<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserSession;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

final class BulkPasswordResetService
{
    /** @return array{users:int,sessions:int} */
    public function reset(string $password): array
    {
        if ($password === '') {
            throw new \InvalidArgumentException('رمز عبور گروهی نمی‌تواند خالی باشد.');
        }

        $result = DB::transaction(function () use ($password): array {
            $now = now();
            $users = 0;
            User::withTrashed()->orderBy('id')->chunkById(200, function ($accounts) use ($password, $now, &$users): void {
                foreach ($accounts as $account) {
                    $account->forceFill([
                        'password' => $password,
                        'must_change_password' => false,
                        'password_changed_at' => $now,
                        'auth_revision' => max(1, (int) $account->auth_revision) + 1,
                        'sessions_revoked_at' => $now,
                        'remember_token' => null,
                    ])->saveQuietly();
                    $users++;
                }
            });

            $sessions = UserSession::query()->whereNull('revoked_at')->update([
                'revoked_at' => $now,
                'revoke_reason' => 'bulk_password_reset',
                'updated_at' => $now,
            ]);

            return ['users' => $users, 'sessions' => $sessions];
        });

        Audit::record('بازنشانی گروهی رمز عبور همه کاربران', null, 'warning', [
            'affected_users' => $result['users'],
            'revoked_sessions' => $result['sessions'],
            'includes_soft_deleted_users' => true,
            'plaintext_logged' => false,
        ], null, 'users.bulk_password_reset');

        return $result;
    }
}
