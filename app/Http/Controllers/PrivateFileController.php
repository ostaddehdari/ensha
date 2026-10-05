<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\CounsellingCase;
use App\Models\PrivateFile;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PrivateFileController extends Controller
{
    public function store(Request $request, Client $client)
    {
        $this->authorizeClient($request, $client, 'private_files.manage');
        $data = $request->validate([
            'case_id' => ['nullable', 'integer', Rule::exists('cases', 'id')],
            'file' => ['required', 'file', 'max:20480', 'mimes:pdf,jpg,jpeg,png,doc,docx,txt'],
            'classification' => ['required', Rule::in(['confidential', 'clinical', 'identity', 'consent'])],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);
        if (! empty($data['case_id'])) abort_unless(CounsellingCase::whereKey($data['case_id'])->where('client_id', $client->id)->exists(), 422);
        $uploaded = $request->file('file');
        $storedName = Str::uuid()->toString().'.'.strtolower($uploaded->getClientOriginalExtension());
        $path = $uploaded->storeAs('ensha-private/'.$client->id, $storedName, 'local');
        abort_unless($path, 500, 'ذخیره فایل ناموفق بود.');
        $absolute = Storage::disk('local')->path($path);
        $record = $client->privateFiles()->create([
            'case_id' => $data['case_id'] ?? null, 'uploaded_by' => $request->user()->id,
            'disk' => 'local', 'path' => $path, 'original_name' => basename($uploaded->getClientOriginalName()),
            'mime_type' => $uploaded->getMimeType(), 'size_bytes' => filesize($absolute), 'sha256' => hash_file('sha256', $absolute),
            'classification' => $data['classification'], 'scan_status' => 'pending', 'description' => $data['description'] ?? null,
        ]);
        Audit::record('بارگذاری فایل خصوصی', $request, 'warning', ['client_id' => $client->id, 'file_id' => $record->id, 'sha256' => $record->sha256], $client, 'client.private_file.uploaded');
        return back()->with('success', 'فایل در فضای خصوصی ذخیره شد.');
    }

    public function download(Request $request, PrivateFile $privateFile)
    {
        $client = $privateFile->client;
        $this->authorizeClient($request, $client, 'private_files.view');
        abort_if($privateFile->scan_status === 'infected', 423, 'دریافت فایل آلوده مسدود است.');
        abort_unless(Storage::disk($privateFile->disk)->exists($privateFile->path), 404);
        $absolute = Storage::disk($privateFile->disk)->path($privateFile->path);
        abort_unless(hash_equals($privateFile->sha256, hash_file('sha256', $absolute)), 409, 'یکپارچگی فایل تأیید نشد.');
        Audit::record('دریافت فایل خصوصی', $request, 'warning', ['client_id' => $client->id, 'file_id' => $privateFile->id], $client, 'client.private_file.downloaded');
        return Storage::disk($privateFile->disk)->download($privateFile->path, $privateFile->original_name, ['Content-Type' => $privateFile->mime_type ?: 'application/octet-stream']);
    }

    public function destroy(Request $request, PrivateFile $privateFile)
    {
        $client = $privateFile->client;
        $this->authorizeClient($request, $client, 'private_files.manage');
        Storage::disk($privateFile->disk)->delete($privateFile->path);
        $privateFile->delete();
        Audit::record('حذف فایل خصوصی', $request, 'warning', ['client_id' => $client->id, 'file_id' => $privateFile->id], $client, 'client.private_file.deleted');
        return back()->with('success', 'فایل خصوصی حذف شد.');
    }

    private function authorizeClient(Request $request, Client $client, string $permission): void
    {
        abort_unless($request->user()->hasPermission($permission), 403);
        abort_unless(Client::visibleTo($request->user())->whereKey($client->id)->exists(), 404);
    }
}
