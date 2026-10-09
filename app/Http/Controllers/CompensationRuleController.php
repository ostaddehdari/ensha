<?php

namespace App\Http\Controllers;

use App\Models\Centre;
use App\Models\CompensationRule;
use App\Models\User;
use App\Services\CompensationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CompensationRuleController extends Controller
{
    public function index(Request $request, Centre $centre)
    {
        $this->authorizeCentre($request, $centre, 'compensation_rules.view');
        $rules = CompensationRule::with(['topic', 'counselor', 'creator'])
            ->where('centre_id', $centre->id)->orderByDesc('is_active')->orderByDesc('valid_from')->paginate(30);
        $topics = DB::table('service_topics as t')->join('service_categories as c', 'c.id', '=', 't.category_id')
            ->where('c.centre_id', $centre->id)->where('t.is_active', true)->select('t.id', 't.name')->orderBy('t.name')->get();
        $counselors = User::query()->join('user_role_centres as urc', 'urc.user_id', '=', 'users.id')
            ->join('roles as r', 'r.id', '=', 'urc.role_id')->where('urc.centre_id', $centre->id)
            ->where('r.slug', 'counselor')->where('users.is_active', true)->select('users.*')->distinct()->orderBy('users.name')->get();
        return view('finance.compensation-rules', compact('centre', 'rules', 'topics', 'counselors'));
    }

    public function store(Request $request, Centre $centre, CompensationService $service)
    {
        $this->authorizeCentre($request, $centre, 'compensation_rules.manage');
        $data = $request->validate([
            'name' => 'required|string|max:150', 'beneficiary' => ['required', Rule::in(['centre', 'counselor'])],
            'calculation_type' => ['required', Rule::in(['percentage', 'fixed'])], 'value' => 'required|numeric|min:0.01',
            'topic_id' => 'nullable|integer', 'counselor_id' => 'nullable|integer', 'valid_from' => 'required|date',
            'valid_until' => 'nullable|date|after_or_equal:valid_from', 'note' => 'nullable|string|max:2000',
        ]);
        if ($data['calculation_type'] === 'percentage') {
            abort_if((float) $data['value'] > 100, 422, 'درصد سهم نمی‌تواند بیشتر از ۱۰۰ باشد.');
            $data['value'] = (int) round((float) $data['value'] * 100);
        } else {
            $data['value'] = (int) round((float) $data['value']);
        }
        if (! empty($data['topic_id'])) {
            abort_unless(DB::table('service_topics as t')->join('service_categories as c', 'c.id', '=', 't.category_id')
                ->where('t.id', $data['topic_id'])->where('c.centre_id', $centre->id)->exists(), 422, 'موضوع متعلق به این مرکز نیست.');
        }
        if (! empty($data['counselor_id'])) {
            abort_unless(DB::table('user_role_centres as urc')->join('roles as r', 'r.id', '=', 'urc.role_id')
                ->where('urc.user_id', $data['counselor_id'])->where('urc.centre_id', $centre->id)->where('r.slug', 'counselor')->exists(), 422, 'مشاور متعلق به این مرکز نیست.');
        }
        $service->createRule((int) $centre->id, $request->user(), $data);
        return back()->with('success', 'نسخه جدید قانون سهم ثبت شد. جلسات قبلی بدون تغییر می‌مانند.');
    }

    public function retire(Request $request, Centre $centre, CompensationRule $rule, CompensationService $service)
    {
        $this->authorizeCentre($request, $centre, 'compensation_rules.manage');
        abort_unless((int) $rule->centre_id === (int) $centre->id, 404);
        $service->retireRule($rule, $request->user());
        return back()->with('success', 'قانون سهم غیرفعال شد.');
    }

    private function authorizeCentre(Request $request, Centre $centre, string $permission): void
    {
        abort_unless($request->user()->hasPermission($permission), 403);
        abort_unless($request->user()->isSuperAdmin() || (int) $request->user()->centre_id === (int) $centre->id, 403);
    }
}
