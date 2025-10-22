<?php

namespace App\Http\Controllers\Api\Reportes;

use App\Http\Controllers\Controller;
use App\Http\Resources\Reportes\RegistroHoraResource;
use App\Models\RegistroHora;
use App\Models\PeriodoAcademico;
use App\Models\ExpedienteAcademico;
use App\Services\Auth\EpScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ReporteHorasController extends Controller
{
    /**
     * GET /api/reportes/horas/mias
     * Resumen + historial del usuario autenticado.
     */
    public function miReporte(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user || !$user->can(EpScopeService::PERM_VIEW_EXPEDIENTE)) {
            return response()->json(['ok'=>false,'message'=>'NO_AUTORIZADO'], 403);
        }

        $expedienteId = EpScopeService::expedienteId($user->id);
        if (!$expedienteId) {
            return response()->json(['ok'=>false,'message'=>'EXPEDIENTE_NO_ENCONTRADO'], 404);
        }

        return $this->buildReporte($request, $expedienteId);
    }

    /**
     * GET /api/reportes/horas/expedientes/{expediente}
     * Resumen + historial de un expediente (para encargados/coordinadores).
     */
    public function expedienteReporte(Request $request, int $expediente): JsonResponse
    {
        $user = $request->user();
        $exp = ExpedienteAcademico::find($expediente);
        if (!$user || !$exp) {
            return response()->json(['ok'=>false,'message'=>'NO_AUTORIZADO_O_EXPEDIENTE'], 403);
        }

        // Autorización: gestiona el EP_SEDE del expediente o tiene permisos mayores
        $can =
            EpScopeService::userManagesEpSede($user->id, $exp->ep_sede_id) ||
            $user->can(EpScopeService::PERM_MANAGE_SEDE) ||
            $user->can(EpScopeService::PERM_MANAGE_FACULTAD);

        if (!$can) {
            return response()->json(['ok'=>false,'message'=>'NO_AUTORIZADO'], 403);
        }

        return $this->buildReporte($request, $expediente);
    }

    /**
     * Construye el reporte (resumen + historial) con filtros comunes.
     *
     * Filtros:
     * - periodo_id
     * - desde (YYYY-MM-DD)
     * - hasta (YYYY-MM-DD)
     * - estado (por defecto: APROBADO)
     * - tipo (vinculable_type: vm_proyecto | vm_evento)
     * - vinculable_id (id del proyecto/evento)
     * - q (busca en actividad)
     * - per_page (historial, default 15)
     */
    protected function buildReporte(Request $request, int $expedienteId): JsonResponse
    {
        $q = RegistroHora::query()
            ->where('expediente_id', $expedienteId);

        // Filtros
        if ($request->filled('periodo_id')) {
            $q->where('periodo_id', (int)$request->get('periodo_id'));
        }
        if ($request->filled('desde')) {
            $q->whereDate('fecha', '>=', $request->get('desde'));
        }
        if ($request->filled('hasta')) {
            $q->whereDate('fecha', '<=', $request->get('hasta'));
        }

        // Por defecto solo aprobados; si quieres todos, envía ?estado=*
        $estado = $request->get('estado');
        if ($estado && $estado !== '*') {
            $q->where('estado', $estado);
        } elseif (!$estado) {
            $q->where('estado', 'APROBADO');
        }

        if ($request->filled('tipo')) {
            $q->where('vinculable_type', $request->get('tipo')); // p.ej. 'vm_proyecto'
        }
        if ($request->filled('vinculable_id')) {
            $q->where('vinculable_id', (int)$request->get('vinculable_id'));
        }
        if ($request->filled('q')) {
            $q->where('actividad', 'like', '%'.trim($request->get('q')).'%');
        }

        // ===== Resumen total y desgloses =====
        $base = clone $q;

        // Total minutos
        $totalMin = (int) (clone $base)->sum('minutos');

        // Por período
        $porPeriodoRows = (clone $base)
            ->selectRaw('periodo_id, SUM(minutos) AS minutos')
            ->groupBy('periodo_id')
            ->get();

        $periodos = PeriodoAcademico::whereIn(
            'id', $porPeriodoRows->pluck('periodo_id')->filter()->unique()
        )->get(['id','codigo'])->keyBy('id');

        $porPeriodo = $porPeriodoRows->map(function ($r) use ($periodos) {
            $codigo = optional($periodos->get($r->periodo_id))->codigo;
            return [
                'periodo_id' => $r->periodo_id,
                'codigo'     => $codigo,
                'minutos'    => (int) $r->minutos,
                'horas'      => round($r->minutos / 60, 2),
            ];
        })->values();

        // Por proyecto/evento (vinculable)
        $porVinculoRows = (clone $base)
            ->selectRaw('vinculable_type, vinculable_id, SUM(minutos) AS minutos')
            ->groupBy('vinculable_type', 'vinculable_id')
            ->get();

        $porVinculo = collect();

        // Resolvemos nombres usando el morphMap registrado en AppServiceProvider
        $porVinculoRows->groupBy('vinculable_type')->each(function ($rows, $type) use (&$porVinculo) {
            $class = Relation::getMorphedModel($type) ?? $type; // FQCN
            if (!class_exists($class)) {
                foreach ($rows as $r) {
                    $porVinculo->push([
                        'tipo'    => $type,
                        'id'      => (int) $r->vinculable_id,
                        'titulo'  => null,
                        'minutos' => (int) $r->minutos,
                        'horas'   => round($r->minutos/60, 2),
                    ]);
                }
                return;
            }

            $ids = $rows->pluck('vinculable_id')->unique()->all();
            $models = $class::whereIn('id', $ids)->get()->keyBy('id');

            foreach ($rows as $r) {
                $m = $models->get($r->vinculable_id);
                $titulo = $m->titulo ?? ($m->nombre ?? ($m->codigo ?? null));

                $porVinculo->push([
                    'tipo'    => $type,
                    'id'      => (int) $r->vinculable_id,
                    'titulo'  => $titulo,
                    'minutos' => (int) $r->minutos,
                    'horas'   => round($r->minutos/60, 2),
                ]);
            }
        });

        // ===== Historial paginado =====
        $perPage = max(1, min((int) $request->get('per_page', 15), 100));

        $historial = $q
            ->with([
                'periodo:id,codigo',
                'vinculable' => function (MorphTo $morphTo) {
                    $morphTo->constrain([
                        \App\Models\VmProyecto::class => function ($q) {
                            $q->select('id','codigo','titulo','descripcion','tipo','modalidad','estado','horas_planificadas');
                        },
                        \App\Models\VmEvento::class => function ($q) {
                            $q->select('id','codigo','titulo','estado'); // ajusta si necesitas más
                        },
                    ]);
                },
            ])
            ->orderBy('fecha', 'desc')->orderBy('id', 'desc')
            ->paginate($perPage);

        // Respuesta
        return response()->json([
            'ok'   => true,
            'data' => [
                'resumen' => [
                    'total_minutos' => $totalMin,
                    'total_horas'   => round($totalMin / 60, 2),
                    'por_periodo'   => $porPeriodo,
                    'por_vinculo'   => $porVinculo->values(),
                ],
                'historial' => RegistroHoraResource::collection($historial),
            ],
            'meta' => [
                'current_page' => $historial->currentPage(),
                'per_page'     => $historial->perPage(),
                'total'        => $historial->total(),
                'last_page'    => $historial->lastPage(),
            ],
        ]);
    }
}
