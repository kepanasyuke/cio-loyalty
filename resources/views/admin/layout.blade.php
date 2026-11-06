<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'CIO Loyalty Admin')</title>
    <style>
        :root { --ink: #12141a; --muted: #6b7280; --line: #e5e7eb; --accent: #2f6bff; --bg: #f7f8fa; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: -apple-system, "Segoe UI", Roboto, Arial, sans-serif; color: var(--ink); background: var(--bg); }
        header { background: #fff; border-bottom: 1px solid var(--line); }
        .wrap { max-width: 1080px; margin: 0 auto; padding: 0 20px; }
        .topbar { display: flex; align-items: center; justify-content: space-between; height: 64px; gap: 16px; }
        .brand { font-weight: 700; letter-spacing: .2px; }
        nav { display: flex; gap: 18px; font-size: 14px; }
        nav a { color: var(--muted); text-decoration: none; }
        nav a:hover { color: var(--ink); }
        main { padding: 28px 0 60px; }
        h1 { font-size: 22px; margin: 0 0 18px; }
        h2 { font-size: 16px; margin: 28px 0 10px; }
        .card { background: #fff; border: 1px solid var(--line); border-radius: 12px; padding: 18px; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px; }
        .stat { background: #fff; border: 1px solid var(--line); border-radius: 12px; padding: 16px; }
        .stat span { display: block; color: var(--muted); font-size: 12px; text-transform: uppercase; letter-spacing: .06em; }
        .stat strong { display: block; font-size: 26px; margin-top: 6px; }
        table { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid var(--line); border-radius: 12px; overflow: hidden; }
        th, td { text-align: left; padding: 11px 14px; border-bottom: 1px solid var(--line); font-size: 14px; vertical-align: top; }
        th { background: #fafbfc; color: var(--muted); font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: .05em; }
        tr:last-child td { border-bottom: none; }
        .btn { display: inline-block; padding: 9px 14px; border-radius: 9px; border: 1px solid var(--line); background: #fff; color: var(--ink); font-size: 14px; text-decoration: none; cursor: pointer; }
        .btn-primary { background: var(--accent); border-color: var(--accent); color: #fff; }
        .btn-danger { color: #b42318; }
        label { display: block; font-size: 13px; color: var(--muted); margin-bottom: 6px; }
        input, textarea, select { width: 100%; padding: 9px 11px; border: 1px solid var(--line); border-radius: 9px; font: inherit; background: #fff; }
        .field { margin-bottom: 14px; }
        .muted { color: var(--muted); font-size: 13px; }
        .flash { background: #ecfdf3; border: 1px solid #a6f4c5; color: #05603a; padding: 10px 14px; border-radius: 10px; margin-bottom: 16px; font-size: 14px; }
        .errors { background: #fef3f2; border: 1px solid #fda29b; color: #b42318; padding: 10px 14px; border-radius: 10px; margin-bottom: 16px; font-size: 14px; }
        .errors ul { margin: 0; padding-left: 18px; }
        .row { display: flex; gap: 14px; flex-wrap: wrap; align-items: flex-end; }
        .row > * { flex: 1 1 200px; }
        .badge { display: inline-block; padding: 3px 9px; border-radius: 999px; background: #eef2ff; color: #3538cd; font-size: 12px; }
        .pager { margin-top: 14px; font-size: 13px; color: var(--muted); }
    </style>
</head>
<body>
<header>
    <div class="wrap topbar">
        <div class="brand">CIO Loyalty Admin</div>
        <nav>
            <a href="{{ route('admin.dashboard') }}">Дашборд</a>
            <a href="{{ route('admin.rewards.index') }}">Награды</a>
            <a href="{{ route('admin.customers.index') }}">Клиенты</a>
        </nav>
        <form method="POST" action="{{ route('admin.logout') }}">
            @csrf
            <button class="btn" type="submit">Выйти</button>
        </form>
    </div>
</header>
<main class="wrap">
    @if (session('status'))
        <div class="flash">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="errors">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @yield('content')
</main>
</body>
</html>
