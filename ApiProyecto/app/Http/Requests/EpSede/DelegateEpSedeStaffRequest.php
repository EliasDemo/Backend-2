<?php

namespace App\Http\Requests\EpSede;

use Illuminate\Foundation\Http\FormRequest;
use App\Services\Auth\EpScopeService;

class DelegateEpSedeStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $epSedeId = (int) $this->route('epSedeId');
        return $user
            && EpScopeService::userManagesEpSede($user->id, $epSedeId)
            && $user->can('ep.assign.encargado'); // interino para ENCARGADO
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required','integer','exists:users,id'],
            'role'    => ['required','in:ENCARGADO'],
            'desde'   => ['required','date'],
            'hasta'   => ['required','date','after_or_equal:desde'],
            'motivo'  => ['nullable','string','max:255'],
        ];
    }
}
