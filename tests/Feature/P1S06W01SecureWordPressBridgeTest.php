<?php

namespace Tests\Feature;

use App\Models\AppointmentSlot;
use App\Models\Centre;
use App\Models\CentreIntegration;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class P1S06W01SecureWordPressBridgeTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private string $secret = 'wordpress-bridge-test-secret-with-32-characters';

    public function test_wordpress_connector_signs_requests_without_exposing_the_secret_header_or_setting_value(): void
    {
        $plugin = file_get_contents(base_path('integrations/wordpress/ensha-booking.php'));

        $this->assertStringContainsString('X-Ensha-Signature', $plugin);
        $this->assertStringContainsString('X-Ensha-Idempotency-Key', $plugin);
        $this->assertStringContainsString('data-idempotency', $plugin);
        $this->assertStringNotContainsString("'X-Ensha-Key'=>", $plugin);
        $this->assertStringNotContainsString('value="<?php echo esc_attr(get_option(\'ensha_booking_api_key\'))', $plugin);
    }

    public function test_unsigned_expired_tampered_and_replayed_requests_are_rejected(): void
    {
        [$centre] = $this->fixture();
        $uri = '/api/v1/centres/'.$centre->id.'/availability?to='.now()->addDays(2)->toDateString().'&from='.now()->addDay()->toDateString();

        $this->getJson($uri)->assertUnauthorized();

        $expired = $this->signedHeaders('GET', $uri, '', now()->subMinutes(10)->timestamp, Str::random(32));
        $this->getJson($uri, $expired)->assertUnauthorized();

        $nonce = Str::random(32);
        $headers = $this->signedHeaders('GET', $uri, '', now()->timestamp, $nonce);
        $tampered = $headers;
        $tampered['X-Ensha-Signature'] = str_repeat('0', 64);
        $this->getJson($uri, $tampered)->assertUnauthorized();

        $this->getJson($uri, $headers)->assertOk();
        $this->getJson($uri, $headers)->assertStatus(409);
        $this->assertDatabaseCount('wordpress_request_nonces', 1);
    }

    public function test_booking_is_idempotent_and_same_key_cannot_be_reused_for_other_payload(): void
    {
        [$centre, $slot] = $this->fixture();
        $uri = '/api/v1/centres/'.$centre->id.'/appointments';
        $idempotencyKey = Str::random(32);
        $payload = [
            'slot_id' => $slot->id,
            'wordpress_user_id' => 'wp-user-1001',
            'first_name' => 'مراجع',
            'last_name' => 'وردپرس',
            'phone' => '09121112233',
            'email' => 'wp-user@example.test',
            'notes' => 'رزرو امضاشده',
        ];
        $body = http_build_query($payload, '', '&');

        $first = $this->signedCall('POST', $uri, $body, $idempotencyKey);
        $first->assertCreated();

        $second = $this->signedCall('POST', $uri, $body, $idempotencyKey);
        $second->assertCreated()->assertHeader('X-Ensha-Idempotent-Replay', 'true');
        $this->assertSame($first->json(), $second->json());
        $this->assertDatabaseCount('appointments', 1);

        $changed = http_build_query([...$payload, 'notes' => 'بدنه متفاوت'], '', '&');
        $this->signedCall('POST', $uri, $changed, $idempotencyKey)->assertStatus(409);
        $this->assertDatabaseCount('appointments', 1);
    }

    public function test_mutating_request_requires_idempotency_key(): void
    {
        [$centre, $slot] = $this->fixture();
        $uri = '/api/v1/centres/'.$centre->id.'/appointments';
        $body = http_build_query([
            'slot_id' => $slot->id,
            'wordpress_user_id' => 'wp-user-1002',
            'first_name' => 'کاربر',
            'last_name' => 'بدون کلید',
            'phone' => '09121112234',
        ], '', '&');

        $this->signedCall('POST', $uri, $body)->assertStatus(422);
        $this->assertDatabaseCount('appointments', 0);
    }

    private function signedCall(string $method, string $uri, string $body, ?string $idempotencyKey = null)
    {
        $headers = $this->signedHeaders($method, $uri, $body, now()->timestamp, Str::random(32));
        if ($idempotencyKey !== null) {
            $headers['X-Ensha-Idempotency-Key'] = $idempotencyKey;
        }

        $server = ['CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'HTTP_ACCEPT' => 'application/json'];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        parse_str($body, $parameters);

        return $this->call($method, $uri, $parameters, [], [], $server, $body);
    }

    private function signedHeaders(string $method, string $uri, string $body, int $timestamp, string $nonce): array
    {
        $parts = parse_url($uri);
        parse_str($parts['query'] ?? '', $query);
        $canonical = implode("\n", [
            strtoupper($method),
            $parts['path'],
            http_build_query($this->sortQuery($query), '', '&', PHP_QUERY_RFC3986),
            (string) $timestamp,
            $nonce,
            hash('sha256', $body),
        ]);

        return [
            'X-Ensha-Timestamp' => (string) $timestamp,
            'X-Ensha-Nonce' => $nonce,
            'X-Ensha-Signature' => hash_hmac('sha256', $canonical, $this->secret),
        ];
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

    private function fixture(): array
    {
        config(['appointments.lock_store' => 'array']);
        $centre = Centre::query()->where('code', 'ENSHA-MAIN')->firstOrFail();
        CentreIntegration::query()->updateOrCreate(
            ['centre_id' => $centre->id, 'driver' => 'wordpress'],
            ['is_active' => true, 'settings' => [], 'secret_payload' => ['api_key' => $this->secret]],
        );

        $managerRole = Role::query()->where('slug', 'manager')->firstOrFail();
        $manager = User::query()->create([
            'first_name' => 'مدیر', 'last_name' => 'پل', 'name' => 'مدیر پل',
            'phone' => '09120006101', 'password' => 'ValidPass123!', 'role' => 'manager',
            'role_id' => $managerRole->id, 'centre_id' => $centre->id,
            'status' => 'active', 'is_active' => true, 'must_change_password' => false,
        ]);
        $manager->roleAssignments()->create(['role_id' => $managerRole->id, 'centre_id' => $centre->id]);

        $counselorRole = Role::query()->where('slug', 'counselor')->firstOrFail();
        $counselor = User::query()->create([
            'first_name' => 'مشاور', 'last_name' => 'پل', 'name' => 'مشاور پل',
            'phone' => '09120006102', 'password' => 'ValidPass123!', 'role' => 'counselor',
            'role_id' => $counselorRole->id, 'centre_id' => $centre->id,
            'status' => 'active', 'is_active' => true, 'must_change_password' => false,
        ]);
        $counselor->roleAssignments()->create(['role_id' => $counselorRole->id, 'centre_id' => $centre->id]);

        $categoryId = DB::table('service_categories')->insertGetId([
            'centre_id' => $centre->id, 'name' => 'خدمات پل', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $topicId = DB::table('service_topics')->insertGetId([
            'category_id' => $categoryId, 'name' => 'مشاوره پل', 'minimum_minutes' => 15,
            'session_minutes' => 45, 'break_minutes' => 0, 'capacity' => 1,
            'requires_room' => false, 'is_active' => true, 'price' => 500000,
            'color' => '#3366ff', 'allowed_modes' => json_encode(['online']),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('counselor_topics')->insert([
            'user_id' => $counselor->id, 'topic_id' => $topicId, 'centre_id' => $centre->id, 'is_active' => true,
        ]);
        $start = now()->addDays(2)->startOfHour();
        DB::table('counselor_shifts')->insert([
            'user_id' => $counselor->id, 'centre_id' => $centre->id, 'weekday' => $start->dayOfWeek,
            'starts_at' => '00:00:00', 'ends_at' => '23:59:59', 'hourly_pay' => 0,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $slot = AppointmentSlot::query()->create([
            'centre_id' => $centre->id, 'topic_id' => $topicId, 'counselor_id' => $counselor->id,
            'slot_date' => $start->toDateString(), 'starts_at' => $start,
            'ends_at' => $start->copy()->addMinutes(45), 'mode' => 'online',
            'capacity' => 1, 'booked_count' => 0, 'status' => 'available',
        ]);

        return [$centre, $slot];
    }
}
