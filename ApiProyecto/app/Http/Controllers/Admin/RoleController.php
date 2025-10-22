<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Guard;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\Response;

class RoleController extends Controller
{
    // Listado de roles (permisos + users_count robusto)
    public function index(Request $request)
    {
        $reqGuard = $request->query('guard');
        $guard = $reqGuard && array_key_exists($reqGuard, config('auth.guards')) ? $reqGuard : 'web';

        $modelClass = Guard::getModelForGuard($guard);
        $morphType  = $modelClass ? app($modelClass)->getMorphClass() : null;

        $q = Role::query()
            ->where('roles.guard_name', $guard)
            ->with('permissions:id,name,guard_name')
            ->select('roles.*')
            ->orderBy('roles.name');

        if ($morphType) {
            $pivot  = config('permission.table_names.model_has_roles', 'model_has_roles');
            $teamFk = config('permission.column_names.team_foreign_key');

            $q->selectSub(function ($s) use ($pivot, $morphType, $teamFk) {
                $s->from("$pivot as mhr")
                  ->selectRaw('count(*)')
                  ->whereColumn('mhr.role_id', 'roles.id')
                  ->where('mhr.model_type', $morphType);

                if (config('permission.teams') && $teamFk) {
                    $s->whereNull("mhr.$teamFk");
                }
            }, 'users_count');
        } else {
            $q->selectRaw('0 as users_count');
        }

        return response()->json($q->get());
    }

    // Crear rol
    public function store(Request $request)
    {
        $guard = $request->input('guard_name', 'web');

        $validated = $request->validate([
            'name' => [
                'required','string','max:100',
                Rule::unique((new Role)->getTable())
                    ->where(fn($qq) => $qq->where('guard_name', $guard)),
            ],
            'guard_name' => ['nullable','string','max:50'],
        ]);

        $role = Role::create([
            'name' => $validated['name'],
            'guard_name' => $guard,
        ]);

        return response()->json($role, Response::HTTP_CREATED);
    }

    // Ver rol (permisos + users_count robusto)
    public function show(Role $role)
    {
        $role->load('permissions:id,name,guard_name');

        $modelClass = Guard::getModelForGuard($role->guard_name);
        $morphType  = $modelClass ? app($modelClass)->getMorphClass() : null;

        if ($morphType) {
            $pivot  = config('permission.table_names.model_has_roles', 'model_has_roles');
            $teamFk = config('permission.column_names.team_foreign_key');

            $countQuery = DB::table($pivot)
                ->where('role_id', $role->id)
                ->where('model_type', $morphType);

            if (config('permission.teams') && $teamFk) {
                $countQuery->whereNull($teamFk);
            }

            $role->users_count = (int) $countQuery->count();
        } else {
            $role->users_count = 0;
        }

        return response()->json($role);
    }

    // Renombrar rol
    public function rename(Request $request, Role $role)
    {
        if ($this->isAdministrador($role)) {
            return response()->json([
                'message' => 'No se puede renombrar el rol ADMINISTRADOR.',
            ], Response::HTTP_FORBIDDEN);
        }

        $guard = $request->input('guard_name', $role->guard_name);

        $validated = $request->validate([
            'name' => [
                'required','string','max:100',
                Rule::unique((new Role)->getTable())
                    ->where(fn($qq) => $qq->where('guard_name', $guard)->where('id', '!=', $role->id)),
            ],
            'guard_name' => ['nullable','string','max:50'],
        ]);

        $role->name = $validated['name'];
        if (isset($validated['guard_name'])) {
            $role->guard_name = $validated['guard_name'];
        }
        $role->save();

        return response()->json($role->fresh('permissions'));
    }

    // Eliminar rol (salvo ADMINISTRADOR)
    public function destroy(Role $role)
    {
        if ($this->isAdministrador($role)) {
            return response()->json([
                'message' => 'No se puede eliminar el rol ADMINISTRADOR.',
            ], Response::HTTP_FORBIDDEN);
        }

        $role->delete(); // spatie elimina pivotes role_has_permissions
        return response()->json(['message' => 'Rol eliminado correctamente.']);
    }

