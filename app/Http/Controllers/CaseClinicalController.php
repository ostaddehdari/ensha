<?php

namespace App\Http\Controllers;

use App\Models\ConfidentialNote;
use App\Models\CounsellingCase;
use App\Models\CounsellingSession;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CaseClinicalController extends Controller
{
    public function storeSession(Request $request, CounsellingCase $case)
    {
        $this->authorizeCase($request, $case, 'sessions.manage');
        $data = $request->validate([
            'counselor_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'scheduled_at' => ['nullable', 'date'], 'started_at' => ['nullable', 'date'], 'ended_at' => ['nullable', 'date', 'after_or_equal:started_at'],
            'duration_minutes' => ['nullable', 'integer', 'between:1,1440'],
            'channel' => ['required', Rule::in(['in_person', 'phone', 'video', 'chat'])],
            'status' => ['required', Rule::in(['scheduled', 'completed', 'cancelled', 'no_show'])],
            'administrative_summary' => ['nullable', 'string', 'max:5000'],
        ]);
        $actorIsAssignedCounselor = $case->assignments()->where('user_id', $request->user()->id)->where('assignment_role', 'counselor')->where('status', 'active')->exists();
        $counselorId = $data['counselor_id'] ?? ($actorIsAssignedCounselor ? $request->user()->id : null);
        abort_unless($counselorId, 422, 'مشاور جلسه را انتخاب کنید.');
        abort_unless($case->assignments()->where('user_id', $counselorId)->where('assignment_role', 'counselor')->where('status', 'active')->exists(), 422, 'مشاور باید تخصیص فعال این پرونده را داشته باشد.');
        $session = DB::transaction(function () use ($case, $data, $counselorId, $request) {
            $number = $case->sessions()->lockForUpdate()->max('session_number') + 1;
            return $case->sessions()->create([...$data, 'counselor_id' => $counselorId, 'session_number' => $number, 'created_by' => $request->user()->id]);
        });
        Audit::record('ثبت جلسه مشاوره', $request, 'info', ['case_id' => $case->id, 'session_id' => $session->id], $case, 'case.session.created');
        return back()->with('success', 'جلسه مشاوره ثبت شد.');
    }

    public function storeNote(Request $request, CounsellingCase $case, CounsellingSession $session)
    {
        $this->authorizeCase($request, $case, 'notes.manage');
        abort_unless($session->case_id === $case->id, 404);
        $data = $request->validate(['body' => ['required', 'string', 'max:50000']]);
        $note = $session->notes()->create(['case_id' => $case->id, 'author_id' => $request->user()->id, 'body' => $data['body'], 'status' => 'draft']);
        Audit::record('ثبت پیش‌نویس یادداشت محرمانه', $request, 'info', ['case_id' => $case->id, 'session_id' => $session->id, 'note_id' => $note->id], $case, 'case.note.created');
        return back()->with('success', 'پیش‌نویس یادداشت محرمانه ثبت شد.');
    }

    public function updateNote(Request $request, CounsellingCase $case, ConfidentialNote $note)
    {
        $this->authorizeCase($request, $case, 'notes.manage');
        $this->authorizeNote($request, $case, $note);
        abort_if($note->isFinalized(), 409, 'یادداشت نهایی قابل ویرایش نیست؛ الحاقیه ثبت کنید.');
        $data = $request->validate(['body' => ['required', 'string', 'max:50000']]);
        $note->update(['body' => $data['body']]);
        Audit::record('ویرایش پیش‌نویس یادداشت محرمانه', $request, 'info', ['case_id' => $case->id, 'note_id' => $note->id], $case, 'case.note.updated');
        return back()->with('success', 'پیش‌نویس به‌روزرسانی شد.');
    }

    public function finalizeNote(Request $request, CounsellingCase $case, ConfidentialNote $note)
    {
        $this->authorizeCase($request, $case, 'notes.manage');
        $this->authorizeNote($request, $case, $note);
        abort_if($note->isFinalized(), 409, 'یادداشت قبلاً نهایی شده است.');
        $plainBody = $note->body;
        $note->update(['status' => 'finalized', 'content_hash' => hash('sha256', $plainBody), 'finalized_by' => $request->user()->id, 'finalized_at' => now()]);
        Audit::record('نهایی‌سازی یادداشت محرمانه', $request, 'warning', ['case_id' => $case->id, 'note_id' => $note->id, 'hash' => $note->content_hash], $case, 'case.note.finalized');
        return back()->with('success', 'یادداشت نهایی و قفل شد.');
    }

    public function storeAddendum(Request $request, CounsellingCase $case, ConfidentialNote $note)
    {
        $this->authorizeCase($request, $case, 'notes.manage');
        $this->authorizeNote($request, $case, $note, false);
        abort_unless($note->isFinalized(), 409, 'الحاقیه فقط برای یادداشت نهایی مجاز است.');
        $data = $request->validate(['body' => ['required', 'string', 'max:20000']]);
        $addendum = $note->addenda()->create(['author_id' => $request->user()->id, 'body' => $data['body'], 'content_hash' => hash('sha256', $data['body'])]);
        Audit::record('ثبت الحاقیه یادداشت محرمانه', $request, 'warning', ['case_id' => $case->id, 'note_id' => $note->id, 'addendum_id' => $addendum->id], $case, 'case.note.addendum.created');
        return back()->with('success', 'الحاقیه ثبت شد.');
    }

    private function authorizeCase(Request $request, CounsellingCase $case, string $permission): void
    {
        abort_unless($request->user()->hasPermission($permission), 403);
        abort_unless(CounsellingCase::visibleTo($request->user())->whereKey($case->id)->exists(), 404);
    }

    private function authorizeNote(Request $request, CounsellingCase $case, ConfidentialNote $note, bool $requireAuthorForDraft = true): void
    {
        abort_unless($note->case_id === $case->id, 404);
        if ($requireAuthorForDraft && ! $request->user()->hasPermission('cases.manage')) abort_unless($note->author_id === $request->user()->id, 403);
    }
}
