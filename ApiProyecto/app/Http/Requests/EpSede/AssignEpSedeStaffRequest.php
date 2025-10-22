<?php

namespace App\Http\Requests\EpSede;

use Illuminate\Foundation\Http\FormRequest;
use App\Services\Auth\EpScopeService;

class AssignEpSedeStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $epSedeId = (int) $this->route('epSedeId');
        if (!$user || !EpScopeService::userManagesEpSede($user->id, $epSedeId)) return false;

        $role = strtoupper((string) $this->input('role'));
        return match ($role) {
            'COORDINADOR' => $user->can('ep.assign.coordinador'),
            'ENCARGADO'   => $user->can('ep.assign.encargado'),
            default       => false,
        };
    }

    public function rules(): array
    {
        return [
            'user_id'       => ['required','integer','exists:users,id'],
            'role'          => ['required','in:COORDINADOR,ENCARGADO'],
            'vigente_desde' => ['nullable','date'],
            'exclusive'     => ['nullable','boolean'],
            'motivo'        => ['nullable','string','max:255'],
        ];
    }

    public function exclusive(): bool
    {
        return (bool) $this->input('exclusive', config('ep_staff.exclusive_same_ep', true));
    }
}
