<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Cache;   // 👈

// Usar Redis como store del scheduler (mutex / overlapping / leader election)
Schedule::useCache(config('cache.default')); // ✅ string del store

/*
|--------------------------------------------------------------------------
| Console Routes (Laravel 12)
|--------------------------------------------------------------------------
| Cron del sistema:
| * * * * * php /ruta/a/tu/app/artisan schedule:run >> /dev/null 2>&1
*/

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})
->purpose('Display an inspiring quote')
->hourly();

// Tick
Schedule::command('vm:tick')
    ->everyMinute()
    ->onOneServer()             // 👈 solo un nodo del clúster lo ejecuta
    ->withoutOverlapping(10)    // 👈 evita solaparse si tarda >1 min (TTL 10 min)
    ->environments(['local','production'])
    ->description('Actualiza estados de sesiones/procesos/proyectos/eventos');

// Autocierre de interinatos
Schedule::command('ep:staff:auto-close-interinatos')
    ->dailyAt('00:15')
    ->timezone(config('app.timezone'))
    ->onOneServer()             // 👈 ejecución única en el clúster
    ->withoutOverlapping()
    ->environments(['local','production'])
    ->description('Cierra interinatos vencidos (vigente_hasta < hoy) y registra AUTO_END');
