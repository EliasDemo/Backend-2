<?php

namespace App\Services\Reportes;

use App\Models\EpSede;
use App\Models\PeriodoAcademico;
use Illuminate\Support\Facades\DB;

class HorasPorPeriodoService
{
    /**
     * Construye el payload { meta, data } para el reporte.
     *
     * @param int $epSedeId
     * @param array<string>|null $periodoCodigos  // ej: ['2026-1','2026-2']
     * @param int|null $ultimos                   // si no hay periodos explícitos
     * @param string $estadoRegistro              // default 'APROBADO'
     * @param bool $soloConHorasEnSeleccion       // true = oculta si solo tiene ANTES
     * @param string $unidad                      // 'h'|'min'
     * @param string $orden                       // 'apellidos'|'codigo'|'total'
     * @param string $dir                         // 'asc'|'desc'
     */
    public function build(
        int $epSedeId,
        ?array $periodoCodigos,
        ?int $ultimos,
        string $estadoRegistro = 'APROBADO',
        bool $soloConHorasEnSeleccion = true,
        string $unidad = 'h',
        string $orden = 'apellidos',
        string $dir = 'asc'
    ): array {
        $ep = EpSede::with('escuelaProfesional')->findOrFail($epSedeId);

        // 1) Selección de periodos
        if ($periodoCodigos && count($periodoCodigos) > 0) {
            $periodos = PeriodoAcademico::query()
                ->whereIn('codigo', $periodoCodigos)
                ->orderBy('anio')
                ->orderBy('ciclo')
                ->get()
                ->values();
        } else {
            $n = $ultimos ?? 5;
            // Tomar los últimos N por (anio,ciclo) descendente, y luego ordenar ascendente para mostrar
            $periodos = PeriodoAcademico::query()
                ->whereIn('estado', ['EN_CURSO', 'CERRADO'])
                ->orderBy('anio', 'desc')
                ->orderBy('ciclo', 'desc')
                ->limit($n)
                ->get()
                ->sortBy([
                    ['anio', 'asc'],
                    ['ciclo', 'asc'],
                ])
                ->values();
        }

        if ($periodos->isEmpty()) {
            return [
                'meta' => [
                    'ep_sede_id'          => $ep->id,
                    'escuela_profesional' => $ep->escuelaProfesional?->nombre,
                    'periodos'            => [],
                    'bucket_antes'        => null,
                    'unidad'              => $unidad,
                ],
                'data' => [],
            ];
        }

        $periodoCodigos = $periodos->pluck('codigo')->values()->all();
        $fechaInicioMin = $periodos->min('fecha_inicio')->toDateString();
        $anioBase       = (string) $periodos->min('anio');
        $bucketAntes    = "ANTES_DE_{$anioBase}";

        // 2) SUM dinámicos por periodo + bucket ANTES
        $selects  = [];
        $bindings = [];

        foreach ($periodoCodigos as $code) {
            $selects[]   = "COALESCE(SUM(CASE WHEN p.codigo = ? THEN rh.minutos ELSE 0 END),0) AS `{$code}`";
            $bindings[]  = $code;
        }
        $selects[]  = "COALESCE(SUM(CASE WHEN rh.fecha < ? THEN rh.minutos ELSE 0 END),0) AS `{$bucketAntes}`";
        $bindings[] = $fechaInicioMin;

        // Para HAVING: suma solo de los periodos seleccionados
        $inMarks        = implode(',', array_fill(0, count($periodoCodigos), '?'));
        $sumSelSql      = "SUM(CASE WHEN p.codigo IN ($inMarks) THEN rh.minutos ELSE 0 END)";
        $bindingsHaving = $periodoCodigos;

        // 3) Query principal
        $rawSelect = implode(",\n                ", $selects);

        $rows = DB::table('expedientes_academicos as ea')
            ->join('users as u', 'u.id', '=', 'ea.user_id')
            ->join('ep_sede as eps', 'eps.id', '=', 'ea.ep_sede_id')
            ->leftJoin('registro_horas as rh', function ($j) use ($epSedeId, $estadoRegistro) {
                $j->on('rh.expediente_id', '=', 'ea.id')
                  ->where('rh.ep_sede_id', '=', $epSedeId)
                  ->where('rh.estado', '=', $estadoRegistro);
            })
            ->leftJoin('periodos_academicos as p', 'p.id', '=', 'rh.periodo_id')
            ->where('ea.ep_sede_id', '=', $epSedeId)
            ->where('ea.estado', '=', 'ACTIVO') // <- EGRESADOS / CESADOS quedan fuera
            ->groupBy('ea.id', 'ea.codigo_estudiante', 'u.last_name', 'u.first_name', 'eps.escuela_profesional_id')
            ->selectRaw("
                ea.id as expediente_id,
                ea.codigo_estudiante as codigo,
                u.last_name,
                u.first_name,
                {$rawSelect}
            ", $bindings)
            ->when($soloConHorasEnSeleccion, function ($q) use ($sumSelSql, $bindingsHaving) {
                $q->havingRaw("$sumSelSql > 0", $bindingsHaving);
            })
            ->get();

        // 4) Mapear a payload con unidades y total
        $escuela      = $ep->escuelaProfesional?->nombre;
        $colsDinamicas = array_merge([$bucketAntes], $periodoCodigos);

        $data = $rows->map(function ($r) use ($escuela, $colsDinamicas, $unidad) {
            $buckets  = [];
            $totalMin = 0;

            foreach ($colsDinamicas as $col) {
                $min = (int) ($r->{$col} ?? 0);
                $totalMin += $min;
                $buckets[$col] = $unidad === 'h' ? round($min / 60, 2) : $min;
            }

            $total = $unidad === 'h' ? round($totalMin / 60, 2) : $totalMin;

            return [
                'ep'                => $escuela,
                'codigo'            => (string) $r->codigo,
                'apellidos_nombres' => trim("{$r->last_name} {$r->first_name}"),
                'buckets'           => $buckets,
                'total'             => $total,
                // auxiliares para ordenamiento server-side:
                '_total_min'        => $totalMin,
                '_last_name'        => $r->last_name,
                '_first_name'       => $r->first_name,
            ];
        })->values();

        // 5) Ordenamiento final (match envuelto)
        $data = (match ($orden) {
            'codigo' => $data->sortBy('codigo', SORT_NATURAL | SORT_FLAG_CASE, $dir === 'desc'),
            'total'  => $data->sortBy('_total_min', SORT_NUMERIC, $dir === 'desc'),
            default  => $data->sortBy([
                ['_last_name', $dir === 'desc' ? 'desc' : 'asc'],
                ['_first_name', $dir === 'desc' ? 'desc' : 'asc'],
            ]),
        })->values()->all();

        return [
            'meta' => [
                'ep_sede_id'          => $ep->id,
                'escuela_profesional' => $escuela,
                'periodos'            => $periodos->map(fn($p) => [
                    'codigo'       => $p->codigo,
                    'anio'         => $p->anio,
                    'ciclo'        => $p->ciclo,
                    'fecha_inicio' => $p->fecha_inicio->toDateString(),
                    'fecha_fin'    => $p->fecha_fin->toDateString(),
                ])->values()->all(),
                'bucket_antes'        => $bucketAntes,
                'unidad'              => $unidad,
            ],
            'data' => $data,
        ];
    }
}
