<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Єдиний резолвер поточного користувача (SEC-2). Демо-fallback дозволений
 * ЛИШЕ локально; у проді анонім → 401 (SEC-1/SEC-4). Магічний email — з конфіга.
 */
trait ResolvesCurrentUser
{
    protected function currentUser(): User
    {
        if ($user = Auth::user()) {
            return $user;
        }

        abort_unless(app()->environment('local', 'testing'), 401, 'Автентифікація потрібна');

        return User::firstOrCreate(
            ['email' => config('app.demo_email')],
            ['name' => 'Demo', 'password' => bcrypt(Str::random(40))],
        );
    }
}
