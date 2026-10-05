<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientMergeRecord;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ClientMergeController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->hasPermission('clients.duplicates'), 403);
        $clients = Client::visibleTo($request->user())->whereNull('merged_into_id')->with('user')->orderBy('id')->get();
        $pairs = collect();
        for ($i = 0; $i < $clients->count(); $i++) {
            for ($j = $i + 1; $j < $clients->count(); $j++) {
                $left = $clients[$i]; $right = $clients[$j];
                $reasons = [];
                if ($left->user?->national_id && $left->user->national_id === $right->user?->national_id) $reasons[] = 'کد ملی یکسان';
                if ($left->user?->phone && $left->user->phone === $right->user?->phone) $reasons[] = 'تلفن یکسان';
                if ($left->date_of_birth && $right->date_of_birth && $left->date_of_birth->equalTo($right->date_of_birth) && mb_strtolower(trim($left->user?->display_name ?? '')) === mb_strtolower(trim($right->user?->display_name ?? ''))) $reasons[] = 'نام و تاریخ تولد یکسان';
                if ($reasons !== []) $pairs->push(compact('left', 'right', 'reasons'));
            }
        }
        return view('clients.duplicates', compact('pairs'));
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->hasPermission('clients.merge'), 403);
        $data = $request->validate([
            'source_client_id' => ['required', 'integer', Rule::exists('clients', 'id')],
            'target_client_id' => ['required', 'integer', 'different:source_client_id', Rule::exists('clients', 'id')],
            'reason' => ['required', 'string', 'min:10', 'max:3000'],
            'confirmation' => ['required', Rule::in(['MERGE'])],
        ]);
        $source = Client::visibleTo($request->user())->whereNull('merged_into_id')->findOrFail($data['source_client_id']);
        $target = Client::visibleTo($request->user())->whereNull('merged_into_id')->findOrFail($data['target_client_id']);
        abort_unless($source->centre_id === $target->centre_id, 422, 'ادغام بین دو مرکز مجاز نیست.');

        $merge = DB::transaction(function () use ($source, $target, $data, $request) {
            $source = Client::whereKey($source->id)->lockForUpdate()->firstOrFail();
            $target = Client::whereKey($target->id)->lockForUpdate()->firstOrFail();
            abort_if($source->merged_into_id || $target->merged_into_id, 409, 'یکی از پرونده‌ها قبلاً ادغام شده است.');
            $summary = [];
            foreach (['cases', 'client_intakes', 'client_guardians', 'emergency_contacts', 'client_consents', 'private_files'] as $table) {
                $count = DB::table($table)->where('client_id', $source->id)->update(['client_id' => $target->id]);
                $summary[$table] = $count;
            }
            $identityMoved = 0; $identitySkipped = 0;
            foreach ($source->externalIdentities()->get() as $identity) {
                if ($target->externalIdentities()->where('provider', $identity->provider)->exists()) { $identity->delete(); $identitySkipped++; }
                else { $identity->update(['client_id' => $target->id]); $identityMoved++; }
            }
            $summary['external_identities_moved'] = $identityMoved;
            $summary['external_identities_skipped'] = $identitySkipped;
            $source->update(['status' => 'archived', 'merged_into_id' => $target->id, 'merged_at' => now(), 'merged_by' => $request->user()->id]);
            return ClientMergeRecord::create([
                'source_client_id' => $source->id, 'target_client_id' => $target->id,
                'requested_by' => $request->user()->id, 'approved_by' => $request->user()->id,
                'status' => 'completed', 'reason' => $data['reason'], 'merge_summary' => $summary, 'executed_at' => now(),
            ]);
        });
        Audit::record('ادغام کنترل‌شده مراجع', $request, 'warning', ['merge_id' => $merge->id, 'source_client_id' => $source->id, 'target_client_id' => $target->id, 'reason' => $data['reason']], $target, 'client.merge.completed');
        return redirect()->route('clients.show', $target)->with('success', 'پرونده‌های تکراری با ثبت ممیزی ادغام شدند.');
    }
}
