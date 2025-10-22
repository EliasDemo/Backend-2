<?php

namespace App\Http\Controllers\Api\Matricula;

use App\Http\Controllers\Controller;
use App\Models\ExpedienteAcademico;
use App\Models\Matricula;
use App\Models\PeriodoAcademico;
use App\Models\User;
use App\Services\Auth\EpScopeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class MatriculaManualController extends Controller
{
    // ───────────────────────── Helpers comunes ─────────────────────────

    private function requireAuth(Request $request)
    {
        $actor = $request->user();
        if (!$actor) {
            abort(response()->json(['ok'=>false,'message'=>'No autenticado.'], 401));
        }
        // Permiso recomendado (además de los checks de EP_SEDE)
        if (!($actor->can('matricula.manual') || $actor->can('ep.manage.ep_sede'))) {
            abort(response()->json(['ok'=>false,'message'=>'NO_AUTORIZADO'], 403));
        }
        return $actor;
    }

    private function resolverEpSedeIdOrFail($actor, ?int $epSedeId = null): int
    {
        if ($epSedeId) {
            if (!EpScopeService::userManagesEpSede($actor->id, $epSedeId)) {
                abort(response()->json(['ok'=>false,'message'=>'No autorizado para esa EP_SEDE.'], 403));
            }
            return (int) $epSedeId;
        }
        $managed = EpScopeService::epSedesIdsManagedBy($actor->id);
        if (count($managed) === 1) return (int) $managed[0];

        if (count($managed) > 1) {
            abort(response()->json([
                'ok' => false,
                'message' => 'Administras más de una EP_SEDE. Envía ep_sede_id.',
                'choices' => $managed,
            ], 422));
        }
        abort(response()->json(['ok'=>false,'message'=>'No administras ninguna EP_SEDE activa.'], 403));
    }

    private function toIso2Country(?string $val): ?string
    {
        if (!$val) return null;
        $v = strtoupper(trim($val));
        $v = strtr($v, ['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N']);
        $v = preg_replace('/\s+/', '', $v);
        $map = [
            'PERU'=>'PE','MEXICO'=>'MX','COLOMBIA'=>'CO','CHILE'=>'CL','ARGENTINA'=>'AR','ECUADOR'=>'EC','ESPANA'=>'ES','SPAIN'=>'ES',
            'ESTADOSUNIDOS'=>'US','EEUU'=>'US','USA'=>'US','REINOUNIDO'=>'GB','UK'=>'GB','INGLATERRA'=>'GB','BRASIL'=>'BR','BRAZIL'=>'BR',
            'BOLIVIA'=>'BO','VENEZUELA'=>'VE','URUGUAY'=>'UY','PARAGUAY'=>'PY','PANAMA'=>'PA','COSTARICA'=>'CR','HONDURAS'=>'HN',
            'GUATEMALA'=>'GT','NICARAGUA'=>'NI','ELSALVADOR'=>'SV','REPUBLICADOMINICANA'=>'DO','DOMINICANA'=>'DO','PUERTORICO'=>'PR',
            'CANADA'=>'CA','ALEMANIA'=>'DE','FRANCIA'=>'FR','ITALIA'=>'IT','PORTUGAL'=>'PT','CHINA'=>'CN','JAPON'=>'JP','JAPAN'=>'JP'
        ];
        if (preg_match('/^[A-Z]{2}$/', $v)) return $v;
        if (isset($map[$v])) return $map[$v];
        if (preg_match('/^[A-Z]{3}$/', $v)) {
            $iso3 = ['PER'=>'PE','MEX'=>'MX','COL'=>'CO','CHL'=>'CL','ARG'=>'AR','ECU'=>'EC','ESP'=>'ES','USA'=>'US','URY'=>'UY','PRY'=>'PY','BOL'=>'BO','VEN'=>'VE','BRA'=>'BR','CAN'=>'CA','DEU'=>'DE','FRA'=>'FR','ITA'=>'IT','PRT'=>'PT','GBR'=>'GB','CHN'=>'CN','JPN'=>'JP','PAN'=>'PA','CRI'=>'CR','HND'=>'HN','GTM'=>'GT','NIC'=>'NI','SLV'=>'SV','DOM'=>'DO','PRI'=>'PR'];
            return $iso3[$v] ?? null;
        }
        return null;
    }

    private function toCicloInt(?string $c): ?int
    {
        if ($c === null) return null;
        $n = (int) preg_replace('/\D+/', '', $c);
        return $n > 0 ? $n : null;
    }

    private function normalizeModalidad(?string $v): ?string
    {
        if (!$v) return null;
        $x = strtoupper($v);
        $x = strtr($x, ['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U']);
        if (str_starts_with($x,'PRE')) return 'PRESENCIAL';
        if (str_starts_with($x,'SEM') || str_contains($x,'MIX') || str_contains($x,'BLE')) return 'SEMIPRESENCIAL';
        if (str_starts_with($x,'VIR') || str_contains($x,'ON')) return 'VIRTUAL';
        return null;
    }

    private function normalizeContrato(?string $v): ?string
    {
        if (!$v) return null;
        $x = strtoupper($v);
        if (str_contains($x,'REG'))  return 'REGULAR';
        if (str_contains($x,'CONV')) return 'CONVENIO';
        if (str_contains($x,'BECA')) return 'BECA';
        return 'OTRO';
    }

    private function ensurePassword(User $user, ?string $doc, bool $whenEmpty = true): void
    {
        if (!$whenEmpty && !empty($user->password)) return;

        if (empty($user->password)) {
            $rounds = (int) env('BCRYPT_ROUNDS', 10);
            $seed = $doc ?: Str::random(10); // 👈 antes tenías \Str::random(10)
            $user->password = Hash::make((string)$seed, ['rounds'=>$rounds]);
        }
    }


    private function ensureStudentRole(User $user): void
    {
        try {
            if (!$user->hasRole('ESTUDIANTE')) {
                $user->assignRole('ESTUDIANTE');
            }
        } catch (\Throwable $e) {
            logger()->warning('No se pudo asignar rol ESTUDIANTE a user '.$user->id.': '.$e->getMessage());
        }
    }

    private function refreshVigencias(ExpedienteAcademico $exp): void
    {
        $min = Matricula::where('expediente_id',$exp->id)->whereNotNull('fecha_matricula')->min('fecha_matricula');
        $max = Matricula::where('expediente_id',$exp->id)->whereNotNull('fecha_matricula')->max('fecha_matricula');
        $upd = [];
        if ($exp->vigente_desde !== $min) $upd['vigente_desde'] = $min;
        if ($exp->vigente_hasta !== $max) $upd['vigente_hasta'] = $max;
        if ($upd) $exp->fill($upd)->save();
    }

    // ───────────────────────── Endpoints ─────────────────────────

    /**
     * GET /api/matriculas/manual/alumnos/buscar?codigo=...&documento=...&email=...
     * Retorna user + expediente + matriculas del alumno.
     */
    public function buscar(Request $request)
    {
        $actor = $this->requireAuth($request);

        $codigo    = trim((string)$request->query('codigo',''));
        $documento = trim((string)$request->query('documento',''));
        $email     = trim((string)$request->query('email',''));

        $exp = null;
        if ($codigo !== '') {
            $exp = ExpedienteAcademico::with('user')
                ->where('codigo_estudiante',$codigo)->first();
        }
        if (!$exp && $documento !== '') {
            $user = User::where('doc_numero',$documento)->first();
            if ($user) $exp = ExpedienteAcademico::with('user')->where('user_id',$user->id)->first();
        }
        if (!$exp && $email !== '') {
            $user = User::where('email',$email)->first();
            if ($user) $exp = ExpedienteAcademico::with('user')->where('user_id',$user->id)->first();
        }

        if (!$exp) return response()->json(['ok'=>false,'message'=>'No encontrado.'],404);

        if (!EpScopeService::userManagesEpSede($actor->id, (int)$exp->ep_sede_id)) {
            return response()->json(['ok'=>false,'message'=>'NO_AUTORIZADO_EP_SEDE'],403);
        }

        $mats = Matricula::where('expediente_id',$exp->id)
            ->orderByDesc('periodo_id')->get();

        return response()->json([
            'ok'=>true,
            'data'=>[
                'user'       => $exp->user,
                'expediente' => $exp,
                'matriculas' => $mats,
            ],
        ],200);
    }

    /**
     * POST /api/matriculas/manual/registrar
     * body: {
     *   ep_sede_id?: int,  // opcional si actor gestiona solo 1
     *   codigo_estudiante?: string, grupo?, correo_institucional?, ciclo?
     *   // user base:
     *   first_name?, last_name?, estudiante?(full), documento?, email?, celular?, pais?, religion?, fecha_nacimiento?
     *   // si quieres "estado" explícito del expediente, enviar estado (ACTIVO|SUSPENDIDO|EGRESADO|CESADO)
     * }
     */
    public function registrarOActualizar(Request $request)
    {
        $actor = $this->requireAuth($request);

        $v = Validator::make($request->all(), [
            'ep_sede_id'           => ['nullable','integer','exists:ep_sede,id'],
            'codigo_estudiante'    => ['nullable','string','max:100'],
            'grupo'                => ['nullable','string','max:100'],
            'correo_institucional' => ['nullable','email','max:255'],
            'ciclo'                => ['nullable','string','max:20'],
            'estado'               => ['nullable','in:ACTIVO,SUSPENDIDO,EGRESADO,CESADO'],

            // user
            'first_name'           => ['nullable','string','max:255'],
            'last_name'            => ['nullable','string','max:255'],
            'estudiante'           => ['nullable','string','max:500'],
            'documento'            => ['nullable','string','max:32'],
            'email'                => ['nullable','email','max:255'],
            'celular'              => ['nullable','string','max:32'],
            'pais'                 => ['nullable','string','max:100'],
            'religion'             => ['nullable','string','max:100'],
            'fecha_nacimiento'     => ['nullable','date'],
        ]);

        if ($v->fails()) return response()->json(['ok'=>false,'errors'=>$v->errors()],422);

        $epSedeId = $this->resolverEpSedeIdOrFail($actor, $request->input('ep_sede_id'));

        $exp = DB::transaction(function () use ($request, $epSedeId) {

            // 1) localizar por codigo_estudiante si llega, sino por documento/email
            $codigo    = $request->string('codigo_estudiante')->trim();
            $documento = $request->string('documento')->trim();
            $email     = $request->string('email')->trim();

            $exp = null; $user = null;

            if ($codigo !== '') {
                $exp = ExpedienteAcademico::where('codigo_estudiante',$codigo)->first();
                if ($exp) $user = $exp->user;
            }

            if (!$exp && $documento !== '') {
                $user = User::where('doc_numero',$documento)->first();
                if ($user) $exp = ExpedienteAcademico::where('user_id',$user->id)->first();
            }

            if (!$exp && !$user && $email !== '') {
                $user = User::where('email',$email)->first();
                if ($user) $exp = ExpedienteAcademico::where('user_id',$user->id)->first();
            }

            // 2) crear/actualizar user
            if (!$user) $user = new User();

            // nombres (si mandan "estudiante" completo, lo partimos)
            $first = $request->string('first_name')->trim();
            $last  = $request->string('last_name')->trim();
            if ($request->filled('estudiante') && (!$first || !$last)) {
                $split = $this->splitName((string) $request->input('estudiante'));
                $first = $first ?: $split['first_name'];
                $last  = $last  ?: $split['last_name'];
            }
            $user->first_name = $first ?: ($user->first_name ?? 'N/A');
            $user->last_name  = $last  ?: ($user->last_name  ?? 'N/A');

            if ($documento !== '') $user->doc_numero = $documento;
            if ($email !== '')     $user->email      = $email;
            if ($request->filled('celular')) $user->celular = (string) $request->input('celular');

            if ($request->filled('pais')) $user->pais = $this->toIso2Country($request->input('pais'));
            if ($request->filled('religion')) $user->religion = (string) $request->input('religion');
            if ($request->filled('fecha_nacimiento')) $user->fecha_nacimiento = (string) $request->input('fecha_nacimiento');

            // status: si no tiene matrícula aún, lo dejamos como esté; no lo forzamos aquí
            $this->ensurePassword($user, $documento ?: null, true);
            $user->save();
            $this->ensureStudentRole($user);

            // 3) expediente
            if (!$exp) $exp = new ExpedienteAcademico();

            $exp->user_id = $user->id;
            $exp->ep_sede_id = $epSedeId;

            if ($codigo !== '') $exp->codigo_estudiante = $codigo;
            if ($request->filled('grupo')) $exp->grupo   = (string)$request->input('grupo');
            if ($request->filled('correo_institucional')) $exp->correo_institucional = (string)$request->input('correo_institucional');
            if ($request->filled('ciclo')) $exp->ciclo = (string)$request->input('ciclo');
            $exp->rol = 'ESTUDIANTE';

            if ($request->filled('estado')) {
                $exp->estado = (string)$request->input('estado');
            } elseif (!$exp->exists) {
                $exp->estado = 'SUSPENDIDO';
            }

            $exp->save();

            // vigencias recalculan cuando matriculamos; aquí no es imprescindible
            return $exp->load('user');
        });

        return response()->json(['ok'=>true, 'data'=>$exp], 200);
    }

    /**
     * POST /api/matriculas/manual/matricular
     * body: {
     *   codigo_estudiante?: string  // o expediente_id
     *   expediente_id?: int
     *   periodo_id: int             // recomendado actual
     *   ciclo?: string|int
     *   grupo?: string
     *   modalidad_estudio?: string  // PRESENCIAL|SEMIPRESENCIAL|VIRTUAL (normalizable)
     *   modo_contrato?: string      // REGULAR|CONVENIO|BECA|OTRO (normalizable)
     *   fecha_matricula?: date|null // null = anular
     * }
     */
    public function matricular(Request $request)
    {
        $actor = $this->requireAuth($request);

        $v = Validator::make($request->all(), [
            'codigo_estudiante' => ['nullable','string','max:100'],
            'expediente_id'     => ['nullable','integer','exists:expedientes_academicos,id'],
            'periodo_id'        => ['required','integer','exists:periodos_academicos,id'],
            'ciclo'             => ['nullable','string','max:20'],
            'grupo'             => ['nullable','string','max:100'],
            'modalidad_estudio' => ['nullable','string','max:40'],
            'modo_contrato'     => ['nullable','string','max:40'],
            'fecha_matricula'   => ['nullable','date'],
        ]);
        if ($v->fails()) return response()->json(['ok'=>false,'errors'=>$v->errors()],422);

        // período (puedes forzar EN_CURSO si así quieres)
        $periodo = PeriodoAcademico::find((int)$request->input('periodo_id'));
        if (!$periodo) return response()->json(['ok'=>false,'message'=>'PERIODO_NO_ENCONTRADO'],404);

        // localizar expediente
        $exp = null;
        if ($request->filled('expediente_id')) {
            $exp = ExpedienteAcademico::with('user')->find((int)$request->input('expediente_id'));
        } elseif ($request->filled('codigo_estudiante')) {
            $exp = ExpedienteAcademico::with('user')
                ->where('codigo_estudiante',(string)$request->input('codigo_estudiante'))
                ->first();
        }

        if (!$exp) return response()->json(['ok'=>false,'message'=>'EXPEDIENTE_NO_ENCONTRADO'],404);
        if (!EpScopeService::userManagesEpSede($actor->id, (int)$exp->ep_sede_id)) {
            return response()->json(['ok'=>false,'message'=>'NO_AUTORIZADO_EP_SEDE'],403);
        }

        $mat = DB::transaction(function () use ($request, $periodo, $exp) {

            $mat = Matricula::query()
                ->where('expediente_id',$exp->id)
                ->where('periodo_id',$periodo->id)
                ->first();

            $fecha = $request->input('fecha_matricula'); // null = anular

            $payload = [
                'ciclo'             => $this->toCicloInt($request->input('ciclo')),
                'grupo'             => $request->input('grupo') ?: null,
                'modalidad_estudio' => $this->normalizeModalidad($request->input('modalidad_estudio')),
                'modo_contrato'     => $this->normalizeContrato($request->input('modo_contrato')),
                'fecha_matricula'   => $fecha ? date('Y-m-d', strtotime($fecha)) : null,
            ];

            if ($fecha) {
                if ($mat) {
                    $mat->update($payload);
                    $statusMsg = 'matrícula actualizada';
                } else {
                    $mat = new Matricula();
                    $mat->expediente_id = $exp->id;
                    $mat->periodo_id    = $periodo->id;
                    foreach ($payload as $k=>$v) $mat->{$k} = $v;
                    $mat->save();
                    $statusMsg = 'matrícula creada';
                }
                // expediente ACTIVO
                if ($exp->estado !== 'ACTIVO') {
                    $exp->estado = 'ACTIVO';
                    if ($request->filled('ciclo')) $exp->ciclo = (string)$request->input('ciclo');
                    if ($request->filled('grupo')) $exp->grupo = (string)$request->input('grupo');
                    $exp->save();
                }
            } else {
                // anular (fecha null)
                if ($mat) {
                    $mat->update($payload); // fecha_matricula => null
                    $statusMsg = 'matrícula anulada';
                } else {
                    $statusMsg = 'sin matrícula (no creada)';
                }
                // opcional: bajar a SUSPENDIDO si no tiene otras matrículas activas
                $tieneOtraActiva = Matricula::where('expediente_id',$exp->id)
                    ->whereNotNull('fecha_matricula')
                    ->where('id','!=', optional($mat)->id ?? 0)
                    ->exists();
                if (!$tieneOtraActiva && $exp->estado === 'ACTIVO') {
                    $exp->estado = 'SUSPENDIDO';
                    $exp->save();
                }
            }

            // vigencias
            $this->refreshVigencias($exp);

            return [$mat, $statusMsg];
        });

        [$matricula, $msg] = $mat;

        return response()->json([
            'ok'=>true,
            'message'=>$msg,
            'data'=>[
                'expediente_id' => $exp->id,
                'periodo_id'    => $periodo->id,
                'matricula'     => $matricula,
                'expediente'    => $exp->fresh(), // estado/ciclo/grupo actualizados
            ],
        ],200);
    }

    /**
     * PATCH /api/matriculas/manual/expedientes/{expediente}/estado
     * body: { estado: 'ACTIVO'|'SUSPENDIDO'|'EGRESADO'|'CESADO' }
     */
    public function cambiarEstadoExpediente(Request $request, ExpedienteAcademico $expediente)
    {
        $actor = $this->requireAuth($request);

        if (!EpScopeService::userManagesEpSede($actor->id, (int)$expediente->ep_sede_id)) {
            return response()->json(['ok'=>false,'message'=>'NO_AUTORIZADO_EP_SEDE'],403);
        }

        $v = Validator::make($request->all(), [
            'estado' => ['required','in:ACTIVO,SUSPENDIDO,EGRESADO,CESADO'],
        ]);
        if ($v->fails()) return response()->json(['ok'=>false,'errors'=>$v->errors()],422);

        $expediente->estado = (string)$request->input('estado');
        $expediente->save();

        return response()->json(['ok'=>true,'data'=>$expediente], 200);
    }

    // ---- util: separar nombres si mandan "estudiante" completo ----
    private function splitName(?string $full): array
    {
        $full = trim((string)$full);
        if ($full === '') return ['first_name' => 'N/A', 'last_name' => 'N/A'];

        if (str_contains($full, ',')) {
            [$last, $first] = array_map('trim', explode(',', $full, 2));
            return [
                'first_name' => $first !== '' ? $first : 'N/A',
                'last_name'  => $last  !== '' ? $last  : 'N/A',
            ];
        }

        $parts = array_values(array_filter(explode(' ', $full), fn($p) => $p !== ''));
        if (count($parts) === 1) return ['first_name' => $parts[0], 'last_name' => 'N/A'];
        if (count($parts) === 2) return ['first_name' => $parts[1], 'last_name' => 'N/A'];

        $last  = $parts[count($parts)-2].' '.$parts[count($parts)-1];
        $first = implode(' ', array_slice($parts, 0, -2));
        return ['first_name' => $first, 'last_name' => $last];
    }
}
