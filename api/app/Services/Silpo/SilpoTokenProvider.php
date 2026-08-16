<?php

namespace App\Services\Silpo;

use App\Models\SilpoToken;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Client\OAuth\TokenSet;

/**
 * Єдине джерело валідного токена Сільпо для MCP-викликів.
 * Web/API — токен залогіненого юзера з silpo_tokens; CLI/демо — SILPO_DEMO_TOKEN.
 */
class SilpoTokenProvider
{
    /** Токен для поточного контексту (для withToken(...) у зареєстрованому клієнті). */
    public function currentAccessToken(): string
    {
        // 1) Залогінений через Sanctum юзер.
        if (Auth::check()) {
            $token = $this->forUser(Auth::user());
            if ($token !== null) {
                return $token;
            }
        }

        // 2) Демо/неавторизований контекст (веб-демо, CLI silpo:ping, черга):
        //    беремо токен demo-юзера, збережений після OAuth-логіну Сільпо.
        $demo = User::where('email', 'demo@mealize.app')->first();
        if ($demo !== null) {
            $token = $this->forUser($demo);
            if ($token !== null) {
                return $token;
            }
        }

        // 3) Останній фолбек — статичний токен з .env (якщо заданий).
        return (string) config('services.silpo.demo_token', '');
    }

    /** Валідний access-токен користувача (null, якщо немає/протух без refresh). */
    public function forUser(User $user): ?string
    {
        $row = SilpoToken::where('user_id', $user->id)->first();

        if ($row === null) {
            return null;
        }

        // TODO(B2.1): якщо $row->isExpired() і є refresh_token — оновити через token-endpoint.
        return $row->access_token;
    }

    /** Зберегти TokenSet, отриманий у OAuth-callback, для користувача. */
    public function storeFor(User $user, TokenSet $token): SilpoToken
    {
        return SilpoToken::updateOrCreate(
            ['user_id' => $user->id],
            [
                'access_token' => $token->accessToken,
                'refresh_token' => $token->refreshToken,
                'expires_at' => $token->expiresAt ? now()->setTimestamp($token->expiresAt) : null,
                'scope' => $token->scope,
            ],
        );
    }
}
