<?php
// app/Services/Reportes/ReporteHorasBuilder.php

namespace App\Services\Reportes;

use App\Http\Resources\Reportes\RegistroHoraResource;
use App\Models\PeriodoAcademico;
use App\Models\RegistroHora;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Relations\Relation;

class ReporteHorasBuilder
{
    public function build(Request $request, int $expedienteId): JsonResponse
    {
        $q = RegistroHora::query()->where('expediente_id', $expedienteId);

        // Filtros
        if ($request->filled('periodo_id')) $q->where('periodo_id', (int)$request->get('periodo_id'));
        if ($request->filled('desde'))      $q->whereDate('fecha', '>=', $request->get('desde'));
        if ($request->filled('hasta'))      $q->whereDate('fecha', '<=', $request->get('hasta'));

        // Por defecto solo APROBADO; si quieres todos, ?estado=*
        $estado = $request->get('estado');
        if ($estado && $estado !== '*') $q->where('estado', $estado);
        elseif (!$estado)               $q->where('estado', 'APROBADO');

        if ($request->filled('tipo'))         $q->where('vinculable_type', $request->get('tipo'));
        if ($request->filled('vinculable_id'))$q->where('vinculable_id', (int)$request->get('vinculable_id'));
        if ($request->filled('q'))            $q->where('actividad', 'like', '%'.trim($request->get('q')).'%');

        // ==== Resumen ====
        $base = clone $q;

        $totalMin = (int) (clone $base)->sum('minutos');

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

        $porVinculoRows = (clone $base)
            ->selectRaw('vinculable_type, vinculable_id, SUM(minutos) AS minutos')
            ->groupBy('vinculable_type', 'vinculable_id')
            ->get();

        $porVinculo = collect();
        $porVinculoRows->groupBy('vinculable_type')->each(function ($rows, $type) use (&$porVinculo) {
            $class = Relation::getMorphedModel($type) ?? $type;
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

        // ==== Historial paginado ====
        $perPage = max(1, min((int) $request->get('per_page', 15), 100));
        $historial = $q->with(['vinculable', 'periodo'])
            ->orderBy('fecha', 'desc')->orderBy('id', 'desc')
            ->paginate($perPage);

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
                'per_page'     => $historial->PerPage(),
                'total'        => $historial->total(),
                'last_page'    => $historial->lastPage(),
            ],
        ]);
    }
}
