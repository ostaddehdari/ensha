<?php

namespace App\Support;

use App\Models\User;
use App\Models\UserSession;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class SessionRegistry
{
    public const REVISION_KEY = 'ensha_auth_revision';

    public static function hash(Request $request): ?string
    {
        if (! $request->hasSession() || ! $request->session()->getId()) {
            return null;
        }

        return hash('sha256', $request->session()->getId());
    }

    public static function record(Request $request, User $user): ?UserSession
    {
        $hash = self::hash($request);
        if (! $hash) {
            return null;
        }

        $agent = (string) $request->userAgent();
        $session = UserSession::query()->firstOrNew(['session_hash' => $hash]);
        if ($session->exists && $session->revoked_at) {
            return $session;
        }
        if ($session->exists
            && (int) $session->user_id === (int) $user->id
            && $session->ip_address === $request->ip()
            && $session->user_agent === Str::limit($agent, 2000, '')
            && $session->last_activity_at?->isAfter(now()->subMinutes(3))) {
            return $session;
        }

        $session->fill([
            'user_id' => $user->id,
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit($agent, 2000, ''),
            'device_name' => self::describeAgent($agent),
            'last_activity_at' => now(),
            'revoked_at' => null,
            'revoke_reason' => null,
        ])->save();

        return $session;
    }

    public static function currentIsRevoked(Request $request, User $user): bool
    {
        $hash = self::hash($request);
        if (! $hash) {
            return false;
        }

        return UserSession::query()
            ->where('user_id', $user->id)
            ->where('session_hash', $hash)
            ->whereNotNull('revoked_at')
            ->exists();
    }

    public static function invalidateAll(User $user, string $reason, ?string $exceptHash = null): int
    {
        $query = $user->sessions()->active();
        if ($exceptHash) {
            $query->where('session_hash', '!=', $exceptHash);
        }

        $count = $query->update([
            'revoked_at' => now(),
            'revoke_reason' => $reason,
            'updated_at' => now(),
        ]);

        $user->forceFill([
            'auth_revision' => max(1, (int) $user->auth_revision) + 1,
            'sessions_revoked_at' => now(),
            'remember_token' => null,
        ])->saveQuietly();

        return $count;
    }

    public static function revokeCurrent(Request $request, string $reason): void
    {
        $hash = self::hash($request);
        $user = $request->user();
        if (! $hash || ! $user) {
            return;
        }

        UserSession::query()
            ->where('user_id', $user->id)
            ->where('session_hash', $hash)
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => now(),
                'revoke_reason' => $reason,
                'updated_at' => now(),
            ]);
    }

    public static function putRevision(Request $request, User $user): void
    {
        if ($request->hasSession()) {
            $request->session()->put(self::REVISION_KEY, (int) $user->auth_revision);
        }
    }

    private static function describeAgent(string $agent): string
    {
        $browser = match (true) {
            Str::contains($agent, ['Edg/', 'Edge/']) => 'Microsoft Edge',
            Str::contains($agent, 'Firefox/') => 'Firefox',
            Str::contains($agent, ['Chrome/', 'CriOS/']) => 'Chrome',
            Str::contains($agent, 'Safari/') => 'Safari',
            default => 'مرورگر ناشناس',
        };

        $platform = match (true) {
            Str::contains($agent, 'Windows') => 'Windows',
            Str::contains($agent, ['iPhone', 'iPad']) => 'iOS',
            Str::contains($agent, 'Android') => 'Android',
            Str::contains($agent, ['Macintosh', 'Mac OS']) => 'macOS',
            Str::contains($agent, 'Linux') => 'Linux',
            default => 'دستگاه ناشناس',
        };

        return $browser.' · '.$platform;
    }
}
