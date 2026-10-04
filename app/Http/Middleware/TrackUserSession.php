<?php

namespace App\Http\Middleware;

use App\Support\SessionRegistry;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class TrackUserSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && $request->hasSession()) {
            $revision = $request->session()->get(SessionRegistry::REVISION_KEY);

            if (($revision === null && $user->sessions_revoked_at)
                || ($revision !== null && (int) $revision !== (int) $user->auth_revision)
                || SessionRegistry::currentIsRevoked($request, $user)) {
                return $this->terminateSession($request);
            }

            if ($revision === null) {
                SessionRegistry::putRevision($request, $user);
            }
        }

        $response = $next($request);

        if ($request->user() && $request->hasSession()) {
            SessionRegistry::record($request, $request->user());
        }

        return $response;
    }

    private function terminateSession(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->withErrors([
            'phone' => 'نشست شما به‌دلایل امنیتی پایان یافته است. دوباره وارد شوید.',
        ]);
    }
}
