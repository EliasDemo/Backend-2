<?php
namespace App\Http\Resources\Academico;

use Illuminate\Http\Resources\Json\JsonResource;

class FacultadResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'=>$this->id,
            'codigo'=>$this->codigo,
            'nombre'=>$this->nombre,
            'universidad_id'=>$this->universidad_id,
            'escuelas_profesionales' => $this->whenLoaded('escuelasProfesionales', function () {
                return EscuelaProfesionalResource::collection($this->escuelasProfesionales);
            }),
            'meta'=>[
                'created_at'=>$this->created_at?->toDateTimeString(),
                'updated_at'=>$this->updated_at?->toDateTimeString(),
            ]
        ];
    }
}
