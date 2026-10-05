<?php

namespace App\Http\Controllers;

use App\Models\ScheduleException;
use App\Models\CentreBranch;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ScheduleExceptionController extends Controller
{
    public function store(Request $request)
    {
        abort_unless($request->user()->hasPermission('schedule_exceptions.manage'), 403);
        $data = $request->validate(['branch_id' => 'nullable|integer|exists:centre_branches,id', 'user_id' => 'required|integer|exists:users,id', 'exception_date' => 'required|date', 'starts_at' => 'nullable|date_format:H:i', 'ends_at' => 'nullable|date_format:H:i', 'type' => ['required', Rule::in(['unavailable', 'override'])], 'reason' => 'nullable|string|max:250']);
        if (($data['starts_at'] ?? null) xor ($data['ends_at'] ?? null)) throw ValidationException::withMessages(['starts_at' => 'شروع و پایان باید با هم وارد شوند.']);
        if (! empty($data['starts_at']) && $data['ends_at'] <= $data['starts_at']) throw ValidationException::withMessages(['ends_at' => 'پایان باید بعد از شروع باشد.']);
        $centreId = (int) $request->user()->centre_id;
        abort_unless($request->user()->isSuperAdmin() || $centreId > 0, 403);
        abort_unless(User::whereKey($data['user_id'])->whereHas('roleAssignments', fn ($q) => $q->where('centre_id', $centreId))->exists(), 403);
        if (! empty($data['branch_id'])) abort_unless(CentreBranch::whereKey($data['branch_id'])->where('centre_id', $centreId)->exists(), 403);
        ScheduleException::create($data + ['centre_id' => $centreId, 'created_by' => $request->user()->id]);
        return back()->with('success', 'استثنای برنامه ثبت شد.');
    }

    public function destroy(Request $request, ScheduleException $exception)
    {
        abort_unless($request->user()->hasPermission('schedule_exceptions.manage'), 403);
        abort_unless($request->user()->isSuperAdmin() || $exception->centre_id === $request->user()->centre_id, 403);
        $exception->delete();
        return back()->with('success', 'استثنا حذف شد.');
    }
}