    // ---------------------------
    // PERMISOS del rol
    // ---------------------------

    // (A) Asignar (suma) una lista de permisos al rol
    public function assignPermissions(Request $request, Role $role)
    {
        if ($this->isAdministrador($role)) {
            return response()->json([
                'message' => 'No se pueden modificar permisos del rol ADMINISTRADOR.',
            ], Response::HTTP_FORBIDDEN);
        }

        $validated = $request->validate([
            'permissions'   => ['required','array','min:1'],
            'permissions.*' => ['string'],
        ]);

        $names = collect($validated['permissions'])
            ->filter(fn($v) => is_string($v) && $v !== '')
            ->unique()->values();

        $perms = Permission::query()
            ->where('guard_name', $role->guard_name)
            ->whereIn('name', $names)
            ->get();

        if ($perms->count() !== $names->count()) {
            return response()->json([
                'message'     => 'Alguno(s) de los permisos no existe(n) o el guard no coincide.',
                'enviados'    => $names,
                'encontrados' => $perms->pluck('name'),
                'faltantes'   => $names->diff($perms->pluck('name'))->values(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $role->givePermissionTo($perms); // añade sin quitar los existentes
        return response()->json($role->fresh('permissions'));
    }

    // (B) SINCRONIZAR set completo de permisos (reemplaza: útil para quitar uno/varios/todos)
    public function setPermissions(Request $request, Role $role)
    {
        if ($this->isAdministrador($role)) {
            return response()->json([
                'message' => 'No se pueden modificar permisos del rol ADMINISTRADOR.',
            ], Response::HTTP_FORBIDDEN);
        }

        $names = collect($request->input('permissions', []))
            ->filter(fn($v) => is_string($v))   // permitir array vacío para dejar sin permisos
            ->unique()->values();

        // Traer solo permisos válidos del mismo guard
        $perms = Permission::query()
            ->where('guard_name', $role->guard_name)
            ->when($names->isNotEmpty(), fn($q) => $q->whereIn('name', $names))
            ->get();

        if ($names->isNotEmpty() && $perms->count() !== $names->count()) {
            return response()->json([
                'message'     => 'Alguno(s) de los permisos no existe(n) o el guard no coincide.',
                'enviados'    => $names,
                'encontrados' => $perms->pluck('name'),
                'faltantes'   => $names->diff($perms->pluck('name'))->values(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Deja EXACTAMENTE los permisos indicados (puede ser lista vacía)
        $role->syncPermissions($perms);

        return response()->json($role->fresh('permissions'));
    }

    // (C) Revocar una lista puntual (opcional; útil si quieres endpoint específico para quitar)
    public function revokePermissions(Request $request, Role $role)
    {
        if ($this->isAdministrador($role)) {
            return response()->json([
                'message' => 'No se pueden modificar permisos del rol ADMINISTRADOR.',
            ], Response::HTTP_FORBIDDEN);
        }

        $validated = $request->validate([
            'permissions'   => ['required','array','min:1'],
            'permissions.*' => ['string'],
        ]);

        $names = collect($validated['permissions'])
            ->filter(fn($v) => is_string($v) && $v !== '')
            ->unique()->values();

        $perms = Permission::query()
            ->where('guard_name', $role->guard_name)
            ->whereIn('name', $names)
            ->get();

        if ($perms->isEmpty()) {
            return response()->json([
                'message' => 'No se encontraron permisos válidos para revocar en este guard.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        foreach ($perms as $p) {
            $role->revokePermissionTo($p);
        }

        return response()->json($role->fresh('permissions'));
    }

    // Listado de permisos por guard
    public function permissionsIndex(Request $request)
    {
        $guard = $request->query('guard', 'web');

        $permissions = Permission::query()
            ->where('guard_name', $guard)
            ->orderBy('name')
            ->get(['id','name','guard_name']);

        return response()->json($permissions);
    }

    private function isAdministrador(Role $role): bool
    {
        return mb_strtoupper($role->name) === 'ADMINISTRADOR';
    }
}
