<?php

namespace App\Http\Controllers\Api\Vm;

use App\Http\Controllers\Controller;
use App\Models\VmSesion;
use App\Services\Auth\EpScopeService;
use App\Services\Vm\AsistenciaService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * SesionAsistenciaController
 * Apertura de ventanas de asistencia (QR / Manual) para sesiones.
 * 🔐 Permiso: el usuario debe gestionar la EP_SEDE de la sesión.
 * Además, AsistenciaService valida internamente `ep.manage.ep_sede`.
 */
class SesionAsistenciaController extends Controller
{
    public function __construct(private AsistenciaService $svc) {}

    /**
     * POST /api/vm/sesiones/{sesion}/qr
     * Abre una ventana QR (30 min por defecto según el service).
     * Body: { max_usos?, lat?, lng?, radio_m? }
     */
    public function abrirVentanaQr(Request $request, VmSesion $sesion): JsonResponse
    {
        $user = $request->user();

        // Pre-chequeo de alcance (EP_SEDE)
        $epSedeId = $this->svc->epSedeIdDesdeSesion($sesion);
        if (!$epSedeId) {
            return response()->json(['ok'=>false, 'message'=>'La sesión no está vinculada a una EP_SEDE.'], 422);
        }
        if (!EpScopeService::userManagesEpSede($user->id, (int)$epSedeId)) {
            return response()->json(['ok'=>false, 'message'=>'No autorizado para esta EP_SEDE.'], 403);
        }

        $data = $request->validate([
            'max_usos' => ['nullable','integer','min:1'],
            'lat'      => ['nullable','numeric','between:-90,90'],
            'lng'      => ['nullable','numeric','between:-180,180'],
            'radio_m'  => ['nullable','integer','min:10','max:5000'],
        ]);

        $geo = (isset($data['lat'], $data['lng'], $data['radio_m']))
            ? ['lat'=>$data['lat'], 'lng'=>$data['lng'], 'radio_m'=>$data['radio_m']]
            : null;

        try {
            $t = $this->svc->generarToken(
                actor:   $user,           // 👈 firma: (User $actor, VmSesion $sesion, ...)
                sesion:  $sesion,
                tipo:    'QR',
                geo:     $geo,
                maxUsos: $data['max_usos'] ?? null,
                creadoPor: $user->id
            );

            return response()->json([
                'ok'   => true,
                'code' => 'QR_OPENED',
                'data' => [
                    'token'       => $t->token,
                    'usable_from' => $t->usable_from,
                    'expires_at'  => $t->expires_at,
                    'geo'         => $geo,
                ],
            ], 201);
        } catch (AuthorizationException $e) {
            return response()->json(['ok'=>false,'message'=>'NO_AUTORIZADO_EP_SEDE'], 403);
        } catch (ValidationException $e) {
            return response()->json(['ok'=>false,'message'=>'VALIDATION_ERROR','errors'=>$e->errors()], 422);
        }
    }

    /**
     * POST /api/vm/sesiones/{sesion}/activar-manual
     * Abre ventana MANUAL alineada a la sesión (inicio-1h a fin+1h en el service).
     */
    public function activarManual(Request $request, VmSesion $sesion): JsonResponse
    {
        $user = $request->user();

        $epSedeId = $this->svc->epSedeIdDesdeSesion($sesion);
        if (!$epSedeId) {
            return response()->json(['ok'=>false, 'message'=>'La sesión no está vinculada a una EP_SEDE.'], 422);
        }
        if (!EpScopeService::userManagesEpSede($user->id, (int)$epSedeId)) {
            return response()->json(['ok'=>false, 'message'=>'No autorizado para esta EP_SEDE.'], 403);
        }

        try {
            $t = $this->svc->generarTokenManualAlineado(
                actor:    $user,
                sesion:   $sesion,
                creadoPor:$user->id
            );

            return response()->json([
                'ok'   => true,
                'code' => 'MANUAL_OPENED',
                'data' => [
                    'usable_from' => $t->usable_from,
                    'expires_at'  => $t->expires_at,
                    'token_id'    => $t->id,
                ],
            ], 201);
        } catch (AuthorizationException $e) {
            return response()->json(['ok'=>false,'message'=>'NO_AUTORIZADO_EP_SEDE'], 403);
        } catch (ValidationException $e) {
            return response()->json(['ok'=>false,'message'=>'VALIDATION_ERROR','errors'=>$e->errors()], 422);
        }
    }
}
