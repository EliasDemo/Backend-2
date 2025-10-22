<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Limpia caché de Spatie
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Usa el guard con el que se autentican tus usuarios (normalmente 'web' con Sanctum)
        $guard = 'web';

        // ─────────────────────────────────────────────────────────────
        // Permisos base (usados por servicios/controladores)
        // ─────────────────────────────────────────────────────────────
        $basePerms = [
            'ep.manage.ep_sede',
            'ep.manage.sede',
            'ep.manage.facultad',
            'ep.view.expediente',
        ];

        // ─────────────────────────────────────────────────────────────
        // Permisos VM (coinciden con los middleware de tus rutas)
        // ─────────────────────────────────────────────────────────────

        $vmPerms = [
            // Proyectos
            'vm.proyecto.niveles.read',
            'vm.proyecto.read',
            'vm.proyecto.create',
            'vm.proyecto.update',
            'vm.proyecto.delete',
            'vm.proyecto.publish',

            // Inscripciones / candidatos (gestión)
            'vm.proyecto.inscripciones.read',
            'vm.proyecto.candidatos.read',

            // Imágenes de proyecto
            'vm.proyecto.imagen.read',
            'vm.proyecto.imagen.create',
            'vm.proyecto.imagen.delete',

            // Procesos
            'vm.proceso.read',
            'vm.proceso.create',
            'vm.proceso.update',
            'vm.proceso.delete',

            // Sesiones
            'vm.sesion.batch.create',
            'vm.sesion.read',
            'vm.sesion.update',
            'vm.sesion.delete',

            // Eventos
            'vm.evento.read',
            'vm.evento.create',
            'vm.evento.update',

            // Imágenes de eventos
            'vm.evento.imagen.read',
            'vm.evento.imagen.create',
            'vm.evento.imagen.delete',

            // Agenda staff
            'vm.agenda.staff.read',

            // Asistencias (staff)
            'vm.asistencia.abrir_qr',
            'vm.asistencia.activar_manual',
            'vm.asistencia.checkin.manual',
            'vm.asistencia.participantes.read',
            'vm.asistencia.justificar.create',
            'vm.asistencia.read',
            'vm.asistencia.reporte.read',
            'vm.asistencia.validar',

        ];

        // Crear (o asegurar) todos los permisos con el guard correcto
        foreach (array_merge($basePerms, $vmPerms) as $perm) {
            Permission::firstOrCreate([
                'name'       => $perm,
                'guard_name' => $guard,
            ]);
        }

        // ─────────────────────────────────────────────────────────────
        // Roles
        // ─────────────────────────────────────────────────────────────
        $admin       = Role::firstOrCreate(['name' => 'ADMINISTRADOR', 'guard_name' => $guard]);
        $coordinador = Role::firstOrCreate(['name' => 'COORDINADOR',   'guard_name' => $guard]);
        $encargado   = Role::firstOrCreate(['name' => 'ENCARGADO',     'guard_name' => $guard]);
        $estudiante  = Role::firstOrCreate(['name' => 'ESTUDIANTE',    'guard_name' => $guard]);

        // Admin: todo
        $admin->syncPermissions(Permission::all());

        // ENCARGADO = gestión completa (base + todos los VM)
        $encargado->syncPermissions(array_merge($basePerms, $vmPerms));

        // COORDINADOR = perfil limitado (lectura/consulta; sin crear/editar/eliminar/publicar)
        $coordinadorPerms = [
            // base
            'ep.manage.ep_sede',

            // proyectos (lectura)
            'vm.proyecto.niveles.read',
            'vm.proyecto.read',
            'vm.proyecto.inscripciones.read',
            'vm.proyecto.candidatos.read',
            'vm.proyecto.imagen.read',

            // procesos/sesiones (lectura)
            'vm.proceso.read',
            'vm.sesion.read',

            // eventos (lectura)
            'vm.evento.read',
            'vm.evento.imagen.read',

            // agenda staff (lectura)
            'vm.agenda.staff.read',

            // asistencias (consulta/reportes/participantes)
            'vm.asistencia.read',
            'vm.asistencia.reporte.read',
            'vm.asistencia.participantes.read',
        ];
        $coordinador->syncPermissions($coordinadorPerms);

        // Estudiante: sin permisos VM (endpoints de alumno no usan 'permission')
        $estudiante->givePermissionTo('ep.view.expediente');    }
}
