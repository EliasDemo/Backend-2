<?php

namespace App\Http\Resources\Academico;

use Illuminate\Http\Resources\Json\JsonResource;

class EscuelaProfesionalResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'          => $this->id,
            'codigo'      => $this->codigo,
            'nombre'      => $this->nombre,
            'facultad_id' => $this->facultad_id,

            // (Opcional) objeto facultad si fue cargado
            'facultad' => $this->whenLoaded('facultad', function () {
                return [
                    'id'     => $this->facultad->id,
                    'codigo' => $this->facultad->codigo,
                    'nombre' => $this->facultad->nombre,
                ];
            }),

            // sedes con pivot de vigencias si fue cargado
            'sedes' => $this->whenLoaded('sedes', function () {
                return $this->sedes->map(function ($s) {
                    return [
                        'id'     => $s->id,
                        'nombre' => $s->nombre,
                        'pivot'  => [
                            'vigente_desde' => $s->pivot->vigente_desde, // string YYYY-MM-DD o null
                            'vigente_hasta' => $s->pivot->vigente_hasta, // string YYYY-MM-DD o null
                        ],
                    ];
                })->values();
            }),

            'meta' => [
                'created_at' => $this->created_at?->toDateTimeString(),
                'updated_at' => $this->updated_at?->toDateTimeString(),
            ],
        ];
    }
}
