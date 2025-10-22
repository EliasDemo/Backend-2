<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Sede extends Model
{
    use HasFactory;

    protected $table = 'sedes';

    protected $fillable = [
        'universidad_id',
        'nombre',
        'es_principal',
        'esta_suspendida',
    ];

    protected $casts = [
        'es_principal'     => 'boolean',
        'esta_suspendida'  => 'boolean',
    ];

    /* =====================
     |  Relaciones
     |=====================*/

    public function universidad(): BelongsTo
    {
        return $this->belongsTo(Universidad::class, 'universidad_id');
    }

    /** Relación directa con la tabla intermedia ep_sede */
    public function epSedes(): HasMany
    {
        return $this->hasMany(EpSede::class, 'sede_id');
    }

    /** Eventos polimórficos dirigidos a la sede */
    public function eventos(): MorphMany
    {
        return $this->morphMany(VmEvento::class, 'targetable');
    }

    /**
     * Registros de horas a través de la tabla intermedia ep_sede.
     * RegistroHora(ep_sede_id) ← EpSede(id, sede_id) ← Sede(id)
     */
    public function registrosHoras(): HasManyThrough
    {
        return $this->hasManyThrough(
            RegistroHora::class,  // Modelo destino
            EpSede::class,        // Modelo intermedio
            'sede_id',            // FK en EpSede que referencia a Sede
            'ep_sede_id',         // FK en RegistroHora que referencia a EpSede
            'id',                 // Clave local en Sede
            'id'                  // Clave local en EpSede
        );
    }

    /** Escuelas profesionales vinculadas vía ep_sede (incluye vigencias en pivot) */
    public function escuelas(): BelongsToMany
    {
        return $this->belongsToMany(
            EscuelaProfesional::class,
            'ep_sede',
            'sede_id',
            'escuela_profesional_id'
        )
        ->withPivot('vigente_desde', 'vigente_hasta', 'created_at', 'updated_at')
        ->withTimestamps();
    }

    /* =====================
     |  Scopes útiles
     |=====================*/

    public function scopePrincipales(Builder $query): Builder
    {
        return $query->where('es_principal', true);
    }

    public function scopeSuspendidas(Builder $query): Builder
    {
        return $query->where('esta_suspendida', true);
    }
}
