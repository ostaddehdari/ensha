<?php

namespace App\Http\Controllers;

use App\Models\CaseAssignment;
use App\Models\CaseStatusHistory;
use App\Models\Centre;
use App\Models\Client;
use App\Models\CounsellingCase;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CounsellingCaseController extends Controller
{
    public function index(Request $request)
    {
        $this->check($request);
        $actor = $request->user();
        $cases = CounsellingCase::query()->visibleTo($actor)
            ->with(['client.user', 'centre', 'assignments.user'])
            ->when($request->filled('status'), fn (Builder $query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('q'), function (Builder $query) use ($request) {
                $term = addcslashes(trim((string) $request->input('q')), '%_\\');
                $query->where(function (Builder $scope) use ($term) {
                    $scope->where('case_number', 'like', "%{$term}%")
                        ->orWhere('title', 'like', "%{$term}%")
                        ->orWhereHas('client', fn (Builder $client) => $client->where('client_code', 'like', "%{$term}%")
                            ->orWhereHas('user', fn (Builder $user) => $user->where('name', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%")));
                });
            })->orderByDesc('id')->paginate(20)->withQueryString();
        return view('cases.index', compact('cases'));
    }

    public function create(Request $request)
    {
        $this->check($request, true);
        $actor = $request->user();
        $clients = Client::visibleTo($actor)->with('user')->where('status', 'active')->orderBy('client_code')->get();
        return view('cases.form', ['case' => new CounsellingCase(), 'clients' => $clients]);
    }

    public function store(Request $request)
    {
        $this->check($request, true);
        $data = $this->validated($request);
        $actor = $request->user();
        $client = Client::visibleTo($actor)->whereKey($data['client_id'])->firstOrFail();
        $case = DB::transaction(function () use ($data, $client, $actor) {
            $case = CounsellingCase::create([
                'client_id' => $client->id,
                'centre_id' => $client->centre_id,
                'case_number' => $this->nextCode($client->centre_id),
                'title' => $data['title'],
                'status' => $data['status'],
                'priority' => $data['priority'],
                'opened_at' => $data['opened_at'] ?? now()->toDateString(),
                'closed_at' => $data['status'] === 'closed' ? ($data['closed_at'] ?? now()->toDateString()) : null,
                'presenting_issue' => $data['presenting_issue'] ?? null,
                'administrative_notes' => $data['administrative_notes'] ?? null,
                'created_by' => $actor->id,
            ]);
            $case->statusHistories()->create(['from_status' => null, 'to_status' => $case->status, 'changed_by' => $actor->id, 'reason' => 'ایجاد پرونده', 'changed_at' => now()]);
            return $case;
        });
        Audit::record('ایجاد پرونده مشاوره', $request, 'info', ['case_id' => $case->id, 'client_id' => $client->id], $case, 'case.created');
        return redirect()->route('cases.show', $case)->with('success', 'پرونده مشاوره ایجاد شد.');
    }

    public function show(Request $request, CounsellingCase $case)
    {
        $this->check($request);
        $this->visible($request, $case);
        $sessionRelations = ['counselor'];
        if ($request->user()->hasPermission('notes.view')) {
            $sessionRelations[] = 'notes.author';
            $sessionRelations[] = 'notes.finalizer';
            $sessionRelations[] = 'notes.addenda.author';
        }
        $case->load([
            'client.user', 'centre', 'creator', 'assignments.user', 'statusHistories.changer',
            'sessions' => fn ($query) => $query->with($sessionRelations)->orderByDesc('session_number'),
            'privateFiles' => fn ($query) => $query->latest('id'),
        ]);
        $assignees = $request->user()->hasPermission('cases.assign') ? $this->availableAssignees($request->user(), $case->centre_id) : collect();
        return view('cases.show', compact('case', 'assignees'));
    }

    public function edit(Request $request, CounsellingCase $case)
    {
        $this->check($request, true);
        $this->visible($request, $case);
        $case->load('client.user');
        return view('cases.form', ['case' => $case, 'clients' => collect([$case->client])]);
    }

    public function update(Request $request, CounsellingCase $case)
    {
        $this->check($request, true);
        $this->visible($request, $case);
        $data = $this->validated($request, $case);
        $fromStatus = $case->status;
        DB::transaction(function () use ($case, $data, $request, $fromStatus) {
            $case->update([
                'title' => $data['title'], 'status' => $data['status'], 'priority' => $data['priority'],
                'opened_at' => $data['opened_at'] ?? null, 'closed_at' => $data['status'] === 'closed' ? ($data['closed_at'] ?? now()->toDateString()) : null,
                'presenting_issue' => $data['presenting_issue'] ?? null, 'administrative_notes' => $data['administrative_notes'] ?? null,
            ]);
            if ($fromStatus !== $case->status) {
                $case->statusHistories()->create(['from_status' => $fromStatus, 'to_status' => $case->status, 'changed_by' => $request->user()->id, 'reason' => $data['status_reason'] ?? null, 'changed_at' => now()]);
            }
        });
        Audit::record('ویرایش پرونده مشاوره', $request, 'info', ['case_id' => $case->id], $case, 'case.updated');
        return redirect()->route('cases.show', $case)->with('success', 'پرونده به‌روزرسانی شد.');
    }

    public function assign(Request $request, CounsellingCase $case)
    {
        abort_unless($request->user()->hasPermission('cases.assign'), 403);
        $this->visible($request, $case);
        $data = $request->validate([
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'assignment_role' => ['required', Rule::in(['counselor', 'case_manager', 'observer'])],
            'is_primary' => ['sometimes', 'boolean'], 'starts_at' => ['nullable', 'date'], 'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'], 'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        abort_unless($this->availableAssignees($request->user(), $case->centre_id)->contains('id', (int) $data['user_id']), 422);
        DB::transaction(function () use ($case, $data) {
            if (! empty($data['is_primary'])) $case->assignments()->where('assignment_role', $data['assignment_role'])->update(['is_primary' => false]);
            $case->assignments()->updateOrCreate(['user_id' => $data['user_id'], 'assignment_role' => $data['assignment_role']], [...$data, 'status' => 'active']);
        });
        Audit::record('تخصیص پرونده به کارمند', $request, 'info', ['case_id' => $case->id, 'user_id' => $data['user_id']], $case, 'case.assignment.created');
        return back()->with('success', 'تخصیص پرونده ثبت شد.');
    }

    public function unassign(Request $request, CounsellingCase $case, CaseAssignment $assignment)
    {
        abort_unless($request->user()->hasPermission('cases.assign'), 403);
        $this->visible($request, $case);
        abort_unless($assignment->case_id === $case->id, 404);
        $assignment->update(['status' => 'ended', 'is_primary' => false, 'ends_at' => now()->toDateString()]);
        Audit::record('پایان تخصیص پرونده', $request, 'info', ['case_id' => $case->id, 'assignment_id' => $assignment->id], $case, 'case.assignment.ended');
        return back()->with('success', 'تخصیص پرونده پایان یافت.');
    }

    private function validated(Request $request, ?CounsellingCase $case = null): array
    {
        return $request->validate([
            'client_id' => [$case ? 'sometimes' : 'required', 'integer', Rule::exists('clients', 'id')],
            'title' => ['required', 'string', 'max:190'], 'status' => ['required', Rule::in(['open', 'on_hold', 'closed', 'archived'])],
            'priority' => ['required', Rule::in(['normal', 'high', 'urgent'])], 'opened_at' => ['nullable', 'date'], 'closed_at' => ['nullable', 'date', 'after_or_equal:opened_at'],
            'presenting_issue' => ['nullable', 'string', 'max:5000'], 'administrative_notes' => ['nullable', 'string', 'max:5000'], 'status_reason' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    private function nextCode(?int $centreId): string
    {
        $next = (int) CounsellingCase::withTrashed()->where('centre_id', $centreId)->lockForUpdate()->count() + 1;
        do { $code = 'CASE-'.str_pad((string) $next++, 5, '0', STR_PAD_LEFT); } while (CounsellingCase::withTrashed()->where('centre_id', $centreId)->where('case_number', $code)->exists());
        return $code;
    }

    private function availableAssignees(User $actor, ?int $centreId)
    {
        return User::query()->where('status', 'active')->whereHas('roleAssignments', function (Builder $query) use ($centreId) {
            $query->where('centre_id', $centreId)->whereHas('role', fn (Builder $role) => $role->whereIn('slug', ['counselor', 'manager', 'secretary']));
        })->orderBy('last_name')->orderBy('first_name')->get();
    }

    private function visible(Request $request, CounsellingCase $case): void
    {
        abort_unless(CounsellingCase::query()->visibleTo($request->user())->whereKey($case->id)->exists(), 404);
    }

    private function check(Request $request, bool $manage = false): void
    {
        abort_unless($request->user()->hasPermission($manage ? 'cases.manage' : 'cases.view'), 403);
    }
}
