<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class EscuelaProfesional extends Model
{
    use HasFactory;

    protected $table = 'escuelas_profesionales';

    protected $fillable = [
        'facultad_id',
        'codigo',
        'nombre',
    ];

    /* =====================
     | Relaciones
     |=====================*/

    public function facultad(): BelongsTo
    {
        return $this->belongsTo(Facultad::class, 'facultad_id');
    }

    public function epSedes(): HasMany
    {
        return $this->hasMany(EpSede::class, 'escuela_profesional_id');
    }

    /**
     * Proyectos de la escuela a través de la tabla intermedia ep_sede.
     * VmProyecto(ep_sede_id) ← EpSede(id, escuela_profesional_id) ← EscuelaProfesional(id)
     */
    public function proyectos(): HasManyThrough
    {
        return $this->hasManyThrough(
            VmProyecto::class,         // Modelo destino
            EpSede::class,             // Modelo intermedio
            'escuela_profesional_id',  // FK en EpSede que referencia a EscuelaProfesional
            'ep_sede_id',              // FK en VmProyecto que referencia a EpSede
            'id',                      // Clave local en EscuelaProfesional
            'id'                       // Clave local en EpSede
        );
    }

    /**
     * Sedes vinculadas vía tabla pivot ep_sede (incluye fechas de vigencia en pivot).
     */
    public function sedes(): BelongsToMany
    {
        return $this->belongsToMany(
            Sede::class,               // Modelo relacionado
            'ep_sede',                 // Tabla pivot
            'escuela_profesional_id',  // FK en ep_sede hacia EscuelaProfesional
            'sede_id'                  // FK en ep_sede hacia Sede
        )
        ->withPivot('vigente_desde', 'vigente_hasta', 'created_at', 'updated_at')
        ->withTimestamps();
    }
}
