<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Вход в админку</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; font-family: -apple-system, "Segoe UI", Roboto, Arial, sans-serif; background: #f7f8fa; color: #12141a; }
        .card { width: 360px; background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 24px; }
        h1 { font-size: 18px; margin: 0 0 18px; }
        label { display: block; font-size: 13px; color: #6b7280; margin-bottom: 6px; }
        input { width: 100%; box-sizing: border-box; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 9px; font: inherit; margin-bottom: 14px; }
        button { width: 100%; padding: 11px; border: 0; border-radius: 9px; background: #2f6bff; color: #fff; font: inherit; cursor: pointer; }
        .errors { background: #fef3f2; border: 1px solid #fda29b; color: #b42318; padding: 10px 12px; border-radius: 10px; font-size: 13px; margin-bottom: 14px; }
        .hint { margin-top: 16px; font-size: 12px; color: #6b7280; line-height: 1.5; }
    </style>
</head>
<body>
<div class="card">
    <h1>CIO Loyalty Admin</h1>

    @if ($errors->any())
        <div class="errors">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('admin.login.store') }}">
        @csrf
        <label for="email">E-mail</label>
        <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus>

        <label for="password">Пароль</label>
        <input id="password" type="password" name="password" required>

        <button type="submit">Войти</button>
    </form>

    <p class="hint">Демо-доступ после <code>php artisan migrate --seed</code>:<br>admin@cio.test / password</p>
</div>
</body>
</html>
