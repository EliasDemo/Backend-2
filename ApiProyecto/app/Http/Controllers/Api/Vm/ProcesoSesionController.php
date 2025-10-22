<?php

namespace App\Http\Controllers\Api\Vm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Vm\SesionBatchRequest;
use App\Http\Resources\Vm\VmProcesoResource;
use App\Http\Resources\Vm\VmSesionResource;
use App\Models\VmProceso;
use App\Models\VmSesion;
use App\Services\Auth\EpScopeService;
use App\Services\Vm\SesionBatchService;
use App\Support\DateList;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProcesoSesionController extends Controller
{
    /** GET /api/vm/procesos/{proceso}/contexto-edicion
     *  Devuelve el proceso y sus sesiones (ordenadas) para la UI de edición.
     */
    public function edit(VmProceso $proceso): JsonResponse
    {
        $user = request()->user();

        $proyecto = $proceso->proyecto()->firstOrFail();
        if (!EpScopeService::userManagesEpSede($user->id, (int) $proyecto->ep_sede_id)) {
            return response()->json(['ok' => false, 'message' => 'No autorizado para la EP_SEDE del proceso.'], 403);
        }

        $proceso->load([
            'proyecto',
            'sesiones' => fn($q) => $q->orderBy('fecha')->orderBy('hora_inicio'),
        ]);

        return response()->json([
            'ok'   => true,
            'data' => [
                'proceso'  => new VmProcesoResource($proceso),
                'sesiones' => VmSesionResource::collection($proceso->sesiones),
            ],
        ], 200);
    }

    /** POST /api/vm/procesos/{proceso}/sesiones/batch
     *  Crea sesiones en lote para un proceso (deja tal cual lo tenías).
     */
// POST /api/vm/procesos/{proceso}/sesiones/batch
    public function storeBatch(VmProceso $proceso, SesionBatchRequest $request): JsonResponse
    {
        $user = $request->user();

        $proyecto = $proceso->proyecto()->with('periodo')->firstOrFail();
        if (!EpScopeService::userManagesEpSede($user->id, (int) $proyecto->ep_sede_id)) {
            return response()->json(['ok' => false, 'message' => 'No autorizado para la EP_SEDE del proceso.'], 403);
        }

        // 🔐 Bloqueo por estado: solo si el proyecto está PLANIFICADO
        if ($proyecto->estado !== 'PLANIFICADO') {
            return response()->json([
                'ok' => false,
                'message' => 'El proyecto no está en PLANIFICADO. No se pueden crear sesiones.',
            ], 409);
        }

        // Fechas dentro del período del proyecto
        $fechas = DateList::fromBatchPayload($request->validated());
        $ini = $proyecto->periodo->fecha_inicio->toDateString();
        $fin = $proyecto->periodo->fecha_fin->toDateString();

        $fuera = $fechas->filter(fn($f) => !($ini <= $f && $f <= $fin))->values();
        if ($fuera->isNotEmpty()) {
            return response()->json([
                'ok'          => false,
                'message'     => 'Hay fechas fuera del período del proyecto.',
                'rango'       => [$ini, $fin],
                'fechas_fuera'=> $fuera,
            ], 422);
        }

        $created = SesionBatchService::createFor($proceso, $request->validated());

        return response()->json(['ok' => true, 'data' => VmSesionResource::collection($created)], 201);
    }


    /** GET /api/vm/sesiones/{sesion}/edit
     *  Devuelve una sesión individual para edición.
     */
    public function editSesion(VmSesion $sesion): JsonResponse
    {
        $user = request()->user();

        $proceso  = VmProceso::findOrFail($sesion->sessionable_id);
        $proyecto = $proceso->proyecto()->firstOrFail();

        if (!EpScopeService::userManagesEpSede($user->id, (int) $proyecto->ep_sede_id)) {
            return response()->json(['ok' => false, 'message' => 'No autorizado para la EP_SEDE del proceso.'], 403);
        }

        return response()->json(['ok' => true, 'data' => new VmSesionResource($sesion)], 200);
    }

    /** PUT /api/vm/sesiones/{sesion}
     *  Actualiza campos de una sesión (solo si el proyecto está PLANIFICADO y la sesión no inició/pasó).
     */
    public function updateSesion(Request $request, VmSesion $sesion): JsonResponse
    {
        $user = $request->user();

        // Contexto para permisos y estado del proyecto
        $proceso  = VmProceso::findOrFail($sesion->sessionable_id);
        $proyecto = $proceso->proyecto()->firstOrFail();

        if (!EpScopeService::userManagesEpSede($user->id, (int) $proyecto->ep_sede_id)) {
            return response()->json(['ok' => false, 'message' => 'No autorizado para la EP_SEDE del proceso.'], 403);
        }

        // 🔐 Solo editable si el proyecto sigue PLANIFICADO
        if ($proyecto->estado !== 'PLANIFICADO') {
            return response()->json([
                'ok' => false,
                'message' => 'El proyecto no está en PLANIFICADO. No se puede editar la sesión.',
            ], 409);
        }

        // Validaciones
        $rules = [
            'fecha'        => ['sometimes','date'],
            'hora_inicio'  => ['sometimes','date_format:H:i'],
            'hora_fin'     => ['sometimes','date_format:H:i'], // sin 'after' fijo por defecto
            'lugar'        => ['sometimes','nullable','string','max:255'],
            'enlace'       => ['sometimes','nullable','string','max:255'],
            'observacion'  => ['sometimes','nullable','string'],
        ];
        if ($request->filled('hora_inicio')) {
            $rules['hora_fin'][] = 'after:hora_inicio';
        }
        $data = $request->validate($rules);

        // Caso: llega solo hora_fin (sin hora_inicio) → validar contra la hora_inicio ya guardada
        if ($request->filled('hora_fin') && !$request->filled('hora_inicio')) {
            $hi = (string) $sesion->hora_inicio;
            if ($hi && $request->hora_fin <= $hi) {
                return response()->json(['ok'=>false,'message'=>'hora_fin debe ser posterior a hora_inicio.'], 422);
            }
        }

        // ✔️ Chequear “editabilidad” con las fechas/hora objetivo (lo que quedaría tras update)
        $targetFecha = $data['fecha'] ?? (string) $sesion->fecha;
        $targetHi    = $data['hora_inicio'] ?? (string) $sesion->hora_inicio;

        $tmp = $sesion->replicate();
        $tmp->fecha = $targetFecha;
        $tmp->hora_inicio = $targetHi;

        if (!$this->sesionEditable($tmp, (string) $proyecto->estado)) {
            return response()->json([
                'ok'      => false,
                'message' => 'No se puede editar: la sesión es pasada o ya inició hoy.',
            ], 409);
        }

        $sesion->update($data);

        return response()->json(['ok'=>true,'data'=>new VmSesionResource($sesion->fresh())], 200);
    }


    /** DELETE /api/vm/sesiones/{sesion}
     *  Elimina una sesión (solo si el proyecto está PLANIFICADO y la sesión no inició/pasó).
     */
    public function destroySesion(VmSesion $sesion): JsonResponse
    {
        $user = request()->user();

        $proceso  = VmProceso::findOrFail($sesion->sessionable_id);
        $proyecto = $proceso->proyecto()->firstOrFail();

        if (!EpScopeService::userManagesEpSede($user->id, (int) $proyecto->ep_sede_id)) {
            return response()->json(['ok' => false, 'message' => 'No autorizado para la EP_SEDE del proceso.'], 403);
        }

        if (!$this->sesionEditable($sesion, (string) $proyecto->estado)) {
            return response()->json([
                'ok'      => false,
                'message' => 'No se puede eliminar: el proyecto no está en PLANIFICADO o la sesión ya inició/pasó.',
            ], 409);
        }

        $sesion->delete();

        return response()->json(null, 204);
    }

    // ───────────────────────── Helper interno ─────────────────────────

    /** Una sesión es editable si el proyecto está PLANIFICADO y la sesión no es pasada ni ya inició hoy. */
    protected function sesionEditable(VmSesion $sesion, string $estadoProyecto): bool
    {
        if ($estadoProyecto !== 'PLANIFICADO') {
            return false;
        }

        $today = now()->toDateString();
        $now   = now()->format('H:i:s');

        $fecha = (string) $sesion->fecha;
        $hi    = (string) $sesion->hora_inicio;

        // No editable si: fecha pasada, o es hoy y ya inició
        if ($fecha < $today) return false;
        if ($fecha === $today && $hi && $hi <= $now) return false;

        return true;
    }
}
