<?php
namespace App\Http\Requests\Academico;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EscuelaProfesionalStoreRequest extends FormRequest
{
    public function authorize() { return true; }

    public function rules()
    {
        $id = $this->route('escuela_profesional')?->id ?? null;
        $fac = $this->input('facultad_id');

        return [
            'facultad_id' => ['required','exists:facultades,id'],
            'codigo' => [
                'required','string','max:50',
                Rule::unique('escuelas_profesionales')->where(fn($q)=> $q->where('facultad_id',$fac))->ignore($id)
            ],
            'nombre' => [
                'required','string','max:255',
                Rule::unique('escuelas_profesionales')->where(fn($q)=> $q->where('facultad_id',$fac))->ignore($id)
            ],
        ];
    }
}
