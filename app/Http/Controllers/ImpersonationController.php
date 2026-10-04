<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Audit;
use App\Support\SessionRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class ImpersonationController extends Controller
{
    public function start(Request $request, User $user): RedirectResponse
    {
        $this->authorize('impersonate', $user);
        if ($request->session()->has('impersonator_id')) {
            throw ValidationException::withMessages(['impersonation' => 'ابتدا از حالت ورود موقت فعلی خارج شوید.']);
        }

        $actor = $request->user();
        $assignmentId=$request->input('assignment_id');
        $assignment=$assignmentId ? $user->roleAssignments()->with('role')->findOrFail($assignmentId) : null;
        if ($assignment) abort_unless($assignment->role?->is_active && $assignment->role->slug==='manager' && $assignment->centre_id,403);
        Audit::record(
            'شروع ورود موقت به حساب کاربر',
            $request,
            'warning',
            ['target_user_id' => $user->id, 'target_role' => $user->role],
            $user,
            'impersonation.started',
            $actor,
        );

        $actorAssignmentId=$request->session()->get('active_assignment_id');
        SessionRegistry::revokeCurrent($request, 'impersonation_started');
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put([
            'impersonator_id' => $actor->id,
            'impersonated_user_id' => $user->id,
            'active_assignment_id' => $assignment?->id,
            'impersonator_assignment_id' => $actorAssignmentId,
        ]);
        SessionRegistry::putRevision($request, $user);
        SessionRegistry::record($request, $user);

        return redirect()->route('dashboard')
            ->with('warning', 'اکنون پنل را با هویت '.$user->display_name.' مشاهده می‌کنید.');
    }

    public function stop(Request $request): RedirectResponse
    {
        $impersonatorId = $request->session()->get('impersonator_id');
        $target = $request->user();
        if (! $impersonatorId || ! $target) {
            return redirect()->route('dashboard');
        }

        $actor = User::query()->find($impersonatorId);
        if (! $actor || ! $actor->isSuperAdmin() || ! $actor->isActive()) {
            SessionRegistry::revokeCurrent($request, 'impersonation_owner_unavailable');
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'phone' => 'حساب ادمین اصلی دیگر برای بازگشت در دسترس نیست.',
            ]);
        }

        Audit::record(
            'پایان ورود موقت به حساب کاربر',
            $request,
            'info',
            ['target_user_id' => $target->id],
            $target,
            'impersonation.stopped',
            $actor,
        );

        $actorAssignmentId=$request->session()->get('impersonator_assignment_id');
        SessionRegistry::revokeCurrent($request, 'impersonation_stopped');
        Auth::login($actor);
        $request->session()->regenerate();
        $request->session()->forget(['impersonator_id', 'impersonated_user_id','impersonator_assignment_id']);
        if ($actorAssignmentId) $request->session()->put('active_assignment_id',$actorAssignmentId); else $request->session()->forget('active_assignment_id');
        SessionRegistry::putRevision($request, $actor);
        SessionRegistry::record($request, $actor);

        return redirect()->route('users.show', $target)
            ->with('success', 'به حساب ادمین بازگشتید.');
    }
}
