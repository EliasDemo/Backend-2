<?php

namespace App\Http\Requests\EpSede;

use Illuminate\Foundation\Http\FormRequest;
use App\Services\Auth\EpScopeService;

class UnassignEpSedeStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $epSedeId = (int) $this->route('epSedeId');
        if (!$user || !EpScopeService::userManagesEpSede($user->id, $epSedeId)) return false;

        $role = strtoupper((string) $this->input('role'));
        return match ($role) {
            'COORDINADOR' => $user->can('ep.unassign.coordinador'),
            'ENCARGADO'   => $user->can('ep.unassign.encargado'),
            default       => false,
        };
    }

    public function rules(): array
    {
        return [
            'role'   => ['required','in:COORDINADOR,ENCARGADO'],
            'motivo' => ['nullable','string','max:255'],
        ];
    }
}
