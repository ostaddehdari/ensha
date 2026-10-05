<?php

namespace App\Http\Controllers;

use App\Models\AppointmentSlot;
use App\Models\CentreBranch;
use App\Models\ServiceTopic;
use App\Models\ScheduleException;
use App\Models\User;
use App\Services\SlotGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AppointmentSlotController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->hasPermission('appointments.view'), 403);
        $centreId = (int) $request->user()->centre_id;
        $slots = AppointmentSlot::with(['topic', 'counselor', 'room'])->where('centre_id', $centreId)
            ->when($request->filled('date'), fn ($q) => $q->whereDate('slot_date', $request->date('date')))
            ->where('starts_at', '>=', now()->subDay())->orderBy('starts_at')->paginate(40)->withQueryString();
        $topics = ServiceTopic::whereHas('category', fn ($q) => $q->where('centre_id', $centreId))->where('is_active', true)->orderBy('name')->get();
        $counselors = User::whereHas('roleAssignments', fn ($q) => $q->where('centre_id', $centreId)->whereHas('role', fn ($r) => $r->where('slug', 'counselor')))->orderBy('last_name')->get();
        $branches = CentreBranch::where('centre_id', $centreId)->where('is_active', true)->orderByDesc('is_default')->get();
        $exceptions = ScheduleException::where('centre_id', $centreId)->whereDate('exception_date', '>=', now()->subMonth())->orderByDesc('exception_date')->limit(50)->get()->groupBy('user_id');
        return view('appointments.slots', compact('slots', 'topics', 'counselors', 'branches', 'exceptions'));
    }

    public function generate(Request $request, SlotGenerator $generator)
    {
        abort_unless($request->user()->hasPermission('slots.manage'), 403);
        $data = $request->validate([
            'branch_id' => 'required|integer|exists:centre_branches,id', 'topic_id' => 'required|integer|exists:service_topics,id',
            'counselor_id' => 'required|integer|exists:users,id', 'from' => 'required|date', 'to' => 'required|date|after_or_equal:from',
            'mode' => ['required', Rule::in(['in_person', 'phone', 'video'])],
        ]);
        $centreId = (int) $request->user()->centre_id;
        abort_unless(CentreBranch::whereKey($data['branch_id'])->where('centre_id', $centreId)->exists(), 403);
        $count = $generator->generate($centreId, (int) $data['branch_id'], (int) $data['topic_id'], (int) $data['counselor_id'], $data['from'], $data['to'], $data['mode']);
        return back()->with('success', $count.' اسلات جدید تولید شد.');
    }

    public function block(Request $request, AppointmentSlot $slot)
    {
        abort_unless($request->user()->hasPermission('slots.manage'), 403);
        abort_unless($request->user()->isSuperAdmin() || $slot->centre_id === $request->user()->centre_id, 403);
        abort_if($slot->booked_count > 0, 422, 'اسلات رزروشده قابل مسدودسازی نیست.');
        DB::transaction(function () use ($slot) {
            $locked = AppointmentSlot::lockForUpdate()->findOrFail($slot->id);
            $locked->update(['status' => $locked->status === 'blocked' ? 'available' : 'blocked', 'lock_version' => $locked->lock_version + 1]);
        });
        return back()->with('success', 'وضعیت اسلات تغییر کرد.');
    }
}
