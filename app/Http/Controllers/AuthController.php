<?php

namespace App\Http\Controllers;

use App\Models\Centre;
use App\Models\Role;
use App\Models\User;
use App\Rules\IranianMobile;
use App\Rules\IranianNationalId;
use App\Support\Audit;
use App\Support\PhoneNormalizer;
use App\Support\SessionRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function showLogin(): View|RedirectResponse
    {
        return auth()->check() ? redirect()->route('dashboard') : view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $request->merge(['phone' => PhoneNormalizer::normalize((string) $request->input('phone'))]);
        $credentials = $request->validate([
            'phone' => ['required', new IranianMobile],
            'password' => ['required', 'string', 'min:6'],
        ], [], ['phone' => 'شماره تلفن', 'password' => 'رمز عبور']);

        if (! Auth::attempt([
            'phone' => $credentials['phone'],
            'password' => $credentials['password'],
            'is_active' => true,
            'status' => 'active',
        ], $request->boolean('remember'))) {
            Audit::record('ورود ناموفق', $request, 'warning', ['phone' => $credentials['phone']]);

            return back()->withErrors(['phone' => 'شماره تلفن یا رمز عبور صحیح نیست.'])->withInput($request->only('phone'));
        }

        if (! $request->user()->roleAssignments()->whereHas('role',fn($query)=>$query->where('is_active',true))->exists()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return back()->withErrors(['phone' => 'نقش این حساب غیرفعال است. با مدیر سامانه تماس بگیرید.']);
        }

        $request->session()->regenerate();
        SessionRegistry::putRevision($request, $request->user());
        $request->user()->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->saveQuietly();
        SessionRegistry::record($request, $request->user());
        Audit::record('ورود موفق', $request, 'info', [], $request->user(), 'auth.login');

        return $request->user()->roleAssignments()->count() > 1 ? redirect()->route('account.roles') : redirect()->intended(route('dashboard'));
    }

    public function showRegister(): View|RedirectResponse
    {
        return auth()->check() ? redirect()->route('dashboard') : view('auth.register');
    }

    public function register(Request $request): RedirectResponse
    {
        $request->merge([
            'phone' => PhoneNormalizer::normalize((string) $request->input('phone')),
            'national_id' => PhoneNormalizer::normalizeDigits((string) $request->input('national_id')),
        ]);
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'national_id' => ['required', new IranianNationalId, 'unique:users,national_id'],
            'phone' => ['required', new IranianMobile, 'unique:users,phone'],
            'password' => ['required', 'confirmed', Password::min(10)->letters()->mixedCase()->numbers()->symbols()],
        ]);

        $clientRole = Role::query()->where('slug', 'client')->firstOrFail();
        $defaultCentre = Centre::query()->where('is_active', true)->orderBy('id')->first();

        $user = User::create([
            ...$data,
            'name' => trim($data['first_name'].' '.$data['last_name']),
            'role' => 'client',
            'role_id' => $clientRole->id,
            'centre_id' => $defaultCentre?->id,
            'is_active' => true,
            'status' => 'active',
            'status_changed_at' => now(),
            'password_changed_at' => now(),
            'must_change_password' => false,
        ]);

        $user->roleAssignments()->create(['role_id'=>$clientRole->id,'centre_id'=>$defaultCentre?->id]);
        Auth::login($user);
        $request->session()->regenerate();
        SessionRegistry::putRevision($request, $user);
        SessionRegistry::record($request, $user);
        Audit::record('ثبت‌نام مراجعه‌کننده', $request, 'info', ['user_id' => $user->id], $user, 'auth.register');

        return redirect()->route('dashboard')->with('success', 'حساب مراجعه‌کننده با موفقیت ساخته شد.');
    }

    public function logout(Request $request): RedirectResponse
    {
        Audit::record('خروج از سامانه', $request);
        SessionRegistry::revokeCurrent($request, 'logout');
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    public static function normalizePhone(string $phone): string
    {
        return PhoneNormalizer::normalize($phone);
    }
}
