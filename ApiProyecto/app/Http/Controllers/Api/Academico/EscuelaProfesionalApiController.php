<?php

namespace App\Http\Controllers\Api\Academico;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academico\EscuelaProfesionalStoreRequest;
use App\Http\Requests\Academico\AttachEpSedeRequest;
use App\Http\Resources\Academico\EscuelaProfesionalResource;
use App\Models\EscuelaProfesional;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class EscuelaProfesionalApiController extends Controller
{
    public function index(Request $request)
    {
        $q       = $request->query('q');
        $perPage = min((int) $request->query('per_page', 15), 100);
        $sedeId  = $request->integer('sede_id');

        $cacheKey = "academico:escuelas:q={$q}:sede={$sedeId}:per={$perPage}:p={$request->query('page',1)}";

        $payload = Cache::tags(['academico'])->remember($cacheKey, now()->addMinutes(5), function () use ($q, $perPage, $sedeId) {
            $query = EscuelaProfesional::select(['id', 'codigo', 'nombre', 'facultad_id']);

            if ($q) {
                $query->where(function ($b) use ($q) {
                    $b->where('nombre', 'LIKE', "%{$q}%")
                      ->orWhere('codigo', 'LIKE', "%{$q}%");
                });
            }

            if ($sedeId) {
                // Solo EP vinculadas a la sede y carga de la sede (para exponer pivot) y facultad (opcional)
                $query->whereHas('sedes', fn ($q2) => $q2->where('sedes.id', $sedeId))
                      ->with([
                          'sedes' => fn ($q3) => $q3->where('sedes.id', $sedeId),
                          'facultad',
                      ]);
            }

            return $query->paginate($perPage);
        });

        return EscuelaProfesionalResource::collection($payload)->response();
    }

    public function show(EscuelaProfesional $escuela)
    {
        $escuela->load(['sedes', 'facultad']);
        return new EscuelaProfesionalResource($escuela);
    }

    public function store(EscuelaProfesionalStoreRequest $request)
    {
        $data = $request->validated();

        DB::beginTransaction();
        try {
            $ep = EscuelaProfesional::create($data);
            DB::commit();
            Cache::tags(['academico'])->flush();

            return (new EscuelaProfesionalResource($ep))
                ->response()
                ->setStatusCode(201);
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);
            return response()->json(['message' => 'Error creando escuela profesional'], 500);
        }
    }

    public function update(EscuelaProfesionalStoreRequest $request, EscuelaProfesional $escuela)
    {
        $data = $request->validated();

        DB::beginTransaction();
        try {
            $escuela->update($data);
            DB::commit();
            Cache::tags(['academico'])->flush();

            return new EscuelaProfesionalResource($escuela->refresh());
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);
            return response()->json(['message' => 'Error actualizando'], 500);
        }
    }

    public function destroy(EscuelaProfesional $escuela)
    {
        try {
            $escuela->delete();
            Cache::tags(['academico'])->flush();
            return response()->json(null, 204);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['message' => 'No se pudo eliminar'], 423);
        }
    }

    /**
     * Vincular EP -> Sede (inserta en ep_sede)
     * POST /administrador/academico/escuelas-profesionales/{escuela_profesional}/sedes
     *
     * Se usa $escuela_profesional (ID) explícito para evitar problemas de model binding.
     */
    public function attachSede(AttachEpSedeRequest $request, $escuela_profesional)
    {
        $data = $request->validated();

        // Resolver EP por ID (no dependemos del binding)
        $ep = EscuelaProfesional::find($escuela_profesional);
        if (!$ep || !$ep->getKey()) {
            return response()->json(['message' => 'Escuela profesional no encontrada'], 404);
        }

        // Verificar duplicado vía relación
        $yaExiste = $ep->sedes()->where('sedes.id', $data['sede_id'])->exists();
        if ($yaExiste) {
            return response()->json(['message' => 'Vínculo ya existente'], 409);
        }

        // Insertar en pivot usando la relación
        try {
            DB::beginTransaction();

            $ep->sedes()->attach($data['sede_id'], [
                'vigente_desde' => $data['vigente_desde'] ?? null,
                'vigente_hasta' => $data['vigente_hasta'] ?? null,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);

            DB::commit();
            Cache::tags(['academico'])->flush();

            return response()->json(['ok' => true], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);
            return response()->json(['message' => 'Error vinculando'], 500);
        }
    }

    /**
     * Actualizar vigencias para la relación existente
     * PUT /administrador/academico/escuelas-profesionales/{escuela_profesional}/sedes/{sede}
     */
    public function updateSedeVigencia(Request $request, EscuelaProfesional $ep, $sedeId)
    {
        $data = $request->validate([
            'vigente_desde' => ['nullable', 'date'],
            'vigente_hasta' => ['nullable', 'date', 'after_or_equal:vigente_desde'],
        ]);

        $updated = DB::table('ep_sede')
            ->where('escuela_profesional_id', $ep->id)
            ->where('sede_id', $sedeId)
            ->update(array_merge($data, ['updated_at' => now()]));

        if (!$updated) {
            return response()->json(['message' => 'No existe vinculo'], 404);
        }

        Cache::tags(['academico'])->flush();
        return response()->json(null, 204);
    }

    /**
     * Desvincular EP <-> Sede
     * DELETE /administrador/academico/escuelas-profesionales/{escuela_profesional}/sedes/{sede}
     */
    public function detachSede(EscuelaProfesional $ep, $sedeId)
    {
        $deleted = DB::table('ep_sede')
            ->where('escuela_profesional_id', $ep->id)
            ->where('sede_id', $sedeId)
            ->delete();

        if (!$deleted) {
            return response()->json(['message' => 'No existe vinculo'], 404);
        }

        Cache::tags(['academico'])->flush();
        return response()->json(null, 204);
    }
}
