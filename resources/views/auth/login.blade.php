<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Ingresar · NEXO PARK</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="variant-dark login-screen">
    <main class="login-main">
        <div class="login-box">
            <form class="card" action="{{ route('login.store') }}" method="POST">
                @csrf
                <div class="card-body">
                    <div class="login-eyebrow">ACCESO DEL PERSONAL</div>
                    <h2>Iniciar sesión</h2>
                    <p class="text-secondary">Ingresá con tu cuenta autorizada.</p>

                    @if($errors->any())
                        <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
                    @endif

                    <div class="mb-3">
                        <label class="form-label" for="email">Correo electrónico</label>
                        <input class="form-control" id="email" type="email" name="email" value="{{ old('email') }}" required autocomplete="username" autofocus>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="password">Contraseña</label>
                        <input class="form-control" id="password" type="password" name="password" required autocomplete="current-password">
                    </div>

                    <label class="form-check mb-4">
                        <input type="checkbox" class="form-check-input" name="remember" value="1">
                        <span class="form-check-label">Recordarme</span>
                    </label>

                    <button type="submit" class="btn btn-primary w-100">Ingresar al panel</button>
                </div>
            </form>
            <p class="login-support">Acceso exclusivo para administradores y personal autorizado.</p>
        </div>
    </main>
</body>
</html>