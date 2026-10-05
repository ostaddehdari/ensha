<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\Client;
use App\Models\ServiceTopic;
use App\Models\User;
use App\Services\AppointmentBookingService;
use App\Services\AppointmentAvailabilityService;
use App\Services\AppointmentPricingService;
use App\Services\AppointmentRescheduleService;
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
        $statuses = DB::table('appointment_statuses')->where('centre_id',$centreId)->where('is_active',true)->orderBy('sort_order')->get();
        $discounts = DB::table('discounts')->where('centre_id',$centreId)->where('is_active',true)->get();
        return view('appointments.calendar', compact('counselors', 'topics', 'statuses', 'discounts'));
    }

    public function resources(Request $request)
    {
        abort_unless($request->user()->hasPermission('appointments.view'), 403);
        $counselors = $this->counselors((int) $request->user()->centre_id);
        $date = $request->date('date') ?? now();
        return response()->json($counselors->map(function (User $user) use ($date,$request) {
            $shift = DB::table('counselor_shifts')->where('centre_id',$request->user()->centre_id)->where('user_id',$user->id)
                ->where('weekday',$date->dayOfWeek)->where('is_active',true)->orderBy('starts_at')->get();
            return ['id'=>$user->id,'name'=>$user->display_name,'avatar'=>$user->avatar_url,
                'hours'=>$shift->map(fn ($s) => substr($s->starts_at,0,5).'–'.substr($s->ends_at,0,5))->join('، '),
                'centre'=>$request->user()->centre?->name];
        })->values());
    }

    public function events(Request $request)
    {
        abort_unless($request->user()->hasPermission('appointments.view'), 403);
        $data = $request->validate([
            'start' => 'required|date', 'end' => 'required|date|after:start', 'counselor_id' => 'nullable|integer',
            'topic_id' => 'nullable|integer', 'status' => 'nullable|string|max:30', 'branch_id' => 'nullable|integer', 'mode'=>'nullable|in:in_person,phone,video',
        ]);
        $events = Appointment::query()->visibleTo($request->user())->with(['client.user', 'topic', 'counselor', 'slot.room'])
            ->where('starts_at', '<', Carbon::parse($data['end']))->where('ends_at', '>', Carbon::parse($data['start']))
            ->when($data['counselor_id'] ?? null, fn ($q, $id) => $q->where('counselor_id', $id))
            ->when($data['topic_id'] ?? null, fn ($q, $id) => $q->where('topic_id', $id))
            ->when($data['branch_id'] ?? null, fn ($q, $id) => $q->where('branch_id', $id))
            ->when($data['mode'] ?? null, fn ($q, $mode) => $q->where('mode',$mode))
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
        return response()->json($slots->filter(function ($slot) {
            try { app(AppointmentAvailabilityService::class)->assertBookable($slot, null); }
            catch (ValidationException $e) { return false; }
            return true;
        })->map(fn (AppointmentSlot $slot) => [
            'id' => $slot->id, 'start'=>$slot->starts_at->toIso8601String(),'end'=>$slot->ends_at->toIso8601String(),
            'counselor_id'=>$slot->counselor_id,'topic_id'=>$slot->topic_id,
            'label' => $slot->starts_at->format('Y-m-d H:i').' — '.$slot->counselor->display_name.' / '.$slot->topic->name,
        ])->values());
    }

    public function quote(Request $request, AppointmentPricingService $pricing)
    {
        abort_unless($request->user()->hasPermission('appointments.manage'),403);
        $data=$request->validate(['slot_id'=>'required|integer','discount_id'=>'nullable|integer','paid_amount'=>'nullable|integer|min:0']);
        $slot=AppointmentSlot::with('topic')->where('centre_id',$request->user()->centre_id)->findOrFail($data['slot_id']);
        return response()->json($pricing->quote($slot,$data['discount_id']??null,(int) ($data['paid_amount']??0)));
    }

    public function store(Request $request, AppointmentBookingService $booking)
    {
        abort_unless($request->user()->hasPermission('appointments.manage'), 403);
        $data = $request->validate(['slot_id' => 'required|integer|exists:appointment_slots,id', 'client_id' => 'required|integer|exists:clients,id', 'case_id' => 'nullable|integer|exists:cases,id', 'notes' => 'nullable|string|max:2000',
            'discount_id'=>'nullable|integer','paid_amount'=>'nullable|integer|min:0','payment_note'=>'nullable|string|max:2000']);
        $slot = AppointmentSlot::findOrFail($data['slot_id']); $client = Client::findOrFail($data['client_id']);
        abort_unless($request->user()->isSuperAdmin() || ($slot->centre_id === $request->user()->centre_id && $client->centre_id === $request->user()->centre_id), 403);
        if (! empty($data['case_id'])) abort_unless($client->cases()->whereKey($data['case_id'])->exists(),422);
        try {
            $appointment = $booking->book($slot->id, $client->id, $data['case_id'] ?? null, $request->user()->id, $data['notes'] ?? null,
                ['source'=>'secretary','discount_id'=>$data['discount_id']??null,'paid_amount'=>$data['paid_amount']??0,'payment_note'=>$data['payment_note']??null]);
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
            $target = AppointmentSlot::where('centre_id',$appointment->centre_id)->where('counselor_id',$data['counselor_id'])
                ->where('topic_id',$appointment->topic_id)->where('starts_at',Carbon::parse($data['start']))
                ->where('ends_at',Carbon::parse($data['end']))->first();
            if (! $target) throw ValidationException::withMessages(['start'=>'اسلات مقصد وجود ندارد.']);
            $updated=app(AppointmentRescheduleService::class)->move($appointment,$target,$request->user()->id);
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
            'counselor' => $a->counselor?->display_name, 'room' => $a->slot?->room?->name, 'mode' => $a->mode,
            'color' => $a->topic_color_snapshot ?: ($a->topic?->color ?: '#2563eb'),
            'statusColor'=>DB::table('appointment_statuses')->where('id',$a->status_id)->value('indicator_color') ?: '#ffffff',
            'url' => route('appointments.show', $a)];
    }
}
