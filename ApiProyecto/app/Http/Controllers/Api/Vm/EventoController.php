<?php

namespace App\Http\Controllers\Api\Vm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Vm\EventoStoreRequest;
use App\Http\Resources\Vm\VmEventoResource;
use App\Models\PeriodoAcademico;
use App\Models\VmEvento;
use App\Services\Auth\EpScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Carbon\Carbon;

class EventoController extends Controller
{
    /** ✅ POST /api/vm/eventos */
    public function store(EventoStoreRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();
        if (!$user) {
            return response()->json(['ok'=>false,'message'=>'No autenticado.'], 401);
        }

        // 🔐 Permiso según el target donde se registrará el evento
        $type = strtolower($data['target_type']);
        $targetId = (int) $data['target_id'];

        $scopeOk = match ($type) {
            'ep_sede'  => EpScopeService::userManagesEpSede($user->id, $targetId),
            'sede'     => EpScopeService::userManagesSede($user->id,  $targetId),
            'facultad' => EpScopeService::userManagesFacultad($user->id, $targetId),
            default    => false,
        };

        if (!$scopeOk) {
            return response()->json(['ok' => false, 'message' => 'No autorizado para el target solicitado.'], 403);
        }

        // ⛔ Fecha dentro del rango del período
        $periodo = PeriodoAcademico::findOrFail((int) $data['periodo_id']);
        $inicio = Carbon::parse($periodo->fecha_inicio);
        $fin    = Carbon::parse($periodo->fecha_fin);
        $fecha  = Carbon::parse($data['fecha']);

        if (!($inicio->lessThanOrEqualTo($fecha) && $fecha->lessThanOrEqualTo($fin))) {
            return response()->json([
                'ok'      => false,
                'message' => 'La fecha del evento está fuera del rango del período.',
                'rango'   => [$inicio->toDateString(), $fin->toDateString()],
            ], 422);
        }

        $codigo = $data['codigo'] ?: ('EVT-' . now()->format('YmdHis') . '-' . $user->id);

        $evento = VmEvento::create([
            'periodo_id'     => $data['periodo_id'],
            'targetable_type'=> $type,               // ep_sede | sede | facultad
            'targetable_id'  => $targetId,
            'codigo'         => $codigo,
            'titulo'         => $data['titulo'],
            'fecha'          => $data['fecha'],
            'hora_inicio'    => $data['hora_inicio'],
            'hora_fin'       => $data['hora_fin'],
            'requiere_inscripcion' => (bool) ($data['requiere_inscripcion'] ?? false),
            'cupo_maximo'    => $data['cupo_maximo'] ?? null,
            'estado'         => 'PLANIFICADO',
        ]);

        return response()->json(['ok' => true, 'data' => new VmEventoResource($evento)], 201);
    }

    /** ✅ GET /api/vm/eventos  (listar)
     *  🔓 No exige permiso: listado general (puedes filtrar por querystring).
     */
    public function index(Request $request): JsonResponse
    {
        $query = VmEvento::query()->with('periodo')->latest('fecha');

        if ($estado = $request->get('estado')) {
            $query->where('estado', $estado);
        }

        if ($target = $request->get('target_id')) {
            $query->where('targetable_id', $target);
        }

        $eventos = $query->paginate(15);

        return response()->json([
            'ok' => true,
            'data' => VmEventoResource::collection($eventos),
            'meta' => ['total' => $eventos->total()]
        ]);
    }

    /** ✅ GET /api/vm/eventos/{evento}  (detalle)
     *  🔓 No exige permiso: consulta puntual.
     */
    public function show(VmEvento $evento): JsonResponse
    {
        $evento->load('periodo');
        return response()->json([
            'ok'   => true,
            'data' => new VmEventoResource($evento)
        ]);
    }

    /** ✅ PUT /api/vm/eventos/{evento}  (editar si no está en curso) */
    public function update(Request $request, VmEvento $evento): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['ok'=>false,'message'=>'No autenticado.'], 401);
        }

        if ($evento->estado !== 'PLANIFICADO') {
            return response()->json(['ok' => false, 'message' => 'Solo se puede editar un evento planificado.'], 422);
        }

        // 🔐 Permiso según el target actual del evento
        $type = strtolower((string) $evento->targetable_type);
        $targetId = (int) $evento->targetable_id;

        $scopeOk = match ($type) {
            'ep_sede'  => EpScopeService::userManagesEpSede($user->id, $targetId),
            'sede'     => EpScopeService::userManagesSede($user->id,  $targetId),
            'facultad' => EpScopeService::userManagesFacultad($user->id, $targetId),
            default    => false,
        };

        if (!$scopeOk) {
            return response()->json(['ok' => false, 'message' => 'No autorizado para editar este evento.'], 403);
        }

        $data = $request->validate([
            'titulo'      => ['sometimes', 'string', 'max:255'],
            'fecha'       => ['sometimes', 'date'],
            'hora_inicio' => ['sometimes', 'date_format:H:i'],
            'hora_fin'    => ['sometimes', 'date_format:H:i', 'after:hora_inicio'],
            'requiere_inscripcion' => ['sometimes', 'boolean'],
            'cupo_maximo' => ['nullable', 'integer', 'min:1'],
        ]);

        // Si cambian la fecha, validamos que siga dentro del período del evento
        if (array_key_exists('fecha', $data)) {
            $periodo = $evento->periodo()->first(['fecha_inicio','fecha_fin']);
            if ($periodo) {
                $inicio = Carbon::parse($periodo->fecha_inicio);
                $fin    = Carbon::parse($periodo->fecha_fin);
                $fecha  = Carbon::parse($data['fecha']);
                if (!($inicio->lessThanOrEqualTo($fecha) && $fecha->lessThanOrEqualTo($fin))) {
                    return response()->json([
                        'ok'      => false,
                        'message' => 'La nueva fecha queda fuera del rango del período.',
                        'rango'   => [$inicio->toDateString(), $fin->toDateString()],
                    ], 422);
                }
            }
        }

        $evento->fill($data)->save();

        return response()->json(['ok' => true, 'data' => new VmEventoResource($evento)]);
    }
}
