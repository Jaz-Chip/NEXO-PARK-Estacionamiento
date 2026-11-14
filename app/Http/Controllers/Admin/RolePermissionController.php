<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Support\AdministrationAccess;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionController extends Controller
{
    public function index()
    {
        Gate::authorize('roles.view');

        return view('admin.roles.index', [
            'roles' => Role::where('guard_name', 'web')->with('permissions')->withCount('permissions')->orderBy('id')->get(),
            'permissions' => Permission::where('guard_name', 'web')->orderBy('id')->get()->groupBy('module'),
        ]);
    }

    public function show(Request $request, Role $role)
    {
        Gate::authorize('roles.view');
        abort_unless($role->guard_name === 'web', 404);

        return response()->json(['role' => $this->roleData($role), 'canConfigure' => AdministrationAccess::canConfigureRole($request->user(), $role)]);
    }

    private function nameRules(?int $ignore = null): array
    {
        return ['bail', 'required', 'string', 'max:100', function ($attribute, $value, $fail) use ($ignore) {
            if (Role::whereRaw('LOWER(name) = ?', [mb_strtolower($value)])->when($ignore, fn ($q) => $q->where('id', '!=', $ignore))->exists()
                || mb_strtolower($value) === 'operador / cajero') {
                $fail('Ya existe un rol con ese nombre.');
            }
        }];
    }

    public function store(Request $request)
    {
        Gate::authorize('roles.manage');
        if (is_string($request->input('name'))) {
            $request->merge(['name' => trim($request->input('name'))]);
        }
        $data = $request->validate([
            'name' => $this->nameRules(), 'permissions' => ['present', 'array'],
            'permissions.*' => ['required', 'string', 'distinct', Rule::exists('permissions', 'name')->where('guard_name', 'web')],
            'guard_name' => ['prohibited'], 'slug' => ['prohibited'],
        ]);
        abort_unless(AdministrationAccess::canGrant($request->user(), $data['permissions']), 403);
        try {
            $role = DB::transaction(function () use ($data) {
                $role = Role::create(['name' => $data['name'], 'slug' => 'custom-'.Str::uuid(), 'guard_name' => 'web']);
                $role->syncPermissions($data['permissions']);
                $role->forceFill(['permissions_initialized_at' => now()])->save();

                return $role;
            });
        } catch (UniqueConstraintViolationException $error) {
            throw ValidationException::withMessages(['name' => 'Ya existe un rol con ese nombre.']);
        }

        return $this->saved($request, [$role->id], 'Rol creado. Los permisos ya están vigentes.', 201);
    }

    public function update(Request $request)
    {
        Gate::authorize('roles.manage');
        if (is_array($request->input('roles'))) {
            $request->merge(['roles' => array_map(function ($row) {
                if (is_array($row) && is_string($row['name'] ?? null)) {
                    $row['name'] = trim($row['name']);
                }

                return $row;
            }, $request->input('roles'))]);
        }
        $data = $request->validate([
            'roles' => ['required', 'array', 'min:1'],
            'roles.*.id' => ['required', 'integer', 'distinct', Rule::exists('roles', 'id')->where('guard_name', 'web')],
            'roles.*.permissions' => ['present', 'array'],
            'roles.*.permissions.*' => ['required', 'string', Rule::exists('permissions', 'name')->where('guard_name', 'web')],
            'roles.*.name' => ['sometimes', 'required', 'string', 'max:100'],
            'roles.*.slug' => ['prohibited'], 'roles.*.guard_name' => ['prohibited'],
        ]);

        try {
            DB::transaction(function () use ($data, $request) {
                $roles = Role::whereIn('id', array_column($data['roles'], 'id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                // Authorize the whole batch before changing any of the actor's own grants.
                foreach ($data['roles'] as $selection) {
                    $role = $roles->get($selection['id']);
                    if ($role && $role->slug !== 'administrador') {
                        abort_unless(AdministrationAccess::canConfigureRole($request->user(), $role)
                            && AdministrationAccess::canGrant($request->user(), $selection['permissions']), 403);
                    }
                }
                foreach ($data['roles'] as $selection) {
                    $role = $roles->get($selection['id']);
                    if (! $role || $role->slug === 'administrador') {
                        throw ValidationException::withMessages(['roles' => 'Los permisos del Administrador son permanentes y no se pueden modificar.']);
                    }

                    if (array_key_exists('name', $selection)) {
                        if (! $role->isCustom()) {
                            throw ValidationException::withMessages(['name' => 'El nombre de los roles del sistema está protegido.']);
                        }
                        validator(['name' => $selection['name']], ['name' => $this->nameRules($role->id)])->validate();
                        $role->name = $selection['name'];
                    }

                    // An explicitly empty array revokes all grants for this role.
                    $role->syncPermissions($selection['permissions']);
                    $role->forceFill(['permissions_initialized_at' => now()])->save();
                }
            });
        } catch (UniqueConstraintViolationException $error) {
            throw ValidationException::withMessages(['name' => 'Ya existe un rol con ese nombre.']);
        }

        return $this->saved($request, array_column($data['roles'], 'id'), 'Permisos guardados. Los cambios ya están vigentes.');
    }

    private function saved(Request $request, array $ids, string $message, int $status = 200)
    {
        // Clear after commit as well, so other sessions see the saved grants.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $request->user()->unsetRelation('roles')->unsetRelation('permissions');

        return response()->json([
            'message' => $message,
            'roles' => Role::whereIn('id', $ids)->get()->map(fn ($role) => $this->roleData($role)),
            'rolesHtml' => Gate::allows('roles.view') ? view('admin.roles.list', ['roles' => Role::where('guard_name', 'web')->with('permissions')->withCount('permissions')->orderBy('id')->get()])->render() : '',
            'canConfigure' => Gate::allows('roles.manage'),
            'canView' => Gate::allows('roles.view'),
            'grantablePermissions' => Permission::where('guard_name', 'web')->pluck('name')->filter(fn ($name) => $request->user()->can($name))->values(),
            'navigation' => view('layouts.partials.navigation')->render(),
        ], $status);
    }

    private function roleData(Role $role): array
    {
        return [
            'id' => $role->id,
            'name' => $role->display_name,
            'custom' => $role->isCustom(),
            'protected' => $role->slug === 'administrador',
            'permissions' => $role->slug === 'administrador'
                ? Permission::where('guard_name', 'web')->pluck('name')
                : $role->permissions()->pluck('name'),
        ];
    }
}
