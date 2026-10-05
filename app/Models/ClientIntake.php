<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientIntake extends \Illuminate\Database\Eloquent\Model
{
    use HasFactory;

    protected $fillable = ['client_id', 'case_id', 'intake_number', 'intake_date', 'status', 'risk_level', 'referral_source', 'presenting_concern', 'medical_notes', 'safeguarding_notes', 'answers', 'completed_by', 'completed_at'];
    protected function casts(): array { return ['intake_date' => 'date', 'answers' => 'array', 'completed_at' => 'datetime']; }
    public function client(): BelongsTo { return $this->belongsTo(Client::class); }
    public function counsellingCase(): BelongsTo { return $this->belongsTo(CounsellingCase::class, 'case_id'); }
    public function completer(): BelongsTo { return $this->belongsTo(User::class, 'completed_by'); }
}
