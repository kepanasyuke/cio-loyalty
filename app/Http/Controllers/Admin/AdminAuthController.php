<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Аутентификация администратора и менеджера в веб-панели.
 *
 * Сессионная аутентификация (guard 'web'), не токенная:
 * используется Auth::attempt() с email+password, после успеха
 * сессия регенерируется для защиты от session fixation.
 *
 * Rate limiting (throttle) и CSRF-защита настраиваются
 * на уровне маршрута, а не в контроллере.
 */
final class AdminAuthController extends Controller
{
    /**
     * Форма входа.
     */
    public function create(): View
    {
        return view('admin.login');
    }

    /**
     * Обработка формы входа.
     *
     * @throws ValidationException Если поля не прошли валидацию
     *                              или учётные данные неверны.
     */
    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => 'Неверный e-mail или пароль.',
            ]);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('admin.dashboard'));
    }

    /**
     * Выход: уничтожение сессии и регенерация CSRF-токена.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}
