<?php
namespace App\Http\Requests\Academico;

use Illuminate\Foundation\Http\FormRequest;

class AttachEpSedeRequest extends FormRequest
{
    public function authorize() { return true; }

    public function rules()
    {
        return [
            'sede_id' => ['required','exists:sedes,id'],
            'vigente_desde' => ['nullable','date'],
            'vigente_hasta' => ['nullable','date','after_or_equal:vigente_desde'],
        ];
    }
}
