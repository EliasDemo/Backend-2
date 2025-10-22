<?php

namespace App\Http\Controllers\Api\Academico;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academico\ExpedienteStoreRequest;
use App\Models\ExpedienteAcademico;
use Illuminate\Http\JsonResponse;

class ExpedienteController extends Controller
{
    /** POST /api/academico/expedientes  */
    public function store(ExpedienteStoreRequest $request): JsonResponse
    {
        // 🔐 Gestión de expedientes por EP-Sede ⇒ requiere permiso
        $user = $request->user();
        if (!$user || !$user->can('ep.manage.ep_sede')) {
            return response()->json(['ok' => false, 'message' => 'NO_AUTORIZADO'], 403);
        }

        $data = $request->validated();
        $data['rol'] = $data['rol'] ?? 'ESTUDIANTE';           // dato de negocio interno, ya no se usa para autorizar
        $data['vigente_desde'] = $data['vigente_desde'] ?? now()->toDateString();

        // Evita duplicado por (user, ep_sede)
        $exists = ExpedienteAcademico::where('user_id', $data['user_id'])
            ->where('ep_sede_id', $data['ep_sede_id'])
            ->first();

        if ($exists) {
            // Actualiza datos si ya existe
            $exists->update([
                'codigo_estudiante'    => $data['codigo_estudiante'] ?? $exists->codigo_estudiante,
                'grupo'                => $data['grupo'] ?? $exists->grupo,
                'correo_institucional' => $data['correo_institucional'] ?? $exists->correo_institucional,
                'rol'                  => $data['rol'] ?? $exists->rol, // no se usa para auth
                'estado'               => 'ACTIVO',
                'vigente_desde'        => $exists->vigente_desde ?? $data['vigente_desde'],
                'vigente_hasta'        => null,
            ]);

            return response()->json(['ok' => true, 'data' => $exists->fresh()], 200);
        }

        $exp = ExpedienteAcademico::create([
            'user_id'              => $data['user_id'],
            'ep_sede_id'           => $data['ep_sede_id'],
            'codigo_estudiante'    => $data['codigo_estudiante'] ?? null,
            'grupo'                => $data['grupo'] ?? null,
            'correo_institucional' => $data['correo_institucional'] ?? null,
            'estado'               => 'ACTIVO',
            'rol'                  => $data['rol'],              // no se usa para auth
            'vigente_desde'        => $data['vigente_desde'],
            'vigente_hasta'        => null,
        ]);

        // ❌ Quitado: asignación de roles Spatie (ya no trabajamos con roles)
        // (Tampoco asignamos permisos aquí: no es necesario para el usuario dueño del expediente)

        return response()->json(['ok' => true, 'data' => $exp], 201);
    }
}
