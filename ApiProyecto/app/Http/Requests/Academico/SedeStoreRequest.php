<?php
namespace App\Http\Requests\Academico;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SedeStoreRequest extends FormRequest
{
    public function authorize() { return true; }

    public function rules()
    {
        $id = $this->route('sede')?->id ?? null;
        $uni = $this->input('universidad_id');

        return [
            'universidad_id' => ['required','exists:universidades,id'],
            'nombre' => [
                'required','string','max:255',
                Rule::unique('sedes')->where(fn($q)=> $q->where('universidad_id',$uni))->ignore($id)
            ],
            'es_principal' => ['boolean'],
            'esta_suspendida' => ['boolean'],
        ];
    }
}
