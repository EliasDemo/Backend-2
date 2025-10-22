<?php

namespace App\Http\Resources\Reportes;

use Illuminate\Http\Resources\Json\JsonResource;

class RegistroHoraResource extends JsonResource
{
    public function toArray($request): array
    {
        // Asegura null si la relación no está cargada (evita MissingValue)
        $v = $this->relationLoaded('vinculable') ? $this->vinculable : null;

        return [
            'id'        => $this->id,
            'fecha'     => optional($this->fecha)->format('Y-m-d'),
            'minutos'   => (int) $this->minutos,
            'horas'     => round($this->minutos / 60, 2),
            'actividad' => $this->actividad,
            'estado'    => $this->estado,

            'periodo' => $this->when(
                $this->relationLoaded('periodo') && $this->periodo,
                fn () => [
                    'id'     => $this->periodo->id,
                    'codigo' => $this->periodo->codigo ?? null,
                ]
            ),

            // Vinculable enriquecido y seguro
            'vinculable' => $this->buildVinculable($v),

            'sesion_id'     => $this->sesion_id,
            'asistencia_id' => $this->asistencia_id,
        ];
    }

    /**
     * Construye el bloque 'vinculable' sin asumir que la relación está cargada.
     *
     * @param  mixed $v  Modelo vinculado o null
     * @return array|null
     */
    protected function buildVinculable($v): ?array
    {
        if (!$v) {
            return null;
        }

        // PROYECTO
        if ($v instanceof \App\Models\VmProyecto) {
            return [
                'tipo'          => 'vm_proyecto',
                'id'            => $v->id,
                'codigo'        => $v->codigo ?? null,
                'titulo'        => $v->titulo ?? null,
                'descripcion'   => $v->descripcion ?? null,
                'tipo_proyecto' => $v->tipo ?? null,      // LIBRE | VINCULADO
                'modalidad'     => $v->modalidad ?? null, // PRESENCIAL | VIRTUAL | MIXTA
                'estado'        => $v->estado ?? null,    // PLANIFICADO | EN_CURSO | CERRADO | CANCELADO
                'horas_planificadas'=> isset($v->horas_planificadas) ? (int)$v->horas_planificadas : null,

            ];
        }

        // EVENTO
        if ($v instanceof \App\Models\VmEvento) {
            return [
                'tipo'    => 'vm_evento',
                'id'      => $v->id,
                'codigo'  => $v->codigo ?? null,
                'titulo'  => $v->titulo ?? null,
                'estado'  => $v->estado ?? null,
            ];
        }

        // Fallback genérico para otros morphs
        return [
            'tipo'   => class_basename($v),
            'id'     => $v->id ?? null,
            'codigo' => $v->codigo ?? null,
            'titulo' => $v->titulo ?? ($v->nombre ?? null),
        ];
    }
}
