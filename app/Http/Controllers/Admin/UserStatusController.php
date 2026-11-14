<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Support\AdministrationAccess;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserStatusController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('usuarios.view');

        return view('admin.users.index', ['canManage' => $request->user()->can('roles.manage')]);
    }

    public function data(Request $request)
    {
        Gate::authorize('usuarios.view');
        $actor = $request->user();

        return response()->json([
            'data' => User::with(['roles.permissions', 'permissions'])->orderBy('name')->get()->map(fn ($user) => [
                'id' => $user->id, 'name' => $user->name, 'email' => $user->email,
                'role_id' => $user->roles->count() === 1 ? $user->roles->first()->id : null,
                'role_label' => $user->role_label, 'is_active' => $user->is_active,
                'canEdit' => $actor->can('roles.manage') && AdministrationAccess::canManageUser($actor, $user),
                'canStatus' => $actor->can('usuarios.status') && AdministrationAccess::canManageUser($actor, $user),
                'protected' => $user->isAdministrator() || $user->id === $actor->id,
            ]),
            'roles' => $actor->can('roles.manage') ? Role::where('guard_name', 'web')->with('permissions')->orderBy('name')->get()
                ->filter(fn ($role) => AdministrationAccess::canAssignRole($actor, $role))
                ->map(fn ($role) => ['id' => $role->id, 'name' => $role->display_name])->values() : [],
        ])->header('Cache-Control', 'private, no-store');
    }

    private function employeeData(Request $request, ?User $user = null): array
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => mb_strtolower(trim($request->input('email')))]);
        }

        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['bail', 'required', 'email', 'max:255', function ($attribute, $value, $fail) use ($user) {
                if (User::whereRaw('LOWER(email) = ?', [$value])->when($user, fn ($q) => $q->where('id', '!=', $user->id))->exists()) {
                    $fail('Este correo ya está registrado.');
                }
            }],
            'password' => $user ? ['prohibited'] : ['bail', 'required', 'string', 'min:8', 'max:72', 'confirmed', function ($attribute, $value, $fail) {
                if (strlen($value) > 72 || str_contains($value, "\0")) {
                    $fail('La contraseña debe ocupar como máximo 72 bytes y no contener caracteres nulos.');
                }
            }],
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')->where('guard_name', 'web')],
            'is_active' => ['prohibited'], 'permissions' => ['prohibited'], 'roles' => ['prohibited'],
        ]);
    }

    public function store(Request $request)
    {
        Gate::authorize('usuarios.view');
        Gate::authorize('roles.manage');
        $data = $this->employeeData($request);
        try {
            $user = DB::transaction(function () use ($request, $data) {
                $role = Role::whereKey($data['role_id'])->lockForUpdate()->firstOrFail();
                abort_unless(AdministrationAccess::canAssignRole($request->user(), $role), 403);

                // The model's compatibility hook calls Spatie assignRole() for role_id.
                return User::create(['name' => $data['name'], 'email' => $data['email'],
                    'password' => Hash::make($data['password']), 'role_id' => $role->id, 'is_active' => true]);
            });
        } catch (UniqueConstraintViolationException $error) {
            throw ValidationException::withMessages(['email' => 'Este correo ya está registrado.']);
        }

        return response()->json(['message' => 'Empleado creado y rol asignado.', 'id' => $user->id], 201);
    }

    public function edit(Request $request, User $user)
    {
        Gate::authorize('usuarios.view');
        Gate::authorize('roles.manage');
        $data = $this->employeeData($request, $user);
        try {
            DB::transaction(function () use ($request, $user, $data) {
                $role = Role::whereKey($data['role_id'])->lockForUpdate()->firstOrFail();
                $target = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                if ($target->isAdministrator() || $target->id === $request->user()->id) {
                    throw ValidationException::withMessages(['role_id' => 'Tu cuenta y las cuentas Administrador están protegidas.']);
                }
                abort_unless(AdministrationAccess::canManageUser($request->user(), $target)
                    && AdministrationAccess::canAssignRole($request->user(), $role), 403);
                $target->fill(['name' => $data['name'], 'email' => $data['email'], 'role_id' => $role->id])->save();
                $target->syncRoles([$role]);
            });
        } catch (UniqueConstraintViolationException $error) {
            throw ValidationException::withMessages(['email' => 'Este correo ya está registrado.']);
        }

        return response()->json(['message' => 'Empleado actualizado. El rol ya está vigente.']);
    }

    public function update(Request $request, User $user)
    {
        Gate::authorize('usuarios.status');
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        DB::transaction(function () use ($request, $user, $data) {
            $target = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($target->id === $request->user()->id || $target->isAdministrator()) {
                throw ValidationException::withMessages(['is_active' => 'Tu cuenta y las cuentas Administrador están protegidas.']);
            }
            abort_unless(AdministrationAccess::canManageUser($request->user(), $target), 403);
            $target->update($data);
            if (! $data['is_active']) {
                $target->forceFill(['remember_token' => null])->save();
            }
        });

        return $request->expectsJson() ? response()->json(['message' => 'Estado del usuario actualizado.']) : back()->with('status', 'Estado del usuario actualizado.');
    }
}
