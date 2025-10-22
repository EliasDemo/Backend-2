<?php

namespace App\Http\Controllers\Api\Reportes;

use App\Http\Controllers\Controller;
use App\Services\Auth\EpScopeService;
use App\Services\Reportes\HorasPorPeriodoService;
use App\Exports\HorasPorPeriodoExport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Maatwebsite\Excel\Facades\Excel;

class HorasPorPeriodoController extends Controller
{
    public function index(Request $request, int $epSedeId, HorasPorPeriodoService $service)
    {
        $userId = (int) $request->user()->id;

        // Seguridad: solo gestores de ese EP_SEDE
        if (!EpScopeService::userManagesEpSede($userId, $epSedeId)) {
            return response()->json([
                'message' => 'No autorizado para consultar este EP_SEDE.'
            ], Response::HTTP_FORBIDDEN);
        }

        // Validación básica de filtros
        $data = $request->validate([
            'periodos'               => 'array',
            'periodos.*'             => 'regex:/^\d{4}\-(1|2)$/',
            'ultimos'                => 'nullable|integer|min:1|max:12',
            'unidad'                 => 'in:h,min',
            'estado'                 => 'in:PENDIENTE,APROBADO,RECHAZADO,ANULADO',
            'solo_con_horas_periodos'=> 'in:0,1',
            'orden'                  => 'in:apellidos,codigo,total',
            'dir'                    => 'in:asc,desc',
        ]);

        $unidad  = $data['unidad'] ?? 'h';
        $estado  = $data['estado'] ?? 'APROBADO';
        $soloSel = ($data['solo_con_horas_periodos'] ?? '1') === '1';
        $orden   = $data['orden'] ?? 'apellidos';
        $dir     = $data['dir'] ?? 'asc';

        $periodoCodigos = $data['periodos'] ?? null;
        $ultimos        = $periodoCodigos ? null : ($data['ultimos'] ?? 5);

        $payload = $service->build(
            epSedeId: $epSedeId,
            periodoCodigos: $periodoCodigos,
            ultimos: $ultimos,
            estadoRegistro: $estado,
            soloConHorasEnSeleccion: $soloSel,
            unidad: $unidad,
            orden: $orden,
            dir: $dir
        );

        return response()->json($payload);
    }

    public function export(Request $request, int $epSedeId, HorasPorPeriodoService $service)
    {
        $userId = (int) $request->user()->id;

        if (!EpScopeService::userManagesEpSede($userId, $epSedeId)) {
            return response()->json([
                'message' => 'No autorizado para exportar este EP_SEDE.'
            ], Response::HTTP_FORBIDDEN);
        }

        $request->merge(['ultimos' => $request->input('periodos') ? null : ($request->input('ultimos', 5))]);

        $payload = $service->build(
            epSedeId: $epSedeId,
            periodoCodigos: $request->input('periodos'),
            ultimos: $request->input('ultimos'),
            estadoRegistro: $request->input('estado', 'APROBADO'),
            soloConHorasEnSeleccion: (string)$request->input('solo_con_horas_periodos', '1') === '1',
            unidad: $request->input('unidad', 'h'),
            orden: $request->input('orden', 'apellidos'),
            dir: $request->input('dir', 'asc')
        );

        $export = new HorasPorPeriodoExport(
            $payload['meta'],
            $payload['data']
        );

        $fileName = sprintf(
            'reporte_horas_ep_%d_%s.xlsx',
            $epSedeId,
            now()->format('Ymd_His')
        );

        return Excel::download($export, $fileName);
    }
}
