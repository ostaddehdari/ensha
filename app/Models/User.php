<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable, SoftDeletes;

    protected ?array $permissionSlugCache = null;

    protected $fillable = [
        'first_name', 'last_name', 'name', 'phone', 'national_id', 'password',
        'role', 'role_id', 'centre_id', 'is_active', 'status', 'status_reason',
        'status_changed_at', 'status_changed_by', 'metadata', 'created_by',
        'last_login_at', 'last_login_ip', 'password_changed_at', 'must_change_password',
        'auth_revision', 'sessions_revoked_at', 'avatar_path',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'metadata' => 'array',
            'status_changed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'must_change_password' => 'boolean',
            'auth_revision' => 'integer',
            'sessions_revoked_at' => 'datetime',
        ];
    }

    public function roleAssignments(): HasMany { return $this->hasMany(UserRoleCentre::class); }

    public function getAvatarUrlAttribute(): ?string {
        return $this->avatar_path ? asset('storage/'.$this->avatar_path) : null;
    }

    public function staffProfile(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(StaffProfile::class);
    }

    public function client(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Client::class);
    }

    public function assignedRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    public function centre(): BelongsTo
    {
        return $this->belongsTo(Centre::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(self::class, 'created_by');
    }

    public function createdUsers(): HasMany
    {
        return $this->hasMany(self::class, 'created_by');
    }

    public function statusChanger(): BelongsTo
    {
        return $this->belongsTo(self::class, 'status_changed_by');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(UserSession::class);
    }

    public function profileValues(): HasMany
    {
        return $this->hasMany(ProfileValue::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'actor_id');
    }

    public function subjectAuditLogs(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'subject');
    }

    public function getRoleLabelAttribute(): string
    {
        return $this->assignedRole?->name ?? config('panels.roles.'.$this->role, $this->role);
    }

    public function getDisplayNameAttribute(): string
    {
        return trim($this->first_name.' '.$this->last_name) ?: $this->phone;
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'active' => 'فعال',
            'blocked' => 'مسدود',
            default => 'غیرفعال',
        };
    }

    public function getStatusToneAttribute(): string
    {
        return match ($this->status) {
            'active' => 'success',
            'blocked' => 'danger',
            default => 'warning',
        };
    }

    public function isActive(): bool
    {
        return $this->is_active && $this->status === 'active' && ! $this->trashed();
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin' || $this->assignedRole?->slug === 'super_admin';
    }

    public function hasPermission(string $permission): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        if (! $this->assignedRole || ! $this->assignedRole->is_active) {
            return false;
        }

        if ($this->permissionSlugCache === null) {
            $this->permissionSlugCache = $this->assignedRole->permissions()
                ->where('permissions.is_active', true)
                ->pluck('permissions.slug')
                ->all();
        }

        return in_array($permission, $this->permissionSlugCache, true);
    }

    public function scopeVisibleTo(Builder $query, User $actor): Builder
    {
        if ($actor->isSuperAdmin()) {
            return $query;
        }

        return $query->where('centre_id', $actor->centre_id)
            ->where('role', '!=', 'super_admin')
            ->whereDoesntHave('assignedRole', fn (Builder $role) => $role->where('slug', 'super_admin'));
    }
}
