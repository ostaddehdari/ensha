<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\CentreBranch;
use App\Models\Client;
use App\Models\ServiceTopic;
use App\Models\User;
use App\Services\AppointmentAvailabilityService;
use App\Services\AppointmentBookingService;
use App\Services\AppointmentPricingService;
use App\Services\AppointmentRescheduleService;
use App\Services\AppointmentResourceLockService;
use App\Services\BookingPolicy;
use App\Services\JalaliDate;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SecretaryCalendarController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->hasPermission('appointments.view'), 403);
        $centreId = (int) $request->user()->centre_id;
        $counselors = $this->counselors($centreId);
        $branches = CentreBranch::where('centre_id', $centreId)->where('is_active', true)->orderByDesc('is_default')->orderBy('name')->get();
        $defaultBranchId = $branches->firstWhere('is_default', true)?->id ?? $branches->first()?->id;
        $calendarTimezone = $request->user()->centre?->timezone ?: config('app.timezone');
        $topics = ServiceTopic::whereHas('category', fn ($q) => $q->where('centre_id', $centreId))->where('is_active', true)->orderBy('name')->get(['id', 'name', 'color']);
        $statuses = DB::table('appointment_statuses')->where('centre_id', $centreId)->where('is_active', true)->orderBy('sort_order')->get();
        $discounts = DB::table('discounts')->where('centre_id', $centreId)->where('is_active', true)->get();

        return view('appointments.calendar', compact('counselors', 'topics', 'statuses', 'discounts', 'branches', 'defaultBranchId', 'calendarTimezone'));
    }

    public function resources(Request $request)
    {
        abort_unless($request->user()->hasPermission('appointments.view'), 403);
        $centreId = (int) $request->user()->centre_id;
        $branch = $this->branch($centreId, $request->integer('branch_id') ?: null);
        $timezone = $branch?->effectiveTimezone() ?: ($request->user()->centre?->timezone ?: config('app.timezone'));
        $counselors = $this->counselors($centreId, $branch?->id);
        $date = Carbon::parse($request->input('date', 'now'), $timezone);

        return response()->json($counselors->map(function (User $user) use ($date, $request, $branch, $timezone) {
            $shift = DB::table('counselor_shifts')->where('centre_id', $request->user()->centre_id)->where('user_id', $user->id)
                ->when($branch, fn ($q) => $q->where(fn ($b) => $b->whereNull('branch_id')->orWhere('branch_id', $branch->id)))
                ->where('weekday', $date->dayOfWeek)->where('is_active', true)->orderBy('starts_at')->get();

            return ['id' => $user->id, 'name' => $user->display_name, 'avatar' => $user->avatar_url,
                'hours' => $shift->map(fn ($s) => substr($s->starts_at, 0, 5).'–'.substr($s->ends_at, 0, 5))->join('، '),
                'centre' => $request->user()->centre?->name, 'branch' => $branch?->name, 'timezone' => $timezone];
        })->values());
    }

    public function events(Request $request)
    {
        abort_unless($request->user()->hasPermission('appointments.view'), 403);
        $data = $request->validate([
            'start' => 'required|date', 'end' => 'required|date|after:start', 'counselor_id' => 'nullable|integer',
            'topic_id' => 'nullable|integer', 'status' => 'nullable|string|max:30', 'branch_id' => 'nullable|integer', 'mode' => 'nullable|in:in_person,phone,video',
        ]);
        $centreId = (int) $request->user()->centre_id;
        $branch = $this->branch($centreId, isset($data['branch_id']) ? (int) $data['branch_id'] : null);
        $timezone = $branch?->effectiveTimezone() ?: ($request->user()->centre?->timezone ?: config('app.timezone'));
        $rangeStart = Carbon::parse($data['start'], $timezone)->setTimezone(config('app.timezone'));
        $rangeEnd = Carbon::parse($data['end'], $timezone)->setTimezone(config('app.timezone'));
        $events = Appointment::query()->visibleTo($request->user())->with(['client.user', 'topic', 'counselor', 'slot.room', 'branch'])
            ->where('starts_at', '<', $rangeEnd)->where('ends_at', '>', $rangeStart)
            ->when($data['counselor_id'] ?? null, fn ($q, $id) => $q->where('counselor_id', $id))
            ->when($data['topic_id'] ?? null, fn ($q, $id) => $q->where('topic_id', $id))
            ->when($data['branch_id'] ?? null, fn ($q, $id) => $q->where('branch_id', $id))
            ->when($data['mode'] ?? null, fn ($q, $mode) => $q->where('mode', $mode))
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))->get();

        return response()->json($events->map(fn (Appointment $a) => $this->event($a, $timezone))->values());
    }

    public function availableSlots(Request $request)
    {
        abort_unless($request->user()->hasPermission('appointments.manage'), 403);
        $data = $request->validate(['start' => 'required|date', 'end' => 'required|date|after:start', 'counselor_id' => 'nullable|integer', 'topic_id' => 'nullable|integer', 'branch_id' => 'nullable|integer']);
        $branch = $this->branch((int) $request->user()->centre_id, isset($data['branch_id']) ? (int) $data['branch_id'] : null);
        $timezone = $branch?->effectiveTimezone() ?: ($request->user()->centre?->timezone ?: config('app.timezone'));
        $rangeStart = Carbon::parse($data['start'], $timezone)->setTimezone(config('app.timezone'));
        $rangeEnd = Carbon::parse($data['end'], $timezone)->setTimezone(config('app.timezone'));
        $slots = AppointmentSlot::with(['topic', 'counselor', 'room'])->where('centre_id', $request->user()->centre_id)
            ->where('status', 'available')->where('starts_at', '>=', $rangeStart)->where('starts_at', '<', $rangeEnd)
            ->when($data['counselor_id'] ?? null, fn ($q, $id) => $q->where('counselor_id', $id))
            ->when($data['topic_id'] ?? null, fn ($q, $id) => $q->where('topic_id', $id))
            ->when($branch, fn ($q) => $q->where('branch_id', $branch->id))->orderBy('starts_at')->limit(150)->get();

        return response()->json($slots->filter(function ($slot) {
            try {
                app(AppointmentAvailabilityService::class)->assertBookable($slot, null);
            } catch (ValidationException $e) {
                return false;
            }

            return true;
        })->map(fn (AppointmentSlot $slot) => [
            'id' => $slot->id, 'start' => $slot->starts_at->toIso8601String(), 'end' => $slot->ends_at->toIso8601String(),
            'counselor_id' => $slot->counselor_id, 'topic_id' => $slot->topic_id,
            'label' => $slot->starts_at->copy()->timezone($timezone)->format('Y-m-d H:i').' — '.$slot->counselor->display_name.' / '.$slot->topic->name,
        ])->values());
    }

    public function quote(Request $request, AppointmentPricingService $pricing)
    {
        abort_unless($request->user()->hasPermission('appointments.manage'), 403);
        $data = $request->validate(['slot_id' => 'required|integer', 'discount_id' => 'nullable|integer']);
        $slot = AppointmentSlot::with('topic')->where('centre_id', $request->user()->centre_id)->findOrFail($data['slot_id']);

        return response()->json($pricing->quote($slot, $data['discount_id'] ?? null, 0));
    }

    public function store(
        Request $request,
        AppointmentBookingService $booking,
        AppointmentAvailabilityService $availability,
        AppointmentResourceLockService $resourceLocks,
    ) {
        abort_unless($request->user()->hasPermission('appointments.manage'), 403);
        $data = $request->validate([
            'appointment_date' => 'required|date_format:Y-m-d', 'start_time' => 'required|date_format:H:i',
            'duration_minutes' => 'required|integer|min:15|max:240',
            'counselor_id' => 'required|integer|exists:users,id', 'topic_id' => 'required|integer|exists:service_topics,id',
            'mode' => 'required|in:in_person,video,phone', 'client_id' => 'nullable|integer|exists:clients,id',
            'first_name' => 'required_without:client_id|nullable|string|max:100',
            'last_name' => 'required_without:client_id|nullable|string|max:100',
            'phone' => 'nullable|string|max:20', 'national_id' => 'nullable|string|max:20',
            'case_id' => 'nullable|integer|exists:cases,id', 'notes' => 'nullable|string|max:2000',
            'discount_id' => 'nullable|integer', 'branch_id' => 'nullable|integer|exists:centre_branches,id',
        ]);
        $centreId = (int) $request->user()->centre_id;
        $branch = $this->branch($centreId, ! empty($data['branch_id']) ? (int) $data['branch_id'] : $this->defaultBranchId($centreId));
        if (! $branch) {
            throw ValidationException::withMessages(['branch_id' => 'برای ثبت نوبت، ابتدا یک شعبه فعال بسازید.']);
        }
        $client = ! empty($data['client_id']) ? Client::where('centre_id', $centreId)->where('status', 'active')->whereNull('merged_into_id')->findOrFail($data['client_id']) : null;
        if (! $client) {
            abort_unless($request->user()->hasPermission('clients.manage'), 403);
        }
        abort_unless($this->counselors($centreId, $branch->id)->contains('id', (int) $data['counselor_id']), 403);
        $topic = ServiceTopic::whereHas('category', fn ($q) => $q->where('centre_id', $centreId))
            ->where('is_active', true)->findOrFail($data['topic_id']);
        if (! empty($data['case_id']) && ! $client) {
            throw ValidationException::withMessages(['case_id' => 'پرونده برای مراجع جدید قابل انتخاب نیست.']);
        }
        if (! empty($data['case_id'])) {
            abort_unless($client->cases()->whereKey($data['case_id'])->exists(), 422);
        }
        $start = Carbon::createFromFormat('!Y-m-d H:i', $data['appointment_date'].' '.$data['start_time'], $branch->effectiveTimezone())
            ->setTimezone(config('app.timezone'));
        $end = $start->copy()->addMinutes((int) $data['duration_minutes']);
        if ($end->copy()->timezone($branch->effectiveTimezone())->toDateString() !== $start->copy()->timezone($branch->effectiveTimezone())->toDateString()) {
            throw ValidationException::withMessages(['start_time' => 'زمان پایان باید در همان روز باشد.']);
        }
        $policy = BookingPolicy::forCentre($centreId);
        if ($start->isPast() && ! $policy->allow_past_bookings) {
            throw ValidationException::withMessages(['start_time' => 'ثبت نوبت در گذشته برای این مرکز غیرفعال است.']);
        }
        try {
            $usesRooms = $policy->check_rooms && $data['mode'] === 'in_person' && $topic->requires_room;
            $keys = $resourceLocks->keys($centreId, [(int) $data['counselor_id']], [], $usesRooms);
            $appointment = $resourceLocks->block($keys, function () use (
                $data, $booking, $availability, $request, $centreId, $client, $topic, $start, $end, $usesRooms, $branch,
            ) {
                return DB::transaction(function () use (
                    $data, $booking, $availability, $request, $centreId, $client, $topic, $start, $end, $usesRooms, $branch,
                ) {
                    DB::table('users')->where('id', $data['counselor_id'])->lockForUpdate()->first();
                    if ($usesRooms) {
                        DB::table('centre_rooms')->where('centre_id', $centreId)->orderBy('id')->lockForUpdate()->get();
                    }
                    $client ??= $this->createClientForBooking($data, $request, $centreId);
                    $existing = AppointmentSlot::where('centre_id', $centreId)
                        ->where('counselor_id', $data['counselor_id'])
                        ->where('topic_id', $topic->id)->where('starts_at', $start)
                        ->where('mode', $data['mode'])->lockForUpdate()->first();
                    if ($existing) {
                        if ((int) $existing->branch_id !== (int) $branch->id) {
                            throw ValidationException::withMessages(['branch_id' => 'مشاور در این زمان در شعبه دیگری برنامه دارد.']);
                        }
                        if (! $existing->ends_at->equalTo($end) || $existing->status !== 'available') {
                            throw ValidationException::withMessages(['start_time' => 'این زمان قبلاً ثبت شده است.']);
                        }
                        $slot = $existing;
                    } else {
                        $slot = new AppointmentSlot([
                            'centre_id' => $centreId, 'branch_id' => $branch->id, 'topic_id' => $topic->id, 'counselor_id' => $data['counselor_id'],
                            'room_id' => null, 'slot_date' => $start->copy()->timezone($branch->effectiveTimezone())->toDateString(), 'starts_at' => $start,
                            'ends_at' => $end, 'mode' => $data['mode'], 'capacity' => max(1, (int) $topic->capacity), 'booked_count' => 0,
                            'status' => 'available', 'source' => 'secretary_manual',
                        ]);
                        $slot->setRelation('topic', $topic);
                        if ($usesRooms) {
                            $slot->room_id = $availability->assignAvailableRoom($slot);
                        }
                        $availability->assertBookable($slot, $client->id, null, true);
                        $slot->save();
                    }

                    return $booking->book($slot->id, $client->id, $data['case_id'] ?? null, $request->user()->id,
                        $data['notes'] ?? null, [
                            'source' => 'secretary',
                            'discount_id' => $data['discount_id'] ?? null,
                            '_resource_locks_held' => true,
                        ]);
                });
            });

            return response()->json($this->event($appointment->load(['client.user', 'topic', 'counselor', 'slot.room', 'branch']), $branch->effectiveTimezone()), 201);
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 409);
        }
    }

    private function createClientForBooking(array $data, Request $request, int $centreId): Client
    {
        $phone = trim((string) ($data['phone'] ?? ''));
        $nationalId = trim((string) ($data['national_id'] ?? ''));
        if ($phone || $nationalId) {
            $duplicate = User::where(function ($query) use ($phone, $nationalId) {
                if ($phone) {
                    $query->where('phone', $phone);
                }
                if ($nationalId && $phone) {
                    $query->orWhere('national_id', $nationalId);
                } elseif ($nationalId) {
                    $query->where('national_id', $nationalId);
                }
            })->exists();
            if ($duplicate) {
                throw ValidationException::withMessages(['client_id' => 'این شماره تماس یا کد ملی قبلاً ثبت شده است؛ مراجع را جستجو و انتخاب کنید.']);
            }
        }
        $roleId = DB::table('roles')->where('slug', 'client')->value('id');
        $user = User::create([
            'first_name' => trim($data['first_name']), 'last_name' => trim($data['last_name']),
            'name' => trim($data['first_name'].' '.$data['last_name']),
            'phone' => $phone ?: 'TMP'.Str::upper(Str::random(16)),
            'national_id' => $nationalId ?: null, 'password' => Str::random(48),
            'role' => 'client', 'role_id' => $roleId, 'centre_id' => $centreId,
            'is_active' => false, 'status' => 'inactive', 'must_change_password' => true,
            'created_by' => $request->user()->id,
        ]);
        if ($roleId) {
            DB::table('user_role_centres')->insert([
                'user_id' => $user->id, 'role_id' => $roleId, 'centre_id' => $centreId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return Client::create([
            'user_id' => $user->id, 'centre_id' => $centreId,
            'client_code' => 'CL-'.Str::upper(Str::random(12)),
            'status' => 'active', 'profile_state' => 'minimal', 'created_by' => $request->user()->id,
        ]);
    }

    public function updateEvent(Request $request, Appointment $appointment)
    {
        abort_unless($request->user()->hasPermission('appointments.manage'), 403);
        abort_unless(Appointment::visibleTo($request->user())->whereKey($appointment)->exists(), 403);
        $data = $request->validate(['start' => 'required|date', 'end' => 'required|date|after:start', 'counselor_id' => 'required|integer|exists:users,id', 'branch_id' => 'nullable|integer|exists:centre_branches,id']);
        if (in_array($appointment->status, ['cancelled', 'completed', 'no_show'], true)) {
            return response()->json(['message' => 'نوبت نهایی‌شده قابل جابه‌جایی نیست.'], 409);
        }
        try {
            $centreId = (int) $appointment->centre_id;
            $branchId = ! empty($data['branch_id']) ? (int) $data['branch_id'] : ((int) $appointment->branch_id ?: null);
            $branch = $this->branch($centreId, $branchId);
            $timezone = $branch?->effectiveTimezone() ?: ($request->user()->centre?->timezone ?: config('app.timezone'));
            abort_unless($this->counselors($centreId, $branch?->id)->contains('id', (int) $data['counselor_id']), 403);
            $start = Carbon::parse($data['start'], $timezone)->setTimezone(config('app.timezone'));
            $end = Carbon::parse($data['end'], $timezone)->setTimezone(config('app.timezone'));
            $updated = app(AppointmentRescheduleService::class)->moveToRange(
                $appointment, (int) $data['counselor_id'], $branch?->id, $start, $end, $request->user()->id, true,
            );

            return response()->json($this->event($updated->load(['client.user', 'topic', 'counselor', 'slot.room', 'branch']), $timezone));
        } catch (ValidationException $e) {
            return response()->json(['message' => 'تداخل زمان', 'errors' => $e->errors()], 409);
        }
    }

    private function counselors(int $centreId, ?int $branchId = null)
    {
        return User::whereHas('roleAssignments', fn ($q) => $q->where('centre_id', $centreId)
            ->when($branchId, fn ($r) => $r->where(fn ($b) => $b->whereNull('branch_id')->orWhere('branch_id', $branchId)))
            ->whereHas('role', fn ($r) => $r->where('slug', 'counselor')))->where('status', 'active')->orderBy('last_name')->get(['id', 'first_name', 'last_name', 'phone']);
    }

    private function branch(int $centreId, ?int $branchId): ?CentreBranch
    {
        if (! $branchId) {
            return null;
        }

        return CentreBranch::whereKey($branchId)->where('centre_id', $centreId)->where('is_active', true)->firstOrFail();
    }

    private function defaultBranchId(int $centreId): ?int
    {
        return CentreBranch::where('centre_id', $centreId)->where('is_active', true)->orderByDesc('is_default')->value('id');
    }

    private function event(Appointment $a, ?string $displayTimezone = null): array
    {
        $timezone = $displayTimezone ?: $a->branch?->effectiveTimezone() ?: config('app.timezone');
        $start = $a->starts_at->copy()->timezone($timezone);
        $end = $a->ends_at->copy()->timezone($timezone);

        return ['id' => $a->id, 'resourceId' => $a->counselor_id, 'start' => $start->format('Y-m-d\\TH:i:s'), 'end' => $end->format('Y-m-d\\TH:i:s'), 'status' => $a->status,
            'title' => trim(($a->client?->user?->display_name ?? 'مراجع').' · '.($a->topic?->name ?? 'خدمت')), 'client' => $a->client?->user?->display_name, 'topic' => $a->topic?->name,
            'counselor' => $a->counselor?->display_name, 'counselorId' => $a->counselor_id, 'room' => $a->slot?->room?->name, 'mode' => $a->mode,
            'branchId' => $a->branch_id, 'branch' => $a->branch?->name, 'timezone' => $timezone,
            'jalaliDate' => app(JalaliDate::class)->format($start), 'durationMinutes' => $start->diffInMinutes($end),
            'color' => $a->topic_color_snapshot ?: ($a->topic?->color ?: '#2563eb'),
            'statusColor' => DB::table('appointment_statuses')->where('id', $a->status_id)->value('indicator_color') ?: '#ffffff',
            'url' => route('appointments.show', $a)];
    }
}
