<?php

namespace App\Http\Controllers;

use App\Models\ProfileField;
use App\Models\Role;
use App\Rules\IranianMobile;
use App\Support\Audit;
use App\Support\PhoneNormalizer;
use App\Support\ProfileForm;
use App\Support\SessionRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SelfProfileController extends Controller
{
    public function show(Request $request): View
    {
        $user = $request->user()->load(['assignedRole', 'centre', 'profileValues']);
        $profileFieldGroups = ProfileField::query()
            ->where('is_active', true)
            ->whereIn('role', array_unique(['all', $user->role]))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->groupBy('role');

        return view('account.profile', [
            'user' => $user,
            'roles' => Role::query()->whereKey($user->role_id)->get(),
            'profileFieldGroups' => $profileFieldGroups,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        $role = $user->assignedRole;
        abort_unless($role && $role->is_active, 403);

        $request->merge(['phone' => PhoneNormalizer::normalize((string) $request->input('phone'))]);
        $phoneChanged = $user->phone !== $request->input('phone');
        $data = $request->validate([
            'phone' => ['required', new IranianMobile, Rule::unique('users', 'phone')->ignore($user->id)],
            'current_password' => [Rule::requiredIf($phoneChanged), 'nullable', 'current_password'],
            'avatar' => ['nullable','image','mimes:jpeg,png,webp','max:2048'],
        ], [], ['phone' => 'شماره تماس', 'current_password' => 'رمز عبور فعلی']);
        $values = ProfileForm::validate($request, $role);

        DB::transaction(function () use ($user, $role, $data, $values, $phoneChanged, $request) {
            if ($request->hasFile('avatar')) {
                $previous=$user->avatar_path;
                $user->avatar_path=$request->file('avatar')->store('avatars','public');
                $user->save();
                if ($previous) \Illuminate\Support\Facades\Storage::disk('public')->delete($previous);
            }
            if ($phoneChanged) {
                $user->phone = $data['phone'];
                $user->save();
                SessionRegistry::invalidateAll($user, 'login_phone_changed', SessionRegistry::hash($request));
            }
            ProfileForm::save($user, $role, $values);
        });

        if ($phoneChanged) {
            SessionRegistry::putRevision($request, $user);
            SessionRegistry::record($request, $user);
        }
        Audit::record('ویرایش پروفایل توسط کاربر', $request, 'info', [
            'national_id' => $user->national_id,
            'phone_changed' => $phoneChanged,
            'profile_field_ids' => array_map('intval', array_keys($values)),
        ], $user, 'profile.self_updated');

        return redirect()->route('profile.show')->with('success', 'اطلاعات پروفایل ذخیره شد.');
    }
}
