<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

final class Audit
{
    public static function record(
        string $event,
        ?Request $request = null,
        string $level = 'info',
        array $context = [],
        ?Model $subject = null,
        ?string $action = null,
        ?User $actor = null,
    ): void {
        try {
            AuditLog::create([
                'actor_id' => $actor?->id ?? optional($request?->user())->id,
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject?->getKey(),
                'event' => $event,
                'action' => $action,
                'level' => $level,
                'route' => $request?->path(),
                'ip_address' => $request?->ip(),
                'user_agent' => $request?->userAgent(),
                'context' => $context,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
