<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserSession;
use App\Support\Audit;
use App\Support\SessionRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class UserSessionController extends Controller
{
    public function revokeAll(Request $request, User $user): RedirectResponse
    {
        $this->authorize('revokeSessions', $user);
        $count = SessionRegistry::invalidateAll($user, 'revoked_by_administrator');

        Audit::record(
            'ابطال همه نشست‌های کاربر',
            $request,
            'warning',
            ['target_user_id' => $user->id, 'revoked_sessions' => $count],
            $user,
            'user.sessions_revoked',
        );

        return back()->with('success', $count
            ? number_format($count).' نشست فعال پایان داده شد.'
            : 'اعتبار ورودهای قبلی باطل شد؛ نشست فعالی برای نمایش وجود نداشت.');
    }

    public function revoke(Request $request, User $user, UserSession $userSession): RedirectResponse
    {
        $this->authorize('revokeSessions', $user);
        abort_unless((int) $userSession->user_id === (int) $user->id, 404);

        if (! $userSession->revoked_at) {
            $userSession->update([
                'revoked_at' => now(),
                'revoke_reason' => 'revoked_by_administrator',
            ]);
        }

        Audit::record(
            'ابطال یک نشست کاربر',
            $request,
            'warning',
            ['target_user_id' => $user->id, 'session_id' => $userSession->id],
            $user,
            'user.session_revoked',
        );

        return back()->with('success', 'نشست انتخاب‌شده پایان داده شد.');
    }
}
