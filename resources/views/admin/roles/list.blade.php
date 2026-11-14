    @foreach($roles as $role)
        <div class="col-12 col-md-6 col-xl-4">
            <div class="card h-100">
                <div class="card-body">
                    <span class="avatar bg-primary-lt mb-3"><x-icon name="users" /></span>
                    <h2 class="card-title">{{ $role->display_name }}</h2>
                    @if($role->slug === 'administrador')
                        <p class="text-secondary">Acceso completo y permanente. Sus permisos administrativos están protegidos.</p>
                    @else
                        <p class="text-secondary"><strong data-permission-count="{{ $role->id }}">{{ $role->permissions_count }}</strong> permisos asignados.</p>
                    @endif
                    <button type="button" class="btn btn-outline-primary" data-role-permissions="{{ $role->id }}" data-permissions-url="{{ route('admin.roles.permissions.show', $role) }}">
                        {{ \App\Support\AdministrationAccess::canConfigureRole(auth()->user(), $role) ? 'Configurar permisos' : 'Ver permisos' }}
                    </button>
                </div>
            </div>
        </div>
    @endforeach
