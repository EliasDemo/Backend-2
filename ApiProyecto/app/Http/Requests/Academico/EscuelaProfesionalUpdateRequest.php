<?php

namespace App\Http\Requests\Academico;

use Illuminate\Foundation\Http\FormRequest;

class EscuelaProfesionalUpdateRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'facultad_id' => ['sometimes','integer','exists:facultades,id'],
            'codigo'      => ['sometimes','string','max:255'],
            'nombre'      => ['sometimes','string','max:255'],
        ];
    }

    public function attributes(): array
    {
        return [
            'facultad_id' => 'facultad',
            'codigo'      => 'código',
            'nombre'      => 'nombre',
        ];
    }
}
