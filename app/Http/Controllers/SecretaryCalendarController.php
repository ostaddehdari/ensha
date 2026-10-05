<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\Client;
use App\Models\ServiceTopic;
use App\Models\User;
use App\Services\AppointmentBookingService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SecretaryCalendarController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->hasPermission('appointments.view'), 403);
        $centreId = (int) $request->user()->centre_id;
        $counselors = $this->counselors($centreId);
        $topics = ServiceTopic::whereHas('category', fn ($q) => $q->where('centre_id', $centreId))->where('is_active', true)->orderBy('name')->get(['id', 'name', 'color']);
        $clients = Client::with('user')->where('centre_id', $centreId)->where('status', 'active')->orderBy('client_code')->get();
        return view('appointments.calendar', compact('counselors', 'topics', 'clients'));
    }

    public function resources(Request $request)
    {
        abort_unless($request->user()->hasPermission('appointments.view'), 403);
        $counselors = $this->counselors((int) $request->user()->centre_id);
        return response()->json($counselors->map(fn (User $user) => ['id' => $user->id, 'name' => $user->display_name, 'role' => 'مشاور'])->values());
    }

    public function events(Request $request)
    {
        abort_unless($request->user()->hasPermission('appointments.view'), 403);
        $data = $request->validate([
            'start' => 'required|date', 'end' => 'required|date|after:start', 'counselor_id' => 'nullable|integer',
            'topic_id' => 'nullable|integer', 'status' => 'nullable|string|max:30', 'branch_id' => 'nullable|integer',
        ]);
        $events = Appointment::query()->visibleTo($request->user())->with(['client.user', 'topic', 'counselor', 'slot.room'])
            ->where('starts_at', '<', Carbon::parse($data['end']))->where('ends_at', '>', Carbon::parse($data['start']))
            ->when($data['counselor_id'] ?? null, fn ($q, $id) => $q->where('counselor_id', $id))
            ->when($data['topic_id'] ?? null, fn ($q, $id) => $q->where('topic_id', $id))
            ->when($data['branch_id'] ?? null, fn ($q, $id) => $q->where('branch_id', $id))
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))->get();
        return response()->json($events->map(fn (Appointment $a) => $this->event($a))->values());
    }

    public function availableSlots(Request $request)
    {
        abort_unless($request->user()->hasPermission('appointments.manage'), 403);
        $data = $request->validate(['start' => 'required|date', 'end' => 'required|date|after:start', 'counselor_id' => 'nullable|integer', 'topic_id' => 'nullable|integer']);
        $slots = AppointmentSlot::with(['topic', 'counselor', 'room'])->where('centre_id', $request->user()->centre_id)
            ->where('status', 'available')->where('starts_at', '>=', Carbon::parse($data['start']))->where('starts_at', '<', Carbon::parse($data['end']))
            ->when($data['counselor_id'] ?? null, fn ($q, $id) => $q->where('counselor_id', $id))
            ->when($data['topic_id'] ?? null, fn ($q, $id) => $q->where('topic_id', $id))->orderBy('starts_at')->limit(150)->get();
        return response()->json($slots->map(fn (AppointmentSlot $slot) => [
            'id' => $slot->id, 'label' => $slot->starts_at->format('Y-m-d H:i').' — '.$slot->counselor->display_name.' / '.$slot->topic->name,
        ]));
    }

    public function store(Request $request, AppointmentBookingService $booking)
    {
        abort_unless($request->user()->hasPermission('appointments.manage'), 403);
        $data = $request->validate(['slot_id' => 'required|integer|exists:appointment_slots,id', 'client_id' => 'required|integer|exists:clients,id', 'case_id' => 'nullable|integer|exists:cases,id', 'notes' => 'nullable|string|max:2000']);
        $slot = AppointmentSlot::findOrFail($data['slot_id']); $client = Client::findOrFail($data['client_id']);
        abort_unless($request->user()->isSuperAdmin() || ($slot->centre_id === $request->user()->centre_id && $client->centre_id === $request->user()->centre_id), 403);
        try {
            $appointment = $booking->book($slot->id, $client->id, $data['case_id'] ?? null, $request->user()->id, $data['notes'] ?? null);
            return response()->json($this->event($appointment->load(['client.user', 'topic', 'counselor', 'slot.room'])), 201);
        } catch (ValidationException $e) { return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 409); }
    }

    public function updateEvent(Request $request, Appointment $appointment)
    {
        abort_unless($request->user()->hasPermission('appointments.manage'), 403);
        abort_unless(Appointment::visibleTo($request->user())->whereKey($appointment)->exists(), 403);
        $data = $request->validate(['start' => 'required|date', 'end' => 'required|date|after:start', 'counselor_id' => 'required|integer|exists:users,id']);
        if (in_array($appointment->status, ['cancelled', 'completed', 'no_show'], true)) return response()->json(['message' => 'نوبت نهایی‌شده قابل جابه‌جایی نیست.'], 409);
        try {
            $updated = DB::transaction(function () use ($appointment, $data) {
                $current = Appointment::lockForUpdate()->findOrFail($appointment->id);
                $target = AppointmentSlot::query()->lockForUpdate()->where('centre_id', $current->centre_id)->where('counselor_id', $data['counselor_id'])
                    ->where('topic_id', $current->topic_id)->where('starts_at', Carbon::parse($data['start']))->where('ends_at', Carbon::parse($data['end']))->first();
                if (! $target || $target->status !== 'available' || $target->booked_count >= $target->capacity) throw ValidationException::withMessages(['start' => 'زمان مقصد دیگر آزاد نیست.']);
                if ($target->id === $current->slot_id) return $current;
                $clash = Appointment::where('client_id', $current->client_id)->where('id', '!=', $current->id)->whereNotIn('status', ['cancelled'])
                    ->where('starts_at', '<', $target->ends_at)->where('ends_at', '>', $target->starts_at)->exists();
                if ($clash) throw ValidationException::withMessages(['start' => 'مراجع در زمان مقصد نوبت دیگری دارد.']);
                $old = AppointmentSlot::lockForUpdate()->findOrFail($current->slot_id);
                $old->update(['booked_count' => max(0, $old->booked_count - 1), 'status' => 'available', 'lock_version' => $old->lock_version + 1]);
                $target->update(['booked_count' => $target->booked_count + 1, 'status' => $target->booked_count + 1 >= $target->capacity ? 'full' : 'available', 'lock_version' => $target->lock_version + 1]);
                $current->update(['slot_id' => $target->id, 'counselor_id' => $target->counselor_id, 'room_id' => $target->room_id, 'branch_id' => $target->branch_id, 'mode' => $target->mode, 'starts_at' => $target->starts_at, 'ends_at' => $target->ends_at, 'seat_number' => $target->booked_count]);
                return $current->fresh();
            }, 3);
            return response()->json($this->event($updated->load(['client.user', 'topic', 'counselor', 'slot.room'])));
        } catch (ValidationException $e) { return response()->json(['message' => 'تداخل زمان', 'errors' => $e->errors()], 409); }
    }

    private function counselors(int $centreId)
    {
        return User::whereHas('roleAssignments', fn ($q) => $q->where('centre_id', $centreId)->whereHas('role', fn ($r) => $r->where('slug', 'counselor')))->where('status', 'active')->orderBy('last_name')->get(['id','first_name','last_name','phone']);
    }

    private function event(Appointment $a): array
    {
        return ['id' => $a->id, 'resourceId' => $a->counselor_id, 'start' => $a->starts_at->toIso8601String(), 'end' => $a->ends_at->toIso8601String(), 'status' => $a->status,
            'title' => trim(($a->client?->user?->display_name ?? 'مراجع').' · '.($a->topic?->name ?? 'خدمت')), 'client' => $a->client?->user?->display_name, 'topic' => $a->topic?->name,
            'counselor' => $a->counselor?->display_name, 'room' => $a->slot?->room?->name, 'mode' => $a->mode, 'color' => $a->topic?->color ?: '#2563eb', 'url' => route('appointments.show', $a)];
    }
}
