<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\ExpedienteAcademico;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use App\Models\EpSedeStaffHistorial;

class EpAutoCloseInterinos extends Command
{
    protected $signature = 'ep:staff:auto-close-interinatos';
    protected $description = 'Cierra interinatos vencidos (vigente_hasta < hoy) y registra AUTO_END.';

    public function handle(): int
    {
        $today = Carbon::today()->toDateString();
        $rows = ExpedienteAcademico::whereIn('rol',['ENCARGADO','COORDINADOR'])
            ->where('estado','ACTIVO')
            ->whereNotNull('vigente_hasta')
            ->where('vigente_hasta','<',$today)
            ->get();

        foreach ($rows as $exp) {
            DB::transaction(function () use ($exp, $today) {
                $u = User::find($exp->user_id);
                if ($u) {
                    $u->removeRole($exp->rol);
                    if (config('ep_staff.touch_user_status', true)) {
                        $u->status = config('ep_staff.status_on_unassign','view_only');
                        $u->save();
                    }
                }
                $exp->estado = 'SUSPENDIDO';
                $exp->save();

                EpSedeStaffHistorial::create([
                    'ep_sede_id' => $exp->ep_sede_id,
                    'user_id'    => $exp->user_id,
                    'role'       => $exp->rol,
                    'evento'     => 'AUTO_END',
                    'desde'      => $exp->vigente_desde,
                    'hasta'      => $exp->vigente_hasta,
                    'actor_id'   => null,
                    'motivo'     => 'Vencimiento automático de interinato',
                ]);
            });
        }

        $this->info('Interinatos vencidos autocerrados: '.$rows->count());
        return self::SUCCESS;
    }
}
