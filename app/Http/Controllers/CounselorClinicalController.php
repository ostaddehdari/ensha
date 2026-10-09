<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\CaseAssignment;
use App\Models\CounsellingCase;
use App\Models\CounsellingSession;
use App\Models\SessionReport;
use App\Models\SessionReportTemplate;
use App\Services\AppointmentBookingService;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CounselorClinicalController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user->hasPermission('session_reports.view'), 403);

        $base = Appointment::query()->visibleTo($user)
            ->with(['client.user', 'topic', 'sessionReport'])
            ->when($request->filled('topic_id'), fn ($query) => $query->where('topic_id', $request->integer('topic_id')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = addcslashes(trim((string) $request->input('q')), '%_\\');
                $query->where(function ($scope) use ($term) {
                    $scope->where('appointment_number', 'like', "%{$term}%")
                        ->orWhereHas('client.user', fn ($client) => $client->where('name', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%"));
                });
            });

        $todayStart = now()->startOfDay();
        $tomorrow = $todayStart->copy()->addDay();
        $today = (clone $base)->where('starts_at', '>=', $todayStart)->where('starts_at', '<', $tomorrow)->orderBy('starts_at')->get();
        $upcoming = (clone $base)->where('starts_at', '>=', $tomorrow)->where('starts_at', '<', $tomorrow->copy()->addDays(14))->orderBy('starts_at')->limit(30)->get();
        $recent = (clone $base)->where('starts_at', '<', $todayStart)->orderByDesc('starts_at')->limit(12)->get();

        $history = null;
        if ($request->boolean('history')) {
            $history = (clone $base)
                ->when($request->filled('from'), fn ($query) => $query->whereDate('starts_at', '>=', $request->date('from')))
                ->when($request->filled('to'), fn ($query) => $query->whereDate('starts_at', '<=', $request->date('to')))
                ->orderByDesc('starts_at')->paginate(30)->withQueryString();
        }

        $stats = [
            'today' => $today->count(),
            'upcoming' => (clone $base)->where('starts_at', '>=', $tomorrow)->count(),
            'completed' => (clone $base)->where('status', 'completed')->count(),
            'reports_pending' => (clone $base)->where('status', 'completed')->whereDoesntHave('sessionReport', fn ($query) => $query->where('status', 'finalized'))->count(),
        ];

        return view('counselor.workspace', compact('today', 'upcoming', 'recent', 'history', 'stats'));
    }

    public function show(Request $request, Appointment $appointment)
    {
        $this->authorizeView($request, $appointment);
        $appointment->load(['client.user', 'client.consents', 'topic', 'counselor', 'case']);
        $session = CounsellingSession::with('report.addenda.author')->where('appointment_id', $appointment->id)->first();
        $report = $session?->report;
        $template = $report?->template ?: SessionReportTemplate::effectiveFor((int) $appointment->centre_id, $appointment->topic_id)->first();
        $template ??= SessionReportTemplate::ensureDefault((int) $appointment->centre_id);
        $fields = $template?->fields ?: [];
        $answers = $report?->structured_answers ?: [];
        $recordingConsent = $appointment->client?->consents
            ?->first(fn ($consent) => $consent->consent_type === 'recording' && $consent->is_granted && ! $consent->revoked_at && (! $consent->expires_at || $consent->expires_at->isFuture()));
        $canManage = $this->canManage($request, $appointment);

        return view('counselor.session-report', compact('appointment', 'session', 'report', 'template', 'fields', 'answers', 'recordingConsent', 'canManage'));
    }

    public function start(Request $request, Appointment $appointment, AppointmentBookingService $booking)
    {
        $this->authorizeManage($request, $appointment);
        abort_if(in_array($appointment->status, ['completed', 'cancelled', 'no_show'], true), 422, 'این نوبت قابل شروع نیست.');

        foreach (['confirmed', 'arrived', 'in_session'] as $next) {
            $appointment->refresh();
            if ($appointment->status === $next || $appointment->status === 'in_session') {
                continue;
            }
            if ($appointment->canTransitionTo($next)) {
                $booking->transition($appointment, $next, $request->user()->id, 'شروع جلسه توسط مشاور');
            }
        }
        $appointment->refresh();
        abort_unless($appointment->status === 'in_session', 422, 'وضعیت نوبت اجازه شروع جلسه را نمی‌دهد.');

        $session = $this->ensureSession($appointment, $request->user()->id);
        $session->update(['started_at' => $session->started_at ?: now(), 'status' => 'in_progress']);
        $appointment->update(['session_started_at' => $appointment->session_started_at ?: now()]);
        Audit::record('شروع جلسه از فضای کاری مشاور', $request, 'info', ['appointment_id' => $appointment->id, 'session_id' => $session->id], $appointment, 'session.started');

        return back()->with('success', 'جلسه شروع شد و گزارش آن آماده ثبت است.');
    }

    public function complete(Request $request, Appointment $appointment, AppointmentBookingService $booking)
    {
        $this->authorizeManage($request, $appointment);
        abort_unless($appointment->status === 'in_session', 422, 'ابتدا جلسه را شروع کنید.');
        $session = $this->ensureSession($appointment, $request->user()->id);
        $endedAt = now();
        $startedAt = $session->started_at ?: $appointment->starts_at;
        $session->update([
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'duration_minutes' => max(1, Carbon::parse($startedAt)->diffInMinutes($endedAt)),
            'status' => 'completed',
        ]);
        $booking->transition($appointment, 'completed', $request->user()->id, 'پایان جلسه توسط مشاور');
        $appointment->update(['session_ended_at' => $endedAt]);
        Audit::record('پایان جلسه از فضای کاری مشاور', $request, 'info', ['appointment_id' => $appointment->id, 'session_id' => $session->id], $appointment, 'session.completed');

        return back()->with('success', 'جلسه پایان یافت؛ گزارش را تکمیل و نهایی کنید.');
    }

    public function save(Request $request, Appointment $appointment)
    {
        $this->authorizeManage($request, $appointment);
        $existingReport = SessionReport::where('appointment_id', $appointment->id)->first();
        $template = $existingReport?->template ?: SessionReportTemplate::effectiveFor((int) $appointment->centre_id, $appointment->topic_id)->first();
        $template ??= SessionReportTemplate::ensureDefault((int) $appointment->centre_id);
        $data = $request->validate([
            'summary' => ['nullable', 'string', 'max:50000'],
            'outcome' => ['nullable', 'string', 'max:20000'],
            'recommendations' => ['nullable', 'string', 'max:20000'],
            'follow_up_required' => ['nullable', 'boolean'],
            'follow_up_at' => ['nullable', 'date'],
            'answers' => ['nullable', 'array'],
        ]);
        $answers = $this->validatedAnswers($template, $data['answers'] ?? [], false);
        $session = $this->ensureSession($appointment, $request->user()->id);

        $report = DB::transaction(function () use ($appointment, $session, $template, $data, $answers) {
            $report = SessionReport::withTrashed()->where('appointment_id', $appointment->id)->lockForUpdate()->first();
            if ($report?->trashed()) {
                $report->restore();
            }
            abort_if($report?->isFinalized(), 409, 'گزارش نهایی قابل ویرایش نیست؛ الحاقیه ثبت کنید.');
            $values = [
                'centre_id' => $appointment->centre_id,
                'counselling_session_id' => $session->id,
                'case_id' => $session->case_id,
                'client_id' => $appointment->client_id,
                'counselor_id' => $appointment->counselor_id,
                'template_id' => $template?->id,
                'template_name_snapshot' => $template?->name,
                'template_version_snapshot' => $template?->version,
                'status' => 'draft',
                'summary' => $data['summary'] ?? null,
                'outcome' => $data['outcome'] ?? null,
                'recommendations' => $data['recommendations'] ?? null,
                'structured_answers' => $answers,
                'follow_up_required' => (bool) ($data['follow_up_required'] ?? false),
                'follow_up_at' => ! empty($data['follow_up_required']) ? ($data['follow_up_at'] ?? null) : null,
            ];
            if ($report) {
                $report->update($values);
                return $report;
            }
            return SessionReport::create(['appointment_id' => $appointment->id, ...$values]);
        });
        Audit::record('ذخیره پیش‌نویس گزارش جلسه', $request, 'warning', ['appointment_id' => $appointment->id, 'report_id' => $report->id], $report, 'session.report.saved');

        return back()->with('success', 'پیش‌نویس گزارش ذخیره شد.');
    }

    public function finalize(Request $request, Appointment $appointment)
    {
        $this->authorizeManage($request, $appointment);
        abort_unless($appointment->status === 'completed', 422, 'گزارش پس از پایان جلسه نهایی می‌شود.');
        $report = SessionReport::where('appointment_id', $appointment->id)->firstOrFail();
        abort_if($report->isFinalized(), 409, 'گزارش قبلاً نهایی شده است.');
        $template = $report->template;
        $this->validatedAnswers($template, $report->structured_answers ?: [], true);
        $hasContent = filled($report->summary) || filled($report->outcome) || filled($report->recommendations)
            || collect($report->structured_answers ?: [])->contains(fn ($value) => ! in_array($value, [null, '', false, 0, '0'], true));
        if (! $hasContent) {
            throw ValidationException::withMessages(['report' => 'برای نهایی‌سازی، حداقل بخشی از گزارش را تکمیل کنید.']);
        }

        $plain = [
            'summary' => $report->summary,
            'outcome' => $report->outcome,
            'recommendations' => $report->recommendations,
            'answers' => $report->structured_answers,
            'follow_up_required' => $report->follow_up_required,
            'follow_up_at' => $report->follow_up_at?->toIso8601String(),
        ];
        $report->update([
            'status' => 'finalized',
            'content_hash' => hash('sha256', json_encode($plain, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
            'finalized_by' => $request->user()->id,
            'finalized_at' => now(),
        ]);
        Audit::record('نهایی‌سازی گزارش جلسه', $request, 'warning', ['appointment_id' => $appointment->id, 'report_id' => $report->id, 'hash' => $report->content_hash], $report, 'session.report.finalized');

        return back()->with('success', 'گزارش نهایی و قفل شد.');
    }

    public function addendum(Request $request, Appointment $appointment)
    {
        $this->authorizeManage($request, $appointment);
        $report = SessionReport::where('appointment_id', $appointment->id)->firstOrFail();
        abort_unless($report->isFinalized(), 409, 'الحاقیه فقط برای گزارش نهایی مجاز است.');
        $data = $request->validate(['body' => ['required', 'string', 'max:20000']]);
        $addendum = $report->addenda()->create([
            'author_id' => $request->user()->id,
            'body' => $data['body'],
            'content_hash' => hash('sha256', $data['body']),
        ]);
        Audit::record('ثبت الحاقیه گزارش جلسه', $request, 'warning', ['appointment_id' => $appointment->id, 'report_id' => $report->id, 'addendum_id' => $addendum->id], $report, 'session.report.addendum');

        return back()->with('success', 'الحاقیه گزارش ثبت شد.');
    }

    private function ensureSession(Appointment $appointment, int $actorId): CounsellingSession
    {
        return DB::transaction(function () use ($appointment, $actorId) {
            $appointment = Appointment::query()->lockForUpdate()->findOrFail($appointment->id);
            $case = $appointment->case;
            if (! $case) {
                $case = CounsellingCase::create([
                    'client_id' => $appointment->client_id,
                    'centre_id' => $appointment->centre_id,
                    'case_number' => 'CASE-'.strtoupper(bin2hex(random_bytes(6))),
                    'title' => 'پرونده '.$appointment->topic_name_snapshot,
                    'status' => 'open',
                    'opened_at' => now()->toDateString(),
                    'created_by' => $actorId,
                ]);
                $appointment->update(['case_id' => $case->id]);
            }

            CaseAssignment::updateOrCreate(
                ['case_id' => $case->id, 'user_id' => $appointment->counselor_id, 'assignment_role' => 'counselor'],
                ['is_primary' => true, 'status' => 'active', 'starts_at' => now()->toDateString(), 'ends_at' => null, 'notes' => 'تخصیص خودکار از نوبت']
            );

            $session = CounsellingSession::where('appointment_id', $appointment->id)->lockForUpdate()->first();
            if ($session) {
                return $session;
            }
            $legacy = CounsellingSession::whereNull('appointment_id')->where('case_id', $case->id)
                ->where('counselor_id', $appointment->counselor_id)->where('scheduled_at', $appointment->starts_at)->lockForUpdate()->first();
            if ($legacy) {
                $legacy->update(['appointment_id' => $appointment->id]);
                return $legacy;
            }
            $number = ((int) CounsellingSession::where('case_id', $case->id)->lockForUpdate()->max('session_number')) + 1;
            return CounsellingSession::create([
                'appointment_id' => $appointment->id,
                'case_id' => $case->id,
                'counselor_id' => $appointment->counselor_id,
                'session_number' => $number,
                'scheduled_at' => $appointment->starts_at,
                'channel' => in_array($appointment->mode, ['in_person', 'phone', 'video'], true) ? $appointment->mode : 'in_person',
                'status' => 'scheduled',
                'created_by' => $actorId,
            ]);
        }, 3);
    }

    private function validatedAnswers(?SessionReportTemplate $template, array $input, bool $requireAll): array
    {
        $answers = [];
        foreach ($template?->fields ?: [] as $field) {
            $key = (string) ($field['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $type = $field['type'] ?? 'text';
            $value = $input[$key] ?? ($type === 'checkbox' ? false : null);
            if ($type === 'checkbox') {
                $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            } elseif (is_string($value)) {
                $value = trim($value);
            }
            if ($type === 'select' && filled($value) && ! in_array($value, $field['options'] ?? [], true)) {
                throw ValidationException::withMessages(["answers.{$key}" => 'گزینه انتخاب‌شده معتبر نیست.']);
            }
            if (($field['required'] ?? false) && $requireAll && in_array($value, [null, '', false], true)) {
                throw ValidationException::withMessages(["answers.{$key}" => 'فیلد «'.($field['label'] ?? $key).'» الزامی است.']);
            }
            if (is_string($value) && mb_strlen($value) > 10000) {
                throw ValidationException::withMessages(["answers.{$key}" => 'مقدار این فیلد بیش از حد مجاز است.']);
            }
            $answers[$key] = $value;
        }
        return $answers;
    }

    private function authorizeView(Request $request, Appointment $appointment): void
    {
        abort_unless($request->user()->hasPermission('session_reports.view'), 403);
        abort_unless(Appointment::visibleTo($request->user())->whereKey($appointment->id)->exists(), 404);
    }

    private function authorizeManage(Request $request, Appointment $appointment): void
    {
        $this->authorizeView($request, $appointment);
        abort_unless($this->canManage($request, $appointment), 403);
    }

    private function canManage(Request $request, Appointment $appointment): bool
    {
        return $request->user()->hasPermission('session_reports.manage')
            && (int) $appointment->counselor_id === (int) $request->user()->id;
    }
}
