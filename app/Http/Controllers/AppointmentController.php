<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\Client;
use App\Services\AppointmentBookingService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AppointmentController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->hasPermission('appointments.view'), 403);
        $appointments = Appointment::query()->visibleTo($request->user())
            ->with(['client.user', 'topic', 'counselor', 'slot.room'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('starts_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('starts_at', '<=', $request->date('to')))
            ->orderBy('starts_at')->paginate(30)->withQueryString();
        return view('appointments.index', compact('appointments'));
    }

    public function create(Request $request)
    {
        abort_unless($request->user()->hasPermission('appointments.manage'), 403);
        $centreId = (int) $request->user()->centre_id;
        $slots = AppointmentSlot::with(['topic', 'counselor', 'room'])
            ->where('centre_id', $centreId)->where('status', 'available')->where('starts_at', '>', now())
            ->orderBy('starts_at')->limit(200)->get();
        $clients = Client::with('user')->where('centre_id', $centreId)->where('status', 'active')->orderBy('client_code')->get();
        return view('appointments.create', compact('slots', 'clients'));
    }

    public function store(Request $request, AppointmentBookingService $booking)
    {
        abort_unless($request->user()->hasPermission('appointments.manage'), 403);
        $data = $request->validate(['slot_id' => 'required|integer|exists:appointment_slots,id', 'client_id' => 'required|integer|exists:clients,id', 'case_id' => 'nullable|integer|exists:cases,id', 'notes' => 'nullable|string|max:2000']);
        $slot = AppointmentSlot::findOrFail($data['slot_id']);
        $client = Client::findOrFail($data['client_id']);
        abort_unless($request->user()->isSuperAdmin() || ($slot->centre_id === $request->user()->centre_id && $client->centre_id === $request->user()->centre_id), 403);
        if (! empty($data['case_id'])) abort_unless($client->cases()->whereKey($data['case_id'])->exists(), 422, 'پرونده به مراجع انتخاب‌شده تعلق ندارد.');
        $appointment = $booking->book((int) $data['slot_id'], (int) $data['client_id'], $data['case_id'] ?? null, $request->user()->id, $data['notes'] ?? null);
        return redirect()->route('appointments.show', $appointment)->with('success', 'نوبت با قفل هم‌زمانی ثبت شد.');
    }

    public function show(Request $request, Appointment $appointment)
    {
        abort_unless($request->user()->hasPermission('appointments.view'), 403);
        abort_unless(Appointment::visibleTo($request->user())->whereKey($appointment)->exists(), 403);
        $appointment->load(['client.user', 'case', 'topic', 'counselor', 'slot.room', 'tariff', 'histories.actor',
            'paymentTransactions' => fn ($query) => $query->with(['receipt', 'creator', 'parent'])->orderByDesc('occurred_at')]);
        $statuses = \Illuminate\Support\Facades\DB::table('appointment_statuses')->where('centre_id',$appointment->centre_id)
            ->where('is_active',true)->orderBy('sort_order')->get();
        $openRegister = \App\Models\CashRegisterSession::where('cashier_id', $request->user()->id)->where('status', 'open')->first();
        return view('appointments.show', compact('appointment','statuses','openRegister'));
    }

    public function transition(Request $request, Appointment $appointment, AppointmentBookingService $booking)
    {
        abort_unless($request->user()->hasPermission('appointments.status'), 403);
        abort_unless(Appointment::visibleTo($request->user())->whereKey($appointment)->exists(), 403);
        $data = $request->validate(['status' => 'required|string|max:24', 'reason' => 'nullable|string|max:1000']);
        abort_unless(in_array($data['status'],Appointment::STATUSES,true) || \Illuminate\Support\Facades\DB::table('appointment_statuses')
            ->where('centre_id',$appointment->centre_id)->where('slug',$data['status'])->where('is_active',true)->exists(),422);
        $booking->transition($appointment, $data['status'], $request->user()->id, $data['reason'] ?? null);
        return back()->with('success', 'وضعیت نوبت ثبت شد.');
    }
}
