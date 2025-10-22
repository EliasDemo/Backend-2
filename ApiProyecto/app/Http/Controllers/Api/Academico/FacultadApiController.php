<?php

namespace App\Http\Controllers\Api\Academico;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academico\FacultadStoreRequest;
use App\Http\Resources\Academico\FacultadResource;
use App\Models\Facultad;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class FacultadApiController extends Controller
{
    public function index(Request $request)
    {
        $q = $request->query('q');
        $perPage = min((int) $request->query('per_page', 15), 100);

        $cacheKey = "academico:facultades:q={$q}:per={$perPage}:p={$request->query('page',1)}";
        $payload = Cache::tags(['academico'])->remember($cacheKey, now()->addMinutes(5), function () use ($q, $perPage) {
            $query = Facultad::select(['id','codigo','nombre','universidad_id']);
            if ($q) {
                $query->where(fn($b) => $b->where('nombre','LIKE',"%{$q}%")
                                          ->orWhere('codigo','LIKE',"%{$q}%"));
            }
            return $query->paginate($perPage);
        });

        return FacultadResource::collection($payload)->response();
    }

    public function show(Facultad $facultad, Request $request)
    {
        $facultad->load('escuelasProfesionales');
        return new FacultadResource($facultad);
    }

    public function store(FacultadStoreRequest $request)
    {
        $data = $request->validated();

        DB::beginTransaction();
        try {
            $f = Facultad::create($data);
            DB::commit();
            Cache::tags(['academico'])->flush();
            return (new FacultadResource($f))->response()->setStatusCode(201);
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);
            return response()->json(['message'=>'Error al crear facultad'],500);
        }
    }

    public function update(FacultadStoreRequest $request, Facultad $facultad)
    {
        $data = $request->validated();
        DB::beginTransaction();
        try {
            $facultad->update($data);
            DB::commit();
            Cache::tags(['academico'])->flush();
            return new FacultadResource($facultad->refresh());
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);
            return response()->json(['message'=>'Error al actualizar'],500);
        }
    }

    public function destroy(Facultad $facultad)
    {
        try {
            $facultad->delete();
            Cache::tags(['academico'])->flush();
            return response()->json(null,204);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['message'=>'No se pudo eliminar'],423);
        }
    }
}
