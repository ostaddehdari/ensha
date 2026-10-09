<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SessionReportTemplate extends \Illuminate\Database\Eloquent\Model
{
    protected $fillable = ['centre_id', 'topic_id', 'name', 'version', 'fields', 'is_default', 'is_active', 'created_by'];

    protected function casts(): array
    {
        return ['fields' => 'array', 'is_default' => 'boolean', 'is_active' => 'boolean'];
    }

    public function centre(): BelongsTo { return $this->belongsTo(Centre::class); }
    public function topic(): BelongsTo { return $this->belongsTo(ServiceTopic::class, 'topic_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function reports(): HasMany { return $this->hasMany(SessionReport::class, 'template_id'); }

    public function scopeEffectiveFor(Builder $query, int $centreId, ?int $topicId): Builder
    {
        return $query->where('centre_id', $centreId)->where('is_active', true)
            ->where(fn (Builder $scope) => $scope->where('topic_id', $topicId)->orWhereNull('topic_id'))
            ->orderByRaw('topic_id IS NULL')->orderByDesc('is_default')->orderByDesc('version');
    }

    public static function ensureDefault(int $centreId): self
    {
        $template = static::where('centre_id', $centreId)->whereNull('topic_id')->where('is_default', true)->first();
        if ($template) {
            return $template;
        }
        return static::create([
            'centre_id' => $centreId,
            'topic_id' => null,
            'name' => 'فرم عمومی گزارش جلسه',
            'version' => 1,
            'fields' => [
                ['key' => 'presenting_issue_reviewed', 'label' => 'موضوع اصلی جلسه بررسی شد', 'type' => 'checkbox', 'required' => false, 'options' => []],
                ['key' => 'goals_reviewed', 'label' => 'اهداف درمانی مرور شد', 'type' => 'checkbox', 'required' => false, 'options' => []],
                ['key' => 'risk_assessed', 'label' => 'ارزیابی خطر انجام شد', 'type' => 'checkbox', 'required' => false, 'options' => []],
                ['key' => 'homework_assigned', 'label' => 'تمرین یا تکلیف تعیین شد', 'type' => 'checkbox', 'required' => false, 'options' => []],
                ['key' => 'session_result', 'label' => 'نتیجه کلی جلسه', 'type' => 'select', 'required' => true, 'options' => ['پیشرفت مناسب', 'نیازمند پیگیری', 'بدون تغییر محسوس', 'ارجاع به متخصص دیگر']],
                ['key' => 'next_session_focus', 'label' => 'محور پیشنهادی جلسه بعد', 'type' => 'textarea', 'required' => false, 'options' => []],
            ],
            'is_default' => true,
            'is_active' => true,
            'created_by' => null,
        ]);
    }
}
