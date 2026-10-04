<?php

namespace App\Http\Controllers;

use App\Support\Audit;
use App\Support\SessionRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class AccountPasswordController extends Controller
{
    public function edit(): View
    {
        return view('account.password');
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'different:current_password', 'confirmed', Password::min(10)->letters()->mixedCase()->numbers()->symbols()],
        ], [], ['current_password' => 'رمز فعلی', 'password' => 'رمز جدید']);

        $user = $request->user();
        $user->forceFill([
            'password' => $data['password'],
            'password_changed_at' => now(),
            'must_change_password' => false,
        ])->save();
        SessionRegistry::invalidateAll($user, 'password_changed');
        $request->session()->regenerate();
        SessionRegistry::putRevision($request, $user);
        SessionRegistry::record($request, $user);

        Audit::record('تغییر رمز عبور توسط کاربر', $request, 'info', [], $user, 'account.password_changed');

        return redirect()->route('dashboard')->with('success', 'رمز عبور شما با موفقیت تغییر کرد.');
    }
}
