<?php

namespace App\Http\Controllers\Api\Vm;

use App\Http\Controllers\Controller;
use App\Models\VmSesion;
use App\Models\VmQrToken;
use App\Models\VmAsistencia;
use App\Models\ExpedienteAcademico;
use App\Models\VmParticipacion;
use App\Models\VmProyecto;
use App\Services\Vm\AsistenciaService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AsistenciasController extends Controller
{
    public function __construct(private AsistenciaService $svc) {}

    // ============================================================
    // TOKENS (STAFF)
    // ============================================================

    /** POST /api/vm/sesiones/{sesion}/qr  (staff) */
    public function generarQr(Request $request, VmSesion $sesion): JsonResponse
    {
        $user = $request->user();
        if (!$user) return response()->json(['ok'=>false,'message'=>'No autenticado.'], 401);
        if (!$user->can('ep.manage.ep_sede')) return response()->json(['ok'=>false,'message'=>'NO_AUTORIZADO'], 403);

        $data = $request->validate([
            'max_usos' => ['nullable','integer','min:1'],
            'lat'      => ['nullable','numeric','between:-90,90'],
            'lng'      => ['nullable','numeric','between:-180,180'],
            'radio_m'  => ['nullable','integer','min:10','max:5000'],
        ]);

        $geo = (isset($data['lat'], $data['lng'], $data['radio_m']))
            ? ['lat'=>$data['lat'], 'lng'=>$data['lng'], 'radio_m'=>$data['radio_m']]
            : null;

        $t = $this->svc->generarToken(
            actor: $user,
            sesion: $sesion,
            tipo: 'QR',
            geo: $geo,
            maxUsos: $data['max_usos'] ?? null,
            creadoPor: $user->id
        );

        return response()->json([
            'ok'   => true,
            'code' => 'QR_OPENED',
            'data' => [
                'token'       => $t->token,
                'usable_from' => $t->usable_from,
                'expires_at'  => $t->expires_at,
                'geo'         => $geo,
            ],
        ], 201);
    }

    /** POST /api/vm/sesiones/{sesion}/activar-manual  (staff) */
    public function activarManual(Request $request, VmSesion $sesion): JsonResponse
    {
        $user = $request->user();
        if (!$user) return response()->json(['ok'=>false,'message'=>'No autenticado.'], 401);
        if (!$user->can('ep.manage.ep_sede')) return response()->json(['ok'=>false,'message'=>'NO_AUTORIZADO'], 403);

        $t = $this->svc->generarTokenManualAlineado(
            actor: $user,
            sesion: $sesion,
            creadoPor: $user->id
        );

        return response()->json([
            'ok'   => true,
            'code' => 'MANUAL_OPENED',
            'data' => [
                'usable_from' => $t->usable_from,
                'expires_at'  => $t->expires_at,
                'token_id'    => $t->id,
            ],
        ], 201);
    }

    // ============================================================
    // CHECK-IN QR (ALUMNO)
    // ============================================================

    /** POST /api/vm/sesiones/{sesion}/check-in/qr */
    public function checkInPorQr(Request $request, VmSesion $sesion): JsonResponse
    {
        $actor = $request->user();
        if (!$actor) return response()->json(['ok'=>false,'message'=>'No autenticado.'], 401);

        $data = $request->validate([
            'token' => ['required','string','size:32'],
            'lat'   => ['nullable','numeric','between:-90,90'],
            'lng'   => ['nullable','numeric','between:-180,180'],
        ]);

        /** @var VmQrToken|null $token */
        $token = VmQrToken::where('sesion_id', $sesion->id)
            ->where('tipo', 'QR')
            ->where('token', $data['token'])
            ->where('activo', true)
            ->first();

        if (!$token) {
            return $this->fail('TOKEN_INVALIDO', 'Token QR no encontrado o inactivo.', 422);
        }

        $this->svc->checkVentana($token);
        $this->svc->checkGeofence($token, $data['lat'] ?? null, $data['lng'] ?? null);

        $epSedeId = $this->svc->epSedeIdDesdeSesion($sesion);
        if (!$epSedeId) {
            return $this->fail('SESION_SIN_EP_SEDE', 'La sesión no está vinculada a una EP_SEDE.', 422);
        }

        // actor == user (no requiere permiso adicional)
        $exp = $this->svc->resolverExpedientePorUser($actor, $actor, $epSedeId);
        if (!$exp) {
            return $this->fail('DIFFERENT_EP_SEDE', 'No perteneces a la EP_SEDE de la sesión.', 422, ['ep_sede_id'=>$epSedeId]);
        }

        [$ptype, $pid] = $this->svc->participableDesdeSesion($sesion);
        if (!$ptype || !$pid) {
            return $this->fail('SESION_SIN_DUENO', 'No se pudo resolver el dueño de la sesión.', 422);
        }

        $inscrito = VmParticipacion::where([
            'participable_type' => $ptype,
            'participable_id'   => $pid,
            'expediente_id'     => $exp->id,
        ])->whereIn('estado', ['INSCRITO','CONFIRMADO'])->exists();

        if (!$inscrito) {
            return $this->fail('NO_INSCRITO', 'El estudiante no está inscrito/confirmado en esta actividad.', 422);
        }

        // upsert con actor explícito
        $a = $this->svc->upsertAsistencia(
            actor: $actor,
            sesion: $sesion,
            exp: $exp,
            metodo: 'QR',
            token: $token,
            meta: [
                'lat'=>$data['lat'] ?? null,
                'lng'=>$data['lng'] ?? null,
                'ip'=>$request->ip(),
                'ua'=>$request->userAgent(),
            ]
        );

        return response()->json([
            'ok'=>true,
            'code'=>'CHECKED_IN',
            'data'=>[
                'asistencia'=>$a,
                'ventana_fin'=>$token->expires_at,
            ]
        ], 201);
    }

    // ============================================================
    // CHECK-IN MANUAL (STAFF) — requiere ventana MANUAL activa
    // ============================================================

    /** POST /api/vm/sesiones/{sesion}/check-in/manual  */
    public function checkInManual(Request $request, VmSesion $sesion): JsonResponse
    {
        $user = $request->user();
        if (!$user) return response()->json(['ok'=>false,'message'=>'No autenticado.'], 401);
        if (!$user->can('ep.manage.ep_sede')) return response()->json(['ok'=>false,'message'=>'NO_AUTORIZADO'], 403);

        $payload = $request->validate([
            'codigo' => ['required','string','max:191'],
        ]);

        $manual = VmQrToken::where('sesion_id', $sesion->id)
            ->where('tipo', 'MANUAL')
            ->vigentesAhora()
            ->latest('id')->first();

        if (!$manual) {
            return $this->fail('VENTANA_NO_ACTIVA', 'Activa primero el llamado manual (o está fuera de ventana).', 422);
        }

        $epSedeId = $this->svc->epSedeIdDesdeSesion($sesion);
        if (!$epSedeId) {
            return $this->fail('SESION_SIN_EP_SEDE', 'La sesión no está vinculada a una EP_SEDE.', 422);
        }

        $exp = ExpedienteAcademico::query()
            ->where('ep_sede_id', $epSedeId)
            ->where('codigo_estudiante', $payload['codigo'])
            ->first();

        if (!$exp) {
            return $this->fail('NO_ENCONTRADO', 'No existe expediente con ese código en esta EP_SEDE.', 422, [
                'codigo' => $payload['codigo'], 'ep_sede_id'=>$epSedeId
            ]);
        }

        [$ptype, $pid] = $this->svc->participableDesdeSesion($sesion);
        if (!$ptype || !$pid) {
            return $this->fail('SESION_SIN_DUENO', 'No se pudo resolver el dueño de la sesión.', 422);
        }

        $inscrito = VmParticipacion::query()
            ->where('participable_type', $ptype)
            ->where('participable_id',   $pid)
            ->where('expediente_id',     $exp->id)
            ->whereIn('estado', ['INSCRITO','CONFIRMADO'])
            ->exists();

        if (!$inscrito) {
            return $this->fail('NO_INSCRITO', 'El estudiante no está inscrito/confirmado en esta actividad.', 422);
        }

        $asistencia = DB::transaction(function () use ($request, $sesion, $exp, $manual) {
            /** @var VmAsistencia $a */
            $a = VmAsistencia::updateOrCreate(
                ['sesion_id' => $sesion->id, 'expediente_id' => $exp->id],
                [
                    'metodo'           => 'MANUAL',
                    'estado'           => 'PENDIENTE',
                    'check_in_at'      => now(),
                    'minutos_validados'=> 0,
                    'qr_token_id'      => $manual->id,
                    'meta'             => [
                        'registrado_por' => $request->user()->id ?? null,
                        'ip'             => $request->ip(),
                        'ua'             => $request->userAgent(),
                        'token_id'       => $manual->id,
                    ],
                ]
            );

            $this->crearOActualizarRegistroHorasLocal($a, $sesion, $exp);

            return $a->fresh();
        });

        return response()->json(['ok'=>true,'code'=>'CHECKED_IN','data'=>['asistencia'=>$asistencia]], 201);
    }

    /** POST /api/vm/sesiones/{sesion}/asistencias/justificar (STAFF) */
    public function checkInFueraDeHora(Request $request, VmSesion $sesion): JsonResponse
    {
        $user = $request->user();
        if (!$user) return response()->json(['ok'=>false,'message'=>'No autenticado.'], 401);
        if (!$user->can('ep.manage.ep_sede')) return response()->json(['ok'=>false,'message'=>'NO_AUTORIZADO'], 403);

        $payload = $request->validate([
            'codigo'        => ['required','string','max:191'],
            'justificacion' => ['required','string','max:2000'],
            'otorgar_horas' => ['nullable','boolean'],
        ]);

        $otorgarHoras = array_key_exists('otorgar_horas', $payload) ? (bool)$payload['otorgar_horas'] : true;

        $epSedeId = $this->svc->epSedeIdDesdeSesion($sesion);
        if (!$epSedeId) {
            return $this->fail('SESION_SIN_EP_SEDE', 'La sesión no está vinculada a una EP_SEDE.', 422);
        }

        $exp = ExpedienteAcademico::query()
            ->where('ep_sede_id', $epSedeId)
            ->where('codigo_estudiante', $payload['codigo'])
            ->first();
        if (!$exp) {
            return $this->fail('NO_ENCONTRADO', 'No existe expediente con ese código en esta EP_SEDE.', 422);
        }

        [$ptype, $pid] = $this->svc->participableDesdeSesion($sesion);
        if (!$ptype || !$pid) {
            return $this->fail('SESION_SIN_DUENO', 'No se pudo resolver el dueño de la sesión.', 422);
        }

        $inscrito = VmParticipacion::query()
            ->where('participable_type', $ptype)
            ->where('participable_id',   $pid)
            ->where('expediente_id',     $exp->id)
            ->whereIn('estado', ['INSCRITO','CONFIRMADO'])
            ->exists();
        if (!$inscrito) {
            return $this->fail('NO_INSCRITO', 'El estudiante no está inscrito/confirmado en esta actividad.', 422);
        }

        $asistencia = DB::transaction(function () use ($request, $sesion, $exp, $payload, $otorgarHoras) {
            /** @var VmAsistencia $a */
            $a = VmAsistencia::updateOrCreate(
                ['sesion_id' => $sesion->id, 'expediente_id' => $exp->id],
                [
                    'metodo'           => 'MANUAL',
                    'estado'           => 'PENDIENTE',
                    'check_in_at'      => now(),
                    'minutos_validados'=> 0,
                    'meta'             => [
                        'registrado_por' => $request->user()->id ?? null,
                        'ip'             => $request->ip(),
                        'ua'             => $request->userAgent(),
                        'fuera_de_hora'  => true,
                        'justificacion'  => $payload['justificacion'],
                    ],
                ]
            );

            if ($otorgarHoras) {
                $this->crearOActualizarRegistroHorasLocal($a, $sesion, $exp, true);
            }

            return $a->fresh();
        });

        return response()->json(['ok'=>true,'code'=>'CHECKED_IN_JUSTIFICADA','data'=>['asistencia'=>$asistencia]], 201);
    }

    // ============================================================
    // PARTICIPANTES / CONSULTA (STAFF)
    // ============================================================

    /** GET /api/vm/sesiones/{sesion}/participantes */
    public function participantes(Request $request, VmSesion $sesion): JsonResponse
    {
        $user = $request->user();
        if (!$user) return response()->json(['ok'=>false,'message'=>'No autenticado.'], 401);
        if (!$user->can('ep.manage.ep_sede')) return response()->json(['ok'=>false,'message'=>'NO_AUTORIZADO'], 403);

        [$ptype, $pid] = $this->svc->participableDesdeSesion($sesion);
        $epSedeId = $this->svc->epSedeIdDesdeSesion($sesion);

        $parts = \App\Models\VmParticipacion::query()
            ->with(['expediente.user:id,first_name,last_name,doc_numero'])
            ->where('participable_type', $ptype ?? VmProyecto::class)
            ->where('participable_id',   $pid ?? 0)
            ->where('rol', 'ALUMNO')
            ->whereIn('estado', ['INSCRITO','CONFIRMADO'])
            ->when($epSedeId, fn($q) => $q->whereHas('expediente', fn($qq) => $qq->where('ep_sede_id', $epSedeId)))
            ->get();

        $expIds = $parts->pluck('expediente_id')->filter()->values();

        $asisPorExp = VmAsistencia::query()
            ->where('sesion_id', $sesion->id)
            ->whereIn('expediente_id', $expIds)
            ->get()
            ->keyBy('expediente_id');

        [$winStart, $winEnd] = $this->svc->timeWindowForSesion($sesion);
        $now = now();

        $rows = $parts->map(function (VmParticipacion $p) use ($asisPorExp, $now, $winStart, $winEnd) {
            $exp = $p->expediente;
            $usr = $exp?->user;
            /** @var VmAsistencia|null $asis */
            $asis = $exp ? $asisPorExp->get($exp->id) : null;

            $estadoCalculado = $asis ? 'PRESENTE' : (($now->lt($winStart) || $now->gt($winEnd)) ? 'FALTA' : '');

            $metodoOut = $asis
                ? (($asis->metodo === 'MANUAL' && data_get($asis, 'meta.fuera_de_hora')) ? 'MANUAL_JUSTIFICADA' : $asis->metodo)
                : null;

            return [
                'participacion_id' => $p->id,
                'expediente_id'    => $exp?->id,
                'codigo'           => $exp?->codigo_estudiante,
                'dni'              => $usr?->doc_numero,
                'nombres'          => $usr?->first_name,
                'apellidos'        => $usr?->last_name,
                'asistencia'       => $asis ? [
                    'id'          => $asis->id,
                    'metodo'      => $metodoOut,
                    'estado'      => $asis->estado,
                    'check_in_at' => $asis->check_in_at,
                    'minutos'     => $asis->minutos_validados,
                ] : null,
                'estado_calculado' => $estadoCalculado,
            ];
        })
        ->sortBy([['apellidos','asc'],['nombres','asc']])
        ->values();

        return response()->json([
            'ok'=>true,
            'data'=>$rows,
            'meta'=>[
                'participantes_total' => $rows->count(),
                'ventana_inicio'      => $winStart->format('Y-m-d H:i:s'),
                'ventana_fin'         => $winEnd->format('Y-m-d H:i:s'),
            ]
        ], 200);
    }

    /** GET /api/vm/sesiones/{sesion}/asistencias (STAFF) */
    public function listarAsistencias(Request $request, VmSesion $sesion): JsonResponse
    {
        $user = $request->user();
        if (!$user) return response()->json(['ok'=>false,'message'=>'No autenticado.'], 401);
        if (!$user->can('ep.manage.ep_sede')) return response()->json(['ok'=>false,'message'=>'NO_AUTORIZADO'], 403);

        $rows = VmAsistencia::query()
            ->with(['expediente.user:id,first_name,last_name,doc_numero'])
            ->where('sesion_id', $sesion->id)
            ->orderByDesc('check_in_at')
            ->get()
            ->map(function (VmAsistencia $a) {
                $metodoOut = ($a->metodo === 'MANUAL' && data_get($a, 'meta.fuera_de_hora'))
                    ? 'MANUAL_JUSTIFICADA'
                    : $a->metodo;

                return [
                    'id'          => $a->id,
                    'metodo'      => $metodoOut,
                    'estado'      => $a->estado,
                    'check_in_at' => $a->check_in_at,
                    'minutos'     => $a->minutos_validados,
                    'codigo'      => $a->expediente->codigo_estudiante ?? null,
                    'dni'         => $a->expediente->user->doc_numero ?? null,
                    'nombres'     => $a->expediente->user->first_name ?? null,
                    'apellidos'   => $a->expediente->user->last_name ?? null,
                ];
            });

        return response()->json(['ok'=>true,'data'=>$rows], 200);
    }

    /** GET /api/vm/sesiones/{sesion}/reporte (STAFF) */
    public function reporte(Request $request, VmSesion $sesion)
    {
        $user = $request->user();
        if (!$user) return response()->json(['ok'=>false,'message'=>'No autenticado.'], 401);
        if (!$user->can('ep.manage.ep_sede')) return response()->json(['ok'=>false,'message'=>'NO_AUTORIZADO'], 403);

        $format = $request->query('format','json');

        $query = VmAsistencia::query()
            ->select([
                'vm_asistencias.id',
                'vm_asistencias.metodo',
                'vm_asistencias.check_in_at',
                'vm_asistencias.estado',
                'vm_asistencias.minutos_validados',
                'vm_asistencias.meta',
                'expedientes_academicos.codigo_estudiante',
                'users.doc_numero as dni',
                'users.first_name',
                'users.last_name',
            ])
            ->join('expedientes_academicos','expedientes_academicos.id','=','vm_asistencias.expediente_id')
            ->join('users','users.id','=','expedientes_academicos.user_id')
            ->where('vm_asistencias.sesion_id', $sesion->id)
            ->orderBy('users.last_name');

        if ($format === 'csv') {
            $headers = [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="reporte_sesion_'.$sesion->id.'.csv"',
            ];
            $callback = function () use ($query) {
                $out = fopen('php://output', 'w');
                fputcsv($out, ['Nombres','Apellidos','Código','DNI','Método','Check-in','Estado','MinutosValidados']);
                $query->chunk(500, function ($chunk) use ($out) {
                    foreach ($chunk as $r) {
                        $metodoOut = ($r->metodo === 'MANUAL' && data_get($r, 'meta.fuera_de_hora'))
                            ? 'MANUAL_JUSTIFICADA'
                            : $r->metodo;

                        fputcsv($out, [
                            $r->first_name,
                            $r->last_name,
                            $r->codigo_estudiante,
                            $r->dni,
                            $metodoOut,
                            $r->check_in_at,
                            $r->estado,
                            $r->minutos_validados,
                        ]);
                    }
                });
                fclose($out);
            };
            return new StreamedResponse($callback, 200, $headers);
        }

        $data = $query->get()->map(function ($r) {
            $r->metodo = ($r->metodo === 'MANUAL' && data_get($r, 'meta.fuera_de_hora'))
                ? 'MANUAL_JUSTIFICADA'
                : $r->metodo;
            unset($r->meta);
            return $r;
        });

        return response()->json(['ok'=>true,'data'=>$data], 200);
    }

    /** POST /api/vm/sesiones/{sesion}/asistencias/validar (STAFF) */
    public function validarAsistencias(Request $request, VmSesion $sesion): JsonResponse
    {
        $user = $request->user();
        if (!$user) return response()->json(['ok'=>false,'message'=>'No autenticado.'], 401);
        if (!$user->can('ep.manage.ep_sede')) return response()->json(['ok'=>false,'message'=>'NO_AUTORIZADO'], 403);

        $minSesion = $this->svc->minutosSesion($sesion);

        $payload = $request->validate([
            'asistencias'          => ['nullable','array'],
            'asistencias.*'        => ['integer','exists:vm_asistencias,id'],
            'crear_registro_horas' => ['nullable','boolean'],
        ]);

        $ids = $payload['asistencias'] ?? [];
        $crearReg = array_key_exists('crear_registro_horas', $payload)
            ? (bool)$payload['crear_registro_horas']
            : true;

        $q = VmAsistencia::where('sesion_id', $sesion->id)
            ->when($ids, fn($qq) => $qq->whereIn('id',$ids));

        $total = 0; $svc = $this->svc;

        DB::transaction(function () use ($q, $minSesion, $crearReg, $svc, $user, &$total) {
            $q->lockForUpdate()->get()->each(function (VmAsistencia $a) use ($minSesion, $crearReg, $svc, $user, &$total) {
                $svc->validarAsistencia($user, $a, $minSesion, $crearReg);
                $total++;
            });
        });

        return response()->json([
            'ok'=>true,
            'code'=>'VALIDATED',
            'data'=>[
                'validadas'=>$total,
                'minutos_por_asistencia'=>$minSesion,
                'registro_horas_creado'=>$crearReg,
            ]
        ], 200);
    }

    // ============================================================
    // Helpers locales
    // ============================================================

    private function crearOActualizarRegistroHorasLocal(
        VmAsistencia $a,
        VmSesion $sesion,
        ExpedienteAcademico $exp,
        bool $justificada = false
    ): void {
        $inicio = \Carbon\Carbon::parse($sesion->fecha)->setTimeFromTimeString($sesion->hora_inicio);
        $fin    = \Carbon\Carbon::parse($sesion->fecha)->setTimeFromTimeString($sesion->hora_fin);
        $min    = max(0, $inicio->diffInMinutes($fin, false));

        $fechaSesion = \Carbon\Carbon::parse($sesion->fecha);
        $periodoId   = DB::table('periodos_academicos')
            ->whereDate('fecha_inicio','<=',$fechaSesion->toDateString())
            ->whereDate('fecha_fin','>=',$fechaSesion->toDateString())
            ->value('id');

        DB::table('registro_horas')->updateOrInsert(
            ['asistencia_id' => $a->id],
            [
                'expediente_id'  => $exp->id,
                'ep_sede_id'     => $exp->ep_sede_id,
                'periodo_id'     => $periodoId,
                'fecha'          => $fechaSesion->toDateString(),
                'minutos'        => $min,
                'actividad'      => ($justificada ? 'Asistencia (justificada) a sesión #' : 'Asistencia a sesión #') . $sesion->id,
                'estado'         => 'APROBADO',
                'vinculable_type'=> $sesion->sessionable_type,
                'vinculable_id'  => $sesion->sessionable_id,
                'sesion_id'      => $sesion->id,
                'updated_at'     => now(),
                'created_at'     => now(),
            ]
        );

        $a->update(['minutos_validados' => $min]);
    }

    private function fail(string $code, string $message, int $status = 422, array $meta = []): JsonResponse
    {
        return response()->json([
            'ok'      => false,
            'code'    => $code,
            'message' => $message,
            'meta'    => (object) $meta,
        ], $status);
    }
}
