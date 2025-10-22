<?php

namespace App\Http\Controllers\Api\Academico;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academico\SedeStoreRequest;
use App\Http\Resources\Academico\EscuelaProfesionalResource;
use App\Http\Resources\Academico\SedeResource;
use App\Models\Sede;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SedeApiController extends Controller
{
    public function index(Request $request)
    {
        $q = $request->query('q');
        $perPage = min((int) $request->query('per_page', 15), 100);

        $cacheKey = "academico:sedes:q={$q}:per={$perPage}:p={$request->query('page',1)}";
        $payload = Cache::tags(['academico'])->remember($cacheKey, now()->addMinutes(5), function () use ($q, $perPage) {
            $query = Sede::select(['id','nombre','es_principal','esta_suspendida','universidad_id']);
            if ($q) $query->where('nombre','LIKE',"%{$q}%");
            return $query->paginate($perPage);
        });

        return SedeResource::collection($payload)->response();
    }

    public function show(Sede $sede)
    {
        return new SedeResource($sede);
    }

    public function store(SedeStoreRequest $request)
    {
        $data = $request->validated();

        DB::beginTransaction();
        try {
            $s = Sede::create($data);
            DB::commit();
            Cache::tags(['academico'])->flush();
            return (new SedeResource($s))->response()->setStatusCode(201);
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);
            return response()->json(['message'=>'Error creando sede'],500);
        }
    }

    public function update(SedeStoreRequest $request, Sede $sede)
    {
        $data = $request->validated();

        DB::beginTransaction();
        try {
            $sede->update($data);
            DB::commit();
            Cache::tags(['academico'])->flush();
            return new SedeResource($sede->refresh());
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);
            return response()->json(['message'=>'Error actualizando sede'],500);
        }
    }

    public function destroy(Sede $sede)
    {
        try {
            $sede->delete();
            Cache::tags(['academico'])->flush();
            return response()->json(null,204);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['message'=>'No se pudo eliminar'],423);
        }
    }

    /**
     * Escuelas profesionales que pertenecen a esta sede (trae pivot de vigencias).
     * GET /administrador/academico/sedes/{sede}/escuelas
     */
    public function escuelas(Sede $sede, Request $request)
    {
        $q       = $request->query('q');
        $perPage = min((int) $request->query('per_page', 15), 200);

        $cacheKey = "academico:sedes:{$sede->id}:escuelas:q={$q}:per={$perPage}:p={$request->query('page',1)}";

        $payload = Cache::tags(['academico'])->remember($cacheKey, now()->addMinutes(5), function () use ($sede, $q, $perPage) {
            // Relación belongsToMany: EP de la sede, con pivot (vigencias)
            $query = $sede->escuelas()
                ->with([
                    // Carga 'sedes' nuevamente pero filtrada a esta sede para exponer la sección 'sedes' del resource
                    'sedes' => fn($q2) => $q2->where('sedes.id', $sede->id),
                    // (Opcional) facultad si quieres devolverla en el payload
                    'facultad',
                ])
                ->select('escuelas_profesionales.*');

            if ($q) {
                $query->where(fn($b) =>
                    $b->where('escuelas_profesionales.nombre','LIKE', "%{$q}%")
                      ->orWhere('escuelas_profesionales.codigo','LIKE', "%{$q}%")
                );
            }

            return $query->paginate($perPage);
        });

        return EscuelaProfesionalResource::collection($payload)->response();
    }
}
