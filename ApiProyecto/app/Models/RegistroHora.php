<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RegistroHora extends Model
{
    use HasFactory;

    protected $table = 'registro_horas';

    protected $fillable = [
        'expediente_id',
        'ep_sede_id',
        'periodo_id',
        'fecha',
        'minutos',
        'actividad',
        'estado',
        'vinculable_id',
        'vinculable_type',
        'sesion_id',
        'asistencia_id',
    ];

    protected $casts = [
        'fecha'   => 'date',
        'minutos' => 'integer',
    ];

    // Relaciones
    public function expediente(): BelongsTo
    {
        return $this->belongsTo(ExpedienteAcademico::class, 'expediente_id');
    }

    public function sesion(): BelongsTo
    {
        return $this->belongsTo(VmSesion::class, 'sesion_id');
    }

    public function asistencia(): BelongsTo
    {
        return $this->belongsTo(VmAsistencia::class, 'asistencia_id');
    }

    public function vinculable()
    {
        return $this->morphTo();
    }

    // 👇 Esta es la relación que te faltaba y que resuelve el with('periodo')
    public function periodo(): BelongsTo
    {
        return $this->belongsTo(PeriodoAcademico::class, 'periodo_id', 'id');
    }

    // Scopes útiles
    public function scopeDeExpediente($q, int $expedienteId)
    {
        return $q->where('expediente_id', $expedienteId);
    }

    public function scopeDePeriodo($q, int $periodoId)
    {
        return $q->where('periodo_id', $periodoId);
    }
}
