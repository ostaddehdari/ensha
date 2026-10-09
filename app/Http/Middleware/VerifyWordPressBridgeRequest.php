<?php

namespace App\Http\Middleware;

use App\Models\Centre;
use App\Models\CentreIntegration;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class VerifyWordPressBridgeRequest
{
    private const CLOCK_SKEW_SECONDS = 300;

    private const IDEMPOTENCY_TTL_HOURS = 24;

    public function handle(Request $request, Closure $next): Response
    {
        $centre = $request->route('centre');
        abort_unless($centre instanceof Centre, 404);

        $integration = CentreIntegration::query()
            ->where('centre_id', $centre->id)
            ->where('driver', 'wordpress')
            ->where('is_active', true)
            ->first();
        $secret = (string) $integration?->secret('api_key');
        abort_unless($secret !== '', 401, 'اتصال WordPress این مرکز فعال نیست.');

        $timestamp = (string) $request->header('X-Ensha-Timestamp');
        $nonce = (string) $request->header('X-Ensha-Nonce');
        $signature = strtolower((string) $request->header('X-Ensha-Signature'));

        abort_unless(ctype_digit($timestamp), 401, 'زمان امضای درخواست معتبر نیست.');
        abort_unless(abs(now()->timestamp - (int) $timestamp) <= self::CLOCK_SKEW_SECONDS, 401, 'امضای درخواست منقضی شده است.');
        abort_unless((bool) preg_match('/\A[A-Za-z0-9_-]{16,128}\z/', $nonce), 401, 'Nonce درخواست معتبر نیست.');
        abort_unless((bool) preg_match('/\A[0-9a-f]{64}\z/', $signature), 401, 'امضای درخواست معتبر نیست.');

        $canonical = implode("\n", [
            strtoupper($request->method()),
            '/'.$request->path(),
            $this->canonicalQuery($request),
            $timestamp,
            $nonce,
            hash('sha256', $request->isMethodSafe() ? '' : $request->getContent()),
        ]);
        abort_unless(hash_equals(hash_hmac('sha256', $canonical, $secret), $signature), 401, 'امضای درخواست معتبر نیست.');

        DB::table('wordpress_request_nonces')->where('expires_at', '<=', now())->delete();
        try {
            DB::table('wordpress_request_nonces')->insert([
                'centre_id' => $centre->id,
                'nonce' => $nonce,
                'request_timestamp' => (int) $timestamp,
                'expires_at' => now()->addSeconds(self::CLOCK_SKEW_SECONDS),
                'created_at' => now(),
            ]);
        } catch (QueryException $exception) {
            if ($this->isUniqueConstraintViolation($exception)) {
                abort(409, 'این درخواست قبلاً دریافت شده است.');
            }

            throw $exception;
        }

        if ($request->isMethodSafe()) {
            return $next($request);
        }

        return $this->handleIdempotentMutation($request, $centre, $next);
    }

    private function handleIdempotentMutation(Request $request, Centre $centre, Closure $next): Response
    {
        $key = (string) $request->header('X-Ensha-Idempotency-Key');
        abort_unless((bool) preg_match('/\A[A-Za-z0-9_-]{16,128}\z/', $key), 422, 'کلید Idempotency معتبر نیست.');

        $requestHash = hash('sha256', implode("\n", [
            strtoupper($request->method()),
            '/'.$request->path(),
            $this->canonicalQuery($request),
            $request->getContent(),
        ]));

        DB::table('wordpress_idempotency_keys')->where('expires_at', '<=', now())->delete();
        $record = DB::table('wordpress_idempotency_keys')
            ->where('centre_id', $centre->id)
            ->where('idempotency_key', $key)
            ->first();

        if ($record) {
            return $this->replay($record, $requestHash);
        }

        try {
            $recordId = DB::table('wordpress_idempotency_keys')->insertGetId([
                'centre_id' => $centre->id,
                'idempotency_key' => $key,
                'request_hash' => $requestHash,
                'method' => strtoupper($request->method()),
                'path' => '/'.$request->path(),
                'status_code' => null,
                'response_body' => null,
                'expires_at' => now()->addHours(self::IDEMPOTENCY_TTL_HOURS),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException $exception) {
            if (! $this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            $record = DB::table('wordpress_idempotency_keys')
                ->where('centre_id', $centre->id)
                ->where('idempotency_key', $key)
                ->first();
            abort_unless($record, 409, 'وضعیت درخواست هم‌کلید قابل بازیابی نیست.');

            return $this->replay($record, $requestHash);
        }

        try {
            $response = $next($request);
        } catch (\Throwable $exception) {
            DB::table('wordpress_idempotency_keys')->where('id', $recordId)->delete();
            throw $exception;
        }

        DB::table('wordpress_idempotency_keys')->where('id', $recordId)->update([
            'status_code' => $response->getStatusCode(),
            'response_body' => $response->getContent(),
            'updated_at' => now(),
        ]);

        return $response;
    }

    private function replay(object $record, string $requestHash): JsonResponse
    {
        abort_unless(hash_equals((string) $record->request_hash, $requestHash), 409, 'این کلید Idempotency برای درخواست دیگری استفاده شده است.');
        abort_if($record->status_code === null, 409, 'درخواست هم‌کلید هنوز در حال پردازش است.');

        $payload = json_decode((string) $record->response_body, true);
        abort_unless(is_array($payload), 409, 'پاسخ ذخیره‌شده قابل بازپخش نیست.');

        return response()->json($payload, (int) $record->status_code, ['X-Ensha-Idempotent-Replay' => 'true']);
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        return in_array((string) $exception->getCode(), ['23000', '23505'], true)
            || str_contains(strtolower($exception->getMessage()), 'unique constraint');
    }

    private function canonicalQuery(Request $request): string
    {
        return http_build_query($this->sortQuery($request->query()), '', '&', PHP_QUERY_RFC3986);
    }

    private function sortQuery(array $query): array
    {
        ksort($query);
        foreach ($query as $key => $value) {
            if (is_array($value)) {
                $query[$key] = $this->sortQuery($value);
            }
        }

        return $query;
    }
}
