<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\ClientGuardian;
use App\Models\ClientIntake;
use App\Models\CounsellingCase;
use App\Models\EmergencyContact;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ClientRecordController extends Controller
{
    public function storeIntake(Request $request, Client $client)
    {
        $this->authorizeClient($request, $client, 'intakes.manage');
        $data = $request->validate([
            'case_id' => ['nullable', 'integer', Rule::exists('cases', 'id')],
            'intake_date' => ['nullable', 'date'],
            'status' => ['required', Rule::in(['draft', 'complete', 'cancelled'])],
            'risk_level' => ['required', Rule::in(['unknown', 'low', 'medium', 'high', 'critical'])],
            'referral_source' => ['nullable', 'string', 'max:190'],
            'presenting_concern' => ['nullable', 'string', 'max:10000'],
            'medical_notes' => ['nullable', 'string', 'max:10000'],
            'safeguarding_notes' => ['nullable', 'string', 'max:10000'],
        ]);
        if (! empty($data['case_id'])) {
            abort_unless(CounsellingCase::whereKey($data['case_id'])->where('client_id', $client->id)->exists(), 422);
        }
        $intake = DB::transaction(function () use ($data, $client, $request) {
            $next = $client->intakes()->lockForUpdate()->count() + 1;
            $intake = $client->intakes()->create([
                ...$data,
                'intake_number' => 'INT-'.str_pad((string) $next, 4, '0', STR_PAD_LEFT),
                'completed_by' => $data['status'] === 'complete' ? $request->user()->id : null,
                'completed_at' => $data['status'] === 'complete' ? now() : null,
            ]);
            return $intake;
        });
        Audit::record('ثبت پذیرش مراجع', $request, 'info', ['client_id' => $client->id, 'intake_id' => $intake->id], $client, 'client.intake.created');
        return back()->with('success', 'فرم پذیرش ثبت شد.');
    }

    public function storeGuardian(Request $request, Client $client)
    {
        $this->authorizeClient($request, $client, 'intakes.manage');
        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:190'], 'relationship' => ['required', 'string', 'max:80'],
            'phone' => ['required', 'string', 'max:32'], 'national_id' => ['nullable', 'string', 'max:32'],
            'is_primary' => ['sometimes', 'boolean'], 'has_legal_authority' => ['sometimes', 'boolean'],
            'verification_notes' => ['nullable', 'string', 'max:3000'],
        ]);
        $guardian = DB::transaction(function () use ($client, $data, $request) {
            if (! empty($data['is_primary'])) $client->guardians()->update(['is_primary' => false]);
            return $client->guardians()->create([...$data, 'verified_by' => $request->user()->id, 'verified_at' => now()]);
        });
        Audit::record('ثبت ولی قانونی مراجع', $request, 'info', ['client_id' => $client->id, 'guardian_id' => $guardian->id], $client, 'client.guardian.created');
        return back()->with('success', 'اطلاعات ولی قانونی ثبت شد.');
    }

    public function destroyGuardian(Request $request, Client $client, ClientGuardian $guardian)
    {
        $this->authorizeClient($request, $client, 'intakes.manage');
        abort_unless($guardian->client_id === $client->id, 404);
        $guardian->delete();
        Audit::record('حذف ولی قانونی مراجع', $request, 'warning', ['client_id' => $client->id, 'guardian_id' => $guardian->id], $client, 'client.guardian.deleted');
        return back()->with('success', 'اطلاعات ولی حذف شد.');
    }

    public function storeEmergencyContact(Request $request, Client $client)
    {
        $this->authorizeClient($request, $client, 'intakes.manage');
        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:190'], 'relationship' => ['required', 'string', 'max:80'],
            'phone' => ['required', 'string', 'max:32'], 'alternate_phone' => ['nullable', 'string', 'max:32'],
            'priority' => ['required', 'integer', 'between:1,10'], 'authorized_for_contact' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:3000'],
        ]);
        $contact = $client->emergencyContacts()->create($data);
        Audit::record('ثبت تماس اضطراری مراجع', $request, 'info', ['client_id' => $client->id, 'contact_id' => $contact->id], $client, 'client.emergency_contact.created');
        return back()->with('success', 'تماس اضطراری ثبت شد.');
    }

    public function destroyEmergencyContact(Request $request, Client $client, EmergencyContact $contact)
    {
        $this->authorizeClient($request, $client, 'intakes.manage');
        abort_unless($contact->client_id === $client->id, 404);
        $contact->delete();
        Audit::record('حذف تماس اضطراری مراجع', $request, 'warning', ['client_id' => $client->id, 'contact_id' => $contact->id], $client, 'client.emergency_contact.deleted');
        return back()->with('success', 'تماس اضطراری حذف شد.');
    }

    public function storeConsent(Request $request, Client $client)
    {
        $this->authorizeClient($request, $client, 'intakes.manage');
        $data = $request->validate([
            'case_id' => ['nullable', 'integer', Rule::exists('cases', 'id')],
            'consent_type' => ['required', Rule::in(['treatment', 'data_processing', 'recording', 'guardian', 'research', 'other'])],
            'document_version' => ['required', 'string', 'max:40'], 'expires_at' => ['nullable', 'date', 'after_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:3000'],
        ]);
        if (! empty($data['case_id'])) abort_unless(CounsellingCase::whereKey($data['case_id'])->where('client_id', $client->id)->exists(), 422);
        $consent = $client->consents()->create([...$data, 'is_granted' => true, 'granted_at' => now(), 'captured_by' => $request->user()->id]);
        Audit::record('ثبت رضایت‌نامه مراجع', $request, 'info', ['client_id' => $client->id, 'consent_id' => $consent->id], $client, 'client.consent.granted');
        return back()->with('success', 'رضایت‌نامه ثبت شد.');
    }

    public function revokeConsent(Request $request, Client $client, ClientConsent $consent)
    {
        $this->authorizeClient($request, $client, 'intakes.manage');
        abort_unless($consent->client_id === $client->id, 404);
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $consent->update(['is_granted' => false, 'revoked_at' => now(), 'notes' => trim(($consent->notes ? $consent->notes."\n" : '').'علت لغو: '.$data['reason'])]);
        Audit::record('لغو رضایت‌نامه مراجع', $request, 'warning', ['client_id' => $client->id, 'consent_id' => $consent->id, 'reason' => $data['reason']], $client, 'client.consent.revoked');
        return back()->with('success', 'رضایت‌نامه لغو شد.');
    }

    private function authorizeClient(Request $request, Client $client, string $permission): void
    {
        abort_unless($request->user()->hasPermission($permission), 403);
        abort_unless(Client::visibleTo($request->user())->whereKey($client->id)->exists(), 404);
        abort_if($client->merged_into_id !== null, 409, 'پرونده مبدأ قبلاً ادغام شده است.');
    }
}
