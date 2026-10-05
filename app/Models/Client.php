<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Client extends \Illuminate\Database\Eloquent\Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id', 'centre_id', 'client_code', 'status', 'date_of_birth', 'gender',
        'preferred_contact', 'referral_source', 'notes', 'created_by',
        'merged_into_id', 'merged_at', 'merged_by',
    ];

    protected function casts(): array
    {
        return ['date_of_birth' => 'date', 'merged_at' => 'datetime'];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function centre(): BelongsTo { return $this->belongsTo(Centre::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function externalIdentities(): HasMany { return $this->hasMany(ExternalIdentity::class); }
    public function cases(): HasMany { return $this->hasMany(CounsellingCase::class); }
    public function intakes(): HasMany { return $this->hasMany(ClientIntake::class); }
    public function guardians(): HasMany { return $this->hasMany(ClientGuardian::class); }
    public function emergencyContacts(): HasMany { return $this->hasMany(EmergencyContact::class); }
    public function consents(): HasMany { return $this->hasMany(ClientConsent::class); }
    public function privateFiles(): HasMany { return $this->hasMany(PrivateFile::class); }
    public function appointments(): HasMany { return $this->hasMany(Appointment::class); }
    public function mergedInto(): BelongsTo { return $this->belongsTo(self::class, 'merged_into_id'); }
    public function mergedSources(): HasMany { return $this->hasMany(self::class, 'merged_into_id'); }

    public function scopeVisibleTo(Builder $query, User $actor): Builder
    {
        return $actor->isSuperAdmin() ? $query : $query->where('centre_id', $actor->centre_id);
    }
}
