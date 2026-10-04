<?php

namespace App\Http\Controllers;

use App\Models\Centre;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CentreSettingsController extends Controller
{
    public function edit(Request $request, Centre $centre)
    {
        $this->authorizeCentre($request, $centre, 'centre_settings.view');

        return view('centres.settings', compact('centre'));
    }

    public function update(Request $request, Centre $centre)
    {
        $this->authorizeCentre($request, $centre, 'centre_settings.manage');
        $data = $request->validate([
            'timezone' => ['required', 'string', Rule::in(timezone_identifiers_list())],
            'appointment_slot_minutes' => ['required', Rule::in([15, 30, 45, 60])],
            'default_session_minutes' => ['required', 'integer', 'min:15', 'max:240'],
            'week_starts_on' => ['required', 'integer', 'between:0,6'],
            'working_day_start' => ['required', 'date_format:H:i'],
            'working_day_end' => ['required', 'date_format:H:i', 'after:working_day_start'],
        ]);
        $settings = collect($data)->except('timezone')->all();
        $centre->update(['timezone' => $data['timezone'], 'settings' => $settings]);
        Audit::record('ویرایش تنظیمات مرکز', $request, 'warning', ['centre_id' => $centre->id, 'settings' => $settings], $centre, 'centre.settings.updated');

        return back()->with('success', 'تنظیمات مرکز ذخیره شد.');
    }

    private function authorizeCentre(Request $request, Centre $centre, string $permission): void
    {
        $actor = $request->user();
        abort_unless($actor->hasPermission($permission), 403);
        abort_unless($actor->isSuperAdmin() || $actor->centre_id === $centre->id, 403);
    }
}
