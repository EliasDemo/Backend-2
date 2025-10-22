<?php
namespace App\Http\Resources\Academico;

use Illuminate\Http\Resources\Json\JsonResource;

class SedeResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'=>$this->id,
            'nombre'=>$this->nombre,
            'es_principal'=>boolval($this->es_principal),
            'esta_suspendida'=>boolval($this->esta_suspendida),
            'universidad_id'=>$this->universidad_id,
            'meta'=>[
                'created_at'=>$this->created_at?->toDateTimeString(),
                'updated_at'=>$this->updated_at?->toDateTimeString(),
            ]
        ];
    }
}
