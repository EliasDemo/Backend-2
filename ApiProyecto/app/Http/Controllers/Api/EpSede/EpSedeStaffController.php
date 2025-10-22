<?php

namespace App\Http\Controllers\Api\EpSede;

use App\Http\Controllers\Controller;
use App\Http\Requests\EpSede\AssignEpSedeStaffRequest;
use App\Http\Requests\EpSede\DelegateEpSedeStaffRequest;
use App\Http\Requests\EpSede\ReinstateEpSedeStaffRequest;
use App\Http\Requests\EpSede\UnassignEpSedeStaffRequest;
use App\Services\Auth\EpScopeService;
use App\Services\EpSede\StaffAssignmentService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EpSedeStaffController extends Controller
{
    public function current(Request $request, int $epSedeId, StaffAssignmentService $service)
    {
        $userId = (int) $request->user()->id;
        if (!EpScopeService::userManagesEpSede($userId, $epSedeId)) {
            return response()->json(['message' => 'No autorizado.'], Response::HTTP_FORBIDDEN);
        }
        return response()->json(['ep_sede_id' => $epSedeId, 'staff' => $service->current($epSedeId)]);
    }

    public function assign(AssignEpSedeStaffRequest $request, int $epSedeId, StaffAssignmentService $service)
    {
        $payload = $service->assign(
            epSedeId: $epSedeId,
            role: $request->string('role'),
            newUserId: (int) $request->integer('user_id'),
            vigenteDesde: $request->input('vigente_desde'),
            exclusive: $request->exclusive(),
            actorId: $request->user()->id,
            motivo: $request->input('motivo')
        );
        return response()->json($payload, Response::HTTP_OK);
    }

    public function unassign(UnassignEpSedeStaffRequest $request, int $epSedeId, StaffAssignmentService $service)
    {
        $payload = $service->unassign(
            epSedeId: $epSedeId,
            role: $request->string('role'),
            actorId: $request->user()->id,
            motivo: $request->input('motivo')
        );
        return response()->json($payload, Response::HTTP_OK);
    }

    public function reinstate(ReinstateEpSedeStaffRequest $request, int $epSedeId, StaffAssignmentService $service)
    {
        $payload = $service->reinstate(
            epSedeId: $epSedeId,
            role: $request->string('role'),
            userId: (int) $request->integer('user_id'),
            vigenteDesde: $request->input('vigente_desde'),
            exclusive: true,
            actorId: $request->user()->id,
            motivo: $request->input('motivo')
        );
        return response()->json($payload, Response::HTTP_OK);
    }

    public function delegate(DelegateEpSedeStaffRequest $request, int $epSedeId, StaffAssignmentService $service)
    {
        $payload = $service->delegate(
            epSedeId: $epSedeId,
            role: $request->string('role'),
            userId: (int) $request->integer('user_id'),
            desde: $request->string('desde'),
            hasta: $request->string('hasta'),
            actorId: $request->user()->id,
            motivo: $request->input('motivo')
        );
        return response()->json($payload, Response::HTTP_OK);
    }

    public function history(Request $request, int $epSedeId, StaffAssignmentService $service)
    {
        $userId = (int) $request->user()->id;
        if (!EpScopeService::userManagesEpSede($userId, $epSedeId)) {
            return response()->json(['message' => 'No autorizado.'], Response::HTTP_FORBIDDEN);
        }
        return response()->json(['ep_sede_id' => $epSedeId, 'history' => $service->history($epSedeId)]);
    }
}
