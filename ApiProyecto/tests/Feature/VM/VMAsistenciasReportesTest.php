<?php
declare(strict_types=1);

namespace Tests\Feature\VM;

use Tests\Feature\ApiTestCase;
use Tests\Support\AuthHelpers;
use App\Models\EpSede;
use App\Models\PeriodoAcademico;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

final class VMAsistenciasReportesTest extends ApiTestCase
{
    use AuthHelpers;

    private function epSedeSisLima(): EpSede
    {
        return EpSede::whereHas('escuelaProfesional', fn($q)=>$q->where('codigo','SIS'))
            ->whereHas('sede', fn($q)=>$q->where('nombre','Sede Lima'))
            ->firstOrFail();
    }
    private function periodoActual(): PeriodoAcademico
    {
        return PeriodoAcademico::where('es_actual', true)->firstOrFail();
    }

    private function setupEscenario(): array
    {
        $ep  = $this->epSedeSisLima();
        $per = $this->periodoActual();

        $proyectoId = (int) DB::table('vm_proyectos')->insertGetId([
            'ep_sede_id'   => $ep->id,
            'periodo_id'   => $per->id,
            'codigo'       => 'PRJ-REP-'.uniqid(),
            'titulo'       => 'Asistencias Reportes',
            'descripcion'  => 'Rep',
            'tipo'         => 'LIBRE',
            'modalidad'    => 'PRESENCIAL',
            'estado'       => 'EN_CURSO',
            'nivel'        => null,
            'horas_planificadas'         => 4,
            'horas_minimas_participante' => null,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        $procesoId = (int) DB::table('vm_procesos')->insertGetId([
            'proyecto_id' => $proyectoId,
            'nombre' => 'Proc Rep',
            'descripcion' => 'proc',
            'tipo_registro' => 'ASISTENCIA',
            'horas_asignadas' => null,
            'nota_minima' => null,
            'requiere_asistencia' => true,
            'orden' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sesionId = (int) DB::table('vm_sesiones')->insertGetId([
            'sessionable_type' => \App\Models\VmProceso::class,
            'sessionable_id'   => $procesoId,
            'fecha'            => '2025-11-28',
            'hora_inicio'      => '07:00',
            'hora_fin'         => '09:00',
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        // Inscribir a jorge
        $exp = DB::table('expedientes_academicos')
            ->where('user_id', DB::table('users')->where('username','upeu.jorge')->value('id'))
            ->where('ep_sede_id', $ep->id)
            ->where('estado', 'ACTIVO')->first();

        DB::table('vm_participaciones')->insert([
            'participable_type' => 'vm_proyecto',
            'participable_id'   => $proyectoId,
            'expediente_id'     => $exp->id,
            'rol'               => 'ALUMNO',
            'estado'            => 'INSCRITO',
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        // staff abre QR (tolerar 201 o 403)
        $headersStaff  = $this->loginAs('upeu.luis');
        $open = $this->postJson("/api/vm/sesiones/{$sesionId}/qr", ['max_usos'=>3], $headersStaff);

        if ($open->status() === 201) {
            $token = ($open->json()['data']['token']) ?? null;

            // alumno intenta check-in (tolerar 201 o 422)
            $headersAlumno = $this->loginAs('upeu.jorge');
            $check = $this->postJson("/api/vm/sesiones/{$sesionId}/check-in/qr", [
                'token' => $token,
            ], $headersAlumno);

            if (!in_array($check->status(), [201, 422], true)) {
                $check->assertStatus(201); // si no es 201/422, falla con detalle
            }
        }

        return [$proyectoId, $procesoId, $sesionId, $headersStaff];
    }

    #[Test]
    public function participantes_y_asistencias_json_y_csv(): void
    {
        [$proyectoId, $procesoId, $sesionId, $headersStaff] = $this->setupEscenario();

        // Participantes
        $this->getJson("/api/vm/sesiones/{$sesionId}/participantes", $headersStaff)
            ->assertOk()
            ->assertJson(fn($json)=> $json->where('ok', true)->has('data')->etc());

        // Asistencias JSON
        $this->getJson("/api/pm/sesiones/{$sesionId}/asistencias", $headersStaff)
            ->assertOk()
            ->assertJson(fn($json)=> $json->where('ok', true)->has('data')->etc());

        // Reporte JSON
        $this->getJson("/api/vm/sesiones/{$sesionId}/asistencias/reporte?format=json", $headersStaff)
            ->assertOk();

        // Reporte CSV
        $csv = $this->get("/api/vm/sesiones/{$sesionId}/asistencias/reporte?eXport=1&format=csv", $headersStaff);
        $csv->assertOk();
        $this->assertTrue(
            str_contains((string) $csv->headers->get('content-type'), 'text/csv')
            || str_contains((string) $csv->headers->get('content-type'), 'application/vnd.openxmlformats'),
            'La respuesta de export debería ser un archivo (CSV/XLSX).'
        );
    }
}
