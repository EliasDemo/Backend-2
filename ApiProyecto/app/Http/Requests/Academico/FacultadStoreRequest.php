<?php
namespace App\Http\Requests\Academico;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FacultadStoreRequest extends FormRequest
{
    public function authorize() { return true; } // ajustar policy si usas

    public function rules()
    {
        $id = $this->route('facultad')?->id ?? null;
        $uni = $this->input('universidad_id');

        return [
            'universidad_id' => ['required','exists:universidades,id'],
            'codigo' => [
                'required','string','max:50',
                Rule::unique('facultades')->where(function ($query) use ($uni) {
                    return $query->where('universidad_id', $uni);
                })->ignore($id)
            ],
            'nombre' => [
                'required','string','max:255',
                Rule::unique('facultades')->where(function ($query) use ($uni) {
                    return $query->where('universidad_id', $uni);
                })->ignore($id)
            ],
        ];
    }
}
