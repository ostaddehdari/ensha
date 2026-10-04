<?php

namespace App\Http\Controllers;

use App\Models\Centre;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit;
use App\Support\SessionRegistry;
use App\Support\ShiftSlotNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CentreOperationsController extends Controller
{
    private function scope(Request $request, ?Centre $centre, string $permission): Centre
    {
        $actor = $request->user();
        abort_unless($actor->hasPermission($permission), 403);

        $centre ??= Centre::findOrFail($actor->centre_id);

        if (! $actor->isSuperAdmin()) {
            $hasActiveAssignment = $actor->roleAssignments()
                ->where('role_id', $actor->role_id)
                ->where('centre_id', $centre->id)
                ->whereHas('role', fn ($role) => $role->where('is_active', true))
                ->exists();

            abort_unless($hasActiveAssignment, 403);
        }

        return $centre;
    }

    private function counselor(Centre $centre, int $userId): User
    {
        return User::query()
            ->whereKey($userId)
            ->where('is_active', true)
            ->where('status', 'active')
            ->whereHas('roleAssignments', fn ($query) => $query
                ->where('centre_id', $centre->id)
                ->whereHas('role', fn ($role) => $role->where('slug', 'counselor')->where('is_active', true)))
            ->firstOrFail();
    }

    public function index(Request $request, ?Centre $centre = null)
    {
        $centre ??= Centre::findOrFail($request->user()->centre_id);

        return $this->topicsPage($request, $centre);
    }

    public function attachCounselor(Request $request, Centre $centre)
    {
        $this->scope($request, $centre, 'counselors.manage');
        $data = $request->validate(['national_id' => ['required', 'string', 'size:10']]);
        $user = User::where('national_id', $data['national_id'])
            ->where('is_active', true)
            ->where('status', 'active')
            ->first();

        if (! $user || ! $user->roleAssignments()->whereHas('role', fn ($query) => $query->where('slug', 'counselor'))->exists()) {
            throw ValidationException::withMessages(['national_id' => 'مشاور فعال با این کد ملی یافت نشد.']);
        }

        $role = Role::where('slug', 'counselor')->where('is_active', true)->firstOrFail();
        $user->roleAssignments()->firstOrCreate(['role_id' => $role->id, 'centre_id' => $centre->id]);
        SessionRegistry::invalidateAll($user, 'centre_assigned');
        Audit::record('عضویت مشاور در مرکز', $request, 'warning', ['user_id' => $user->id, 'centre_id' => $centre->id], $user);

        return back()->with('success', 'مشاور به مرکز اضافه شد.');
    }

    public function category(Request $request, Centre $centre)
    {
        $this->scope($request, $centre, 'fees.manage');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('service_categories', 'name')->where('centre_id', $centre->id)],
        ]);
        DB::table('service_categories')->insert([...$data, 'centre_id' => $centre->id, 'created_at' => now(), 'updated_at' => now()]);

        return back()->with('success', 'دسته ثبت شد.');
    }

    public function topic(Request $request, Centre $centre)
    {
        $this->scope($request, $centre, 'fees.manage');
        $data = $request->validate([
            'category_id' => 'required|integer',
            'name' => 'required|string|max:120',
            'minimum_minutes' => 'required|integer|min:1|max:1440',
            'session_minutes' => 'required|integer|min:1|max:1440',
            'price' => 'required|integer|min:0',
            'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);
        abort_unless(DB::table('service_categories')->where('centre_id', $centre->id)->where('id', $data['category_id'])->exists(), 403);

        if ($data['session_minutes'] < $data['minimum_minutes']) {
            throw ValidationException::withMessages(['session_minutes' => 'مدت جلسه نباید کمتر از حداقل زمان باشد.']);
        }
        if (DB::table('service_topics')->where('category_id', $data['category_id'])->where('name', $data['name'])->exists()) {
            throw ValidationException::withMessages(['name' => 'این موضوع در دسته انتخاب‌شده وجود دارد.']);
        }

        DB::table('service_topics')->insert([...$data, 'created_at' => now(), 'updated_at' => now()]);

        return back()->with('success', 'موضوع ثبت شد.');
    }

    public function updateTopic(Request $request, Centre $centre, int $topic)
    {
        $this->scope($request, $centre, 'fees.manage');
        $data = $request->validate([
            'minimum_minutes' => 'required|integer|min:1|max:1440',
            'session_minutes' => 'required|integer|min:1|max:1440',
            'price' => 'required|integer|min:0',
            'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);
        abort_unless($data['session_minutes'] >= $data['minimum_minutes'], 422);
        $updated = DB::table('service_topics')
            ->where('id', $topic)
            ->whereIn('category_id', DB::table('service_categories')->where('centre_id', $centre->id)->select('id'))
            ->update([...$data, 'updated_at' => now()]);
        abort_unless($updated, 404);
        Audit::record('تغییر تعرفه و مدت جلسه', $request, 'info', ['centre_id' => $centre->id, 'topic_id' => $topic]);

        return back()->with('success', 'تعرفه و مدت جلسه ویرایش شد.');
    }

    public function assign(Request $request, Centre $centre)
    {
        $this->scope($request, $centre, 'counselors.manage');
        $data = $request->validate(['user_id' => 'required|integer', 'topic_ids' => 'array', 'topic_ids.*' => 'integer|distinct']);
        $user = $this->counselor($centre, (int) $data['user_id']);
        $ids = array_map('intval', $data['topic_ids'] ?? []);
        $found = DB::table('service_topics as t')
            ->join('service_categories as c', 'c.id', '=', 't.category_id')
            ->where('c.centre_id', $centre->id)
            ->whereIn('t.id', $ids)
            ->count();
        abort_unless($found === count($ids), 403);

        DB::transaction(function () use ($centre, $user, $ids) {
            $centreTopicIds = DB::table('service_topics as t')
                ->join('service_categories as c', 'c.id', '=', 't.category_id')
                ->where('c.centre_id', $centre->id)
                ->pluck('t.id');
            DB::table('counselor_topics')->where('user_id', $user->id)->whereIn('topic_id', $centreTopicIds)->delete();
            foreach ($ids as $id) {
                DB::table('counselor_topics')->insert(['user_id' => $user->id, 'topic_id' => $id]);
            }
        });

        return back()->with('success', 'موضوعات مشاور ثبت شد.');
    }

    public function shift(Request $request, Centre $centre)
    {
        $this->scope($request, $centre, 'schedules.manage');
        $data = $request->validate([
            'user_id' => 'required|integer',
            'weekday' => 'required|integer|between:0,6',
            'slots' => 'required|array|min:1|max:32',
            'slots.*' => ['required', 'string', 'distinct', 'regex:/^(?:[01]\d|2[0-3]):(?:00|30)$/'],
            'hourly_pay' => 'required|integer|min:0',
        ]);
        $user = $this->counselor($centre, (int) $data['user_id']);
        $ranges = ShiftSlotNormalizer::normalize($data['slots']);

        DB::transaction(function () use ($request, $centre, $user, $data, $ranges) {
            DB::table('users')->where('id', $user->id)->lockForUpdate()->first();

            foreach ($ranges as $range) {
                $conflicts = DB::table('counselor_shifts as s')
                    ->join('centres as c', 'c.id', '=', 's.centre_id')
                    ->where('s.user_id', $user->id)
                    ->where('s.weekday', $data['weekday'])
                    ->where('s.starts_at', '<', $range['ends_at'])
                    ->where('s.ends_at', '>', $range['starts_at'])
                    ->pluck('c.name')
                    ->unique()
                    ->all();

                if ($conflicts) {
                    throw ValidationException::withMessages([
                        'slots' => 'تداخل با برنامه مرکز: '.implode('، ', $conflicts).'؛ زمان دیگری انتخاب کنید یا ابتدا شیفت قبلی را حذف کنید.',
                    ]);
                }
            }

            foreach ($ranges as $range) {
                $shift = [
                    'user_id' => $user->id,
                    'weekday' => $data['weekday'],
                    'starts_at' => $range['starts_at'],
                    'ends_at' => $range['ends_at'],
                    'hourly_pay' => $data['hourly_pay'],
                ];
                $id = DB::table('counselor_shifts')->insertGetId([
                    ...$shift,
                    'centre_id' => $centre->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('shift_change_logs')->insert([
                    'shift_id' => $id,
                    'actor_id' => $request->user()->id,
                    'user_id' => $user->id,
                    'centre_id' => $centre->id,
                    'after' => json_encode($shift),
                    'created_at' => now(),
                ]);
            }
        });

        return back()->with('success', count($ranges).' بازه کاری بدون اتصال زمان‌های غیرمتوالی ثبت شد.');
    }

    public function removeShift(Request $request, Centre $centre, int $shift)
    {
        $this->scope($request, $centre, 'schedules.manage');
        DB::transaction(function () use ($request, $centre, $shift) {
            $record = DB::table('counselor_shifts')->where('centre_id', $centre->id)->where('id', $shift)->lockForUpdate()->first();
            abort_unless($record, 404);
            DB::table('shift_change_logs')->insert([
                'actor_id' => $request->user()->id,
                'user_id' => $record->user_id,
                'centre_id' => $centre->id,
                'before' => json_encode($record),
                'created_at' => now(),
            ]);
            DB::table('counselor_shifts')->where('id', $shift)->delete();
        });

        return back()->with('success', 'شیفت حذف شد.');
    }

    public function closure(Request $request, Centre $centre)
    {
        $this->scope($request, $centre, 'schedules.manage');
        $data = $request->validate(['starts_on' => 'required|date', 'ends_on' => 'required|date|after_or_equal:starts_on', 'reason' => 'required|string|max:200']);
        DB::table('centre_closures')->insert([...$data, 'centre_id' => $centre->id, 'created_at' => now(), 'updated_at' => now()]);

        return back()->with('success', 'تعطیلی ثبت شد.');
    }

    public function leave(Request $request, Centre $centre)
    {
        $this->scope($request, $centre, 'schedules.manage');
        $data = $request->validate(['user_id' => 'required|integer', 'starts_at' => 'required|date', 'ends_at' => 'required|date|after:starts_at', 'reason' => 'nullable|string|max:200']);
        $this->counselor($centre, (int) $data['user_id']);
        DB::table('counselor_leaves')->insert([...$data, 'centre_id' => $centre->id, 'created_at' => now(), 'updated_at' => now()]);

        return back()->with('success', 'مرخصی ثبت شد.');
    }

    public function room(Request $request, Centre $centre)
    {
        $this->scope($request, $centre, 'schedules.manage');
        $data = $request->validate(['name' => 'required|string|max:120', 'priority_user_id' => 'nullable|integer', 'topic_ids' => 'array', 'topic_ids.*' => 'integer']);
        $id = DB::table('centre_rooms')->insertGetId(['centre_id' => $centre->id, 'name' => $data['name'], 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->saveRoomPreferences($centre, $id, $data);

        return back()->with('success', 'اتاق ثبت شد.');
    }

    public function updateRoom(Request $request, Centre $centre, int $room)
    {
        $this->scope($request, $centre, 'schedules.manage');
        $data = $request->validate(['priority_user_id' => 'nullable|integer', 'topic_ids' => 'array', 'topic_ids.*' => 'integer']);
        abort_unless(DB::table('centre_rooms')->where('id', $room)->where('centre_id', $centre->id)->exists(), 404);
        $this->saveRoomPreferences($centre, $room, $data);

        return back()->with('success', 'تنظیمات اتاق ذخیره شد.');
    }

    private function saveRoomPreferences(Centre $centre, int $room, array $data): void
    {
        DB::transaction(function () use ($centre, $room, $data) {
            DB::table('room_counselors')->where('room_id', $room)->delete();
            DB::table('room_topics')->where('room_id', $room)->delete();

            if (! empty($data['priority_user_id'])) {
                $valid = User::whereKey($data['priority_user_id'])
                    ->whereHas('roleAssignments', fn ($query) => $query
                        ->where('centre_id', $centre->id)
                        ->whereHas('role', fn ($role) => $role->where('slug', 'counselor')))
                    ->exists();
                if ($valid) {
                    DB::table('room_counselors')->insert(['room_id' => $room, 'user_id' => $data['priority_user_id'], 'priority' => 0]);
                }
            }

            foreach (array_unique(array_map('intval', $data['topic_ids'] ?? [])) as $topic) {
                $valid = DB::table('service_topics as t')
                    ->join('service_categories as c', 'c.id', '=', 't.category_id')
                    ->where('c.centre_id', $centre->id)
                    ->where('t.id', $topic)
                    ->exists();
                if ($valid) {
                    DB::table('room_topics')->insert(['room_id' => $room, 'topic_id' => $topic]);
                }
            }
        });
    }

    public function topicsPage(Request $request, Centre $centre)
    {
        $this->scope($request, $centre, 'fees.view');
        $categories = DB::table('service_categories')->where('centre_id', $centre->id)->orderBy('name')->get();
        $topics = DB::table('service_topics as t')
            ->join('service_categories as c', 'c.id', '=', 't.category_id')
            ->where('c.centre_id', $centre->id)
            ->select('t.*', 'c.name as category_name')
            ->orderBy('c.name')
            ->orderBy('t.name')
            ->get();
        $counselors = User::whereHas('roleAssignments', fn ($query) => $query
            ->where('centre_id', $centre->id)
            ->whereHas('role', fn ($role) => $role->where('slug', 'counselor')))
            ->orderBy('last_name')
            ->get();
        $assignments = DB::table('counselor_topics')
            ->whereIn('topic_id', $topics->pluck('id'))
            ->get()
            ->groupBy('user_id')
            ->map(fn ($items) => $items->pluck('topic_id')->all());

        return view('centres.topics', compact('centre', 'categories', 'topics', 'counselors', 'assignments'));
    }

    public function workHours(Request $request, Centre $centre)
    {
        $this->scope($request, $centre, 'schedules.view');
        $counselors = User::whereHas('roleAssignments', fn ($query) => $query
            ->where('centre_id', $centre->id)
            ->whereHas('role', fn ($role) => $role->where('slug', 'counselor')))
            ->orderBy('last_name')
            ->get();
        $shifts = DB::table('counselor_shifts')->where('centre_id', $centre->id)->get()->groupBy('user_id');

        return view('centres.work-hours', compact('centre', 'counselors', 'shifts'));
    }

    public function holidays(Request $request, Centre $centre)
    {
        $this->scope($request, $centre, 'schedules.view');
        $closures = DB::table('centre_closures')->where('centre_id', $centre->id)->orderByDesc('starts_on')->get();
        $officialYear = DB::table('official_holidays')->where('is_active', true)->max('jalali_year');
        $official = DB::table('official_holidays')
            ->where('is_active', true)
            ->when($officialYear, fn ($query) => $query->where('jalali_year', $officialYear))
            ->orderBy('jalali_date')
            ->get();

        return view('centres.holidays', compact('centre', 'closures', 'official', 'officialYear'));
    }

    public function leavesPage(Request $request, Centre $centre)
    {
        $this->scope($request, $centre, 'schedules.view');
        $counselors = User::whereHas('roleAssignments', fn ($query) => $query
            ->where('centre_id', $centre->id)
            ->whereHas('role', fn ($role) => $role->where('slug', 'counselor')))
            ->orderBy('last_name')
            ->get();
        $leaves = DB::table('counselor_leaves as l')
            ->join('users as u', 'u.id', '=', 'l.user_id')
            ->where('l.centre_id', $centre->id)
            ->select('l.*', 'u.first_name', 'u.last_name')
            ->orderByDesc('l.starts_at')
            ->get();

        return view('centres.leaves', compact('centre', 'counselors', 'leaves'));
    }

    public function roomsPage(Request $request, Centre $centre)
    {
        $this->scope($request, $centre, 'schedules.view');
        $rooms = DB::table('centre_rooms')->where('centre_id', $centre->id)->orderBy('name')->get();
        foreach ($rooms as $room) {
            $room->priority_user_id = DB::table('room_counselors')->where('room_id', $room->id)->value('user_id');
            $room->topic_ids = DB::table('room_topics')->where('room_id', $room->id)->pluck('topic_id')->all();
        }
        $topics = DB::table('service_topics as t')
            ->join('service_categories as c', 'c.id', '=', 't.category_id')
            ->where('c.centre_id', $centre->id)
            ->select('t.*', 'c.name as category_name')
            ->orderBy('c.name')
            ->get();
        $counselors = User::whereHas('roleAssignments', fn ($query) => $query
            ->where('centre_id', $centre->id)
            ->whereHas('role', fn ($role) => $role->where('slug', 'counselor')))
            ->orderBy('last_name')
            ->get();

        return view('centres.rooms', compact('centre', 'rooms', 'topics', 'counselors'));
    }
}
