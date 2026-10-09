<?php

namespace App\Http\Controllers;

use App\Models\CounselorSettlement;
use App\Models\User;
use App\Services\CompensationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CounselorSettlementController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->hasPermission('settlements.view'), 403);
        $base = CounselorSettlement::where('centre_id', $request->user()->centre_id);
        if ($request->user()->assignedRole?->slug === 'counselor') $base->where('counselor_id', $request->user()->id);
        $summary = [
            'draft' => (clone $base)->where('status', 'draft')->count(),
            'approved_payable' => (clone $base)->where('status', 'approved')->sum('payable_amount'),
            'paid' => (clone $base)->where('status', 'paid')->sum('paid_amount'),
        ];
        $settlements = (clone $base)->with('counselor')->latest()->paginate(30);
        $counselors = collect();
        if ($request->user()->hasPermission('settlements.manage')) {
            $counselors = User::query()->join('user_role_centres as urc', 'urc.user_id', '=', 'users.id')
                ->join('roles as r', 'r.id', '=', 'urc.role_id')->where('urc.centre_id', $request->user()->centre_id)
                ->where('r.slug', 'counselor')->where('users.is_active', true)->select('users.*')->distinct()->orderBy('users.name')->get();
        }
        return view('finance.settlements.index', compact('settlements', 'counselors', 'summary'));
    }

    public function store(Request $request, CompensationService $service)
    {
        abort_unless($request->user()->hasPermission('settlements.manage'), 403);
        $data = $request->validate([
            'counselor_id' => 'required|integer', 'period_start' => 'required|date', 'period_end' => 'required|date|after_or_equal:period_start',
            'deduction_amount' => 'nullable|integer|min:0', 'deduction_title' => 'nullable|string|max:150',
            'bonus_amount' => 'nullable|integer|min:0', 'bonus_title' => 'nullable|string|max:150', 'note' => 'nullable|string|max:2000',
        ]);
        $adjustments = [];
        if (($data['deduction_amount'] ?? 0) > 0) $adjustments[] = ['kind'=>'deduction','title'=>$data['deduction_title'] ?: 'کسورات دوره','amount'=>$data['deduction_amount']];
        if (($data['bonus_amount'] ?? 0) > 0) $adjustments[] = ['kind'=>'bonus','title'=>$data['bonus_title'] ?: 'پاداش دوره','amount'=>$data['bonus_amount']];
        $settlement = $service->createSettlement((int) $request->user()->centre_id, $request->user(), (int) $data['counselor_id'], $data['period_start'], $data['period_end'], $adjustments, $data['note'] ?? null);
        return redirect()->route('settlements.show', $settlement)->with('success', 'پیش‌نویس تسویه ساخته شد.');
    }

    public function show(Request $request, CounselorSettlement $settlement)
    {
        $this->authorizeSettlement($request, $settlement, 'settlements.view');
        $settlement->load(['centre','counselor','creator','approver','payer','items.appointment.client.user','items.appointment.topic','adjustments']);
        return view('finance.settlements.show', compact('settlement'));
    }

    public function approve(Request $request, CounselorSettlement $settlement, CompensationService $service)
    {
        $this->authorizeSettlement($request, $settlement, 'settlements.approve');
        $service->approve($settlement, $request->user());
        return back()->with('success', 'تسویه تأیید شد و آماده پرداخت است.');
    }

    public function pay(Request $request, CounselorSettlement $settlement, CompensationService $service)
    {
        $this->authorizeSettlement($request, $settlement, 'settlements.pay');
        $data = $request->validate(['payment_reference'=>'required|string|max:120']);
        $service->markPaid($settlement, $request->user(), $data['payment_reference']);
        return back()->with('success', 'پرداخت تسویه ثبت شد.');
    }

    public function cancel(Request $request, CounselorSettlement $settlement, CompensationService $service)
    {
        $this->authorizeSettlement($request, $settlement, 'settlements.manage');
        $data = $request->validate(['reason'=>'required|string|max:2000']);
        $service->cancel($settlement, $request->user(), $data['reason']);
        return back()->with('success', 'پیش‌نویس تسویه لغو شد و اقلام آن دوباره قابل تسویه‌اند.');
    }

    private function authorizeSettlement(Request $request, CounselorSettlement $settlement, string $permission): void
    {
        abort_unless($request->user()->hasPermission($permission), 403);
        abort_unless($request->user()->isSuperAdmin() || (int) $request->user()->centre_id === (int) $settlement->centre_id, 403);
        if ($request->user()->assignedRole?->slug === 'counselor') abort_unless((int) $settlement->counselor_id === (int) $request->user()->id, 403);
    }
}
