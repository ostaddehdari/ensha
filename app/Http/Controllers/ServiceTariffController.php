<?php

namespace App\Http\Controllers;

use App\Models\CentreBranch;
use App\Models\ServiceTariff;
use App\Models\ServiceTopic;
use App\Models\User;
use App\Services\TariffVersionService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ServiceTariffController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->hasPermission('tariffs.view'), 403);
        $centreId = (int) $request->user()->centre_id;
        $tariffs = ServiceTariff::with(['topic', 'counselor', 'branch'])->where('centre_id', $centreId)->orderByDesc('valid_from')->paginate(30);
        $topics = ServiceTopic::whereHas('category', fn ($q) => $q->where('centre_id', $centreId))->where('is_active', true)->orderBy('name')->get();
        $counselors = User::whereHas('roleAssignments', fn ($q) => $q->where('centre_id', $centreId)->whereHas('role', fn ($r) => $r->where('slug', 'counselor')))->orderBy('last_name')->get();
        $branches = CentreBranch::where('centre_id', $centreId)->where('is_active', true)->get();
        return view('appointments.tariffs', compact('tariffs', 'topics', 'counselors', 'branches'));
    }

    public function store(Request $request, TariffVersionService $versions)
    {
        abort_unless($request->user()->hasPermission('tariffs.manage'), 403);
        $data = $request->validate(['branch_id' => 'nullable|integer|exists:centre_branches,id', 'topic_id' => 'required|integer|exists:service_topics,id', 'counselor_id' => 'nullable|integer|exists:users,id', 'price' => 'required|integer|min:0', 'counselor_pay' => 'required|integer|min:0', 'valid_from' => 'required|date']);
        $centreId = (int) $request->user()->centre_id;
        abort_unless(ServiceTopic::whereKey($data['topic_id'])->whereHas('category', fn ($q) => $q->where('centre_id', $centreId))->exists(), 403);
        if (! empty($data['branch_id'])) abort_unless(CentreBranch::whereKey($data['branch_id'])->where('centre_id', $centreId)->exists(), 403);
        if (! empty($data['counselor_id'])) abort_unless(User::whereKey($data['counselor_id'])->whereHas('roleAssignments', fn ($q) => $q->where('centre_id', $centreId))->exists(), 403);
        try {
            $versions->create($data + ['centre_id' => $centreId, 'currency' => 'IRR', 'created_by' => $request->user()->id]);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['valid_from' => $e->getMessage()]);
        }
        return back()->with('success', 'نسخه جدید تعرفه ثبت و نسخه قبلی بسته شد.');
    }
}
