<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class Universidad extends Model
{
    use HasFactory;

    protected $table = 'universidades';

    protected $fillable = [
        'codigo',
        'nombre',
        'tipo_gestion',
        'estado_licenciamiento',
    ];

    /**
     * Fuerza a Eloquent a guardar este modelo en relaciones polimórficas
     * usando el alias "universidad" (debe existir en tu morphMap).
     */
    protected $morphClass = 'universidad';

    // Catálogos para validar/usar en selects
    public const TIPO_GESTION = ['PUBLICO', 'PRIVADO'];
    public const ESTADO_LICENCIAMIENTO = ['LICENCIA_OTORGADA', 'LICENCIA_DENEGADA', 'EN_PROCESO', 'NINGUNO'];

    /* =====================
     |  Relaciones con otras entidades
     |=====================*/
    public function sedes()
    {
        return $this->hasMany(Sede::class, 'universidad_id');
    }

    public function facultades()
    {
        return $this->hasMany(Facultad::class, 'universidad_id');
    }

    /* =====================
     |  Imágenes polimórficas
     |=====================*/
    public function imagenes(): MorphMany
    {
        return $this->morphMany(Imagen::class, 'imageable');
    }

    /** Colección de LOGOS (todas, ordenadas desc) */
    public function logos(): MorphMany
    {
        return $this->imagenes()
            ->where('titulo', 'LOGO')
            ->orderByDesc('id');
    }

    /** Colección de PORTADAS (todas, ordenadas desc) */
    public function portadas(): MorphMany
    {
        return $this->imagenes()
            ->where('titulo', 'PORTADA')
            ->orderByDesc('id');
    }

    /**
     * LOGO principal (último público de la categoría).
     * Si prefieres máxima compatibilidad, puedes usar ofMany con constraints.
     */
    public function logo(): MorphOne
    {
        return $this->morphOne(Imagen::class, 'imageable')
            ->where('titulo', 'LOGO')
            ->where('visibilidad', 'PUBLICA')
            ->latestOfMany();
        // Alternativa:
        // ->ofMany(['id' => 'max'], fn($q) => $q->where('titulo','LOGO')->where('visibilidad','PUBLICA'));
    }

    /**
     * PORTADA principal (última pública de la categoría).
     */
    public function portada(): MorphOne
    {
        return $this->morphOne(Imagen::class, 'imageable')
            ->where('titulo', 'PORTADA')
            ->where('visibilidad', 'PUBLICA')
            ->latestOfMany();
        // Alternativa:
        // ->ofMany(['id' => 'max'], fn($q) => $q->where('titulo','PORTADA')->where('visibilidad','PUBLICA'));
    }

    /* =====================
     |  Scopes útiles
     |=====================*/
    public function scopePublicas($query)
    {
        return $query->where('tipo_gestion', 'PUBLICO');
    }

    public function scopePrivadas($query)
    {
        return $query->where('tipo_gestion', 'PRIVADO');
    }

    public function scopeConLicenciaOtorgada($query)
    {
        return $query->where('estado_licenciamiento', 'LICENCIA_OTORGADA');
    }
}
