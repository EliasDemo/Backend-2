<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use App\Models\EpSede;
use App\Models\Facultad;
use App\Models\Sede;
use App\Models\Universidad;
use App\Models\VmEvento;
use App\Models\VmProceso;
use App\Models\VmProyecto;
use Illuminate\Support\ServiceProvider;
use Illuminate\Database\Eloquent\Relations\Relation;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $canonical = [
            'user'         => \App\Models\User::class,
            'vm_proyecto'  => \App\Models\VmProyecto::class,
            'vm_proceso'   => \App\Models\VmProceso::class,
            'vm_evento'    => \App\Models\VmEvento::class,
            'ep_sede'      => \App\Models\EpSede::class,
            'sede'         => \App\Models\Sede::class,
            'facultad'     => \App\Models\Facultad::class,
            'universidad'  => \App\Models\Universidad::class, // 👈 alias canónico
        ];

        $backward = [
            'App\\Models\\User'        => \App\Models\User::class,
            'App\\Models\\VmProyecto'  => \App\Models\VmProyecto::class,
            'App\\Models\\VmProceso'   => \App\Models\VmProceso::class,
            'App\\Models\\VmEvento'    => \App\Models\VmEvento::class,
            'App\\Models\\EpSede'      => \App\Models\EpSede::class,
            'App\\Models\\Sede'        => \App\Models\Sede::class,
            'App\\Models\\Facultad'    => \App\Models\Facultad::class,
            'App\\Models\\Universidad' => \App\Models\Universidad::class, // 👈 FQCN histórico
            'VmProceso'                => \App\Models\VmProceso::class,
            'VmEvento'                 => \App\Models\VmEvento::class,
            'Universidad'              => \App\Models\Universidad::class, // 👈 alias viejo
        ];

        $map = $canonical + $backward;

        // Opción flexible (no rompe si encuentra algo fuera del mapa):
        Relation::morphMap($map);

        // Si prefieres “romper” cuando haya algo fuera del mapa, usa esta en lugar de la anterior,
        // pero SOLO después de normalizar la BD (paso 3):
        // Relation::enforceMorphMap($map);
    }
}
