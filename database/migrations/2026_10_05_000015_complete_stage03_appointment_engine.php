<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('official_holidays', function (Blueprint $table) {
            $table->date('gregorian_date')->nullable()->index()->after('jalali_date');
        });
        $this->mapOfficialHolidays1405();

        Schema::table('service_topics', function (Blueprint $table) {
            $table->json('allowed_modes')->nullable()->after('color');
            $table->unsignedSmallInteger('break_minutes')->default(0)->after('session_minutes');
            $table->unsignedSmallInteger('capacity')->default(1)->after('break_minutes');
            $table->boolean('requires_room')->default(true)->after('capacity');
            $table->boolean('is_active')->default(true)->index()->after('requires_room');
        });

        Schema::table('centre_rooms', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('centre_id')->constrained('centre_branches')->nullOnDelete();
            $table->unsignedSmallInteger('capacity')->default(1)->after('name');
            $table->string('room_type', 30)->default('consulting')->after('capacity');
            $table->index(['centre_id', 'branch_id', 'is_active']);
        });

        Schema::table('counselor_shifts', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('centre_id')->constrained('centre_branches')->nullOnDelete();
            $table->unsignedSmallInteger('slot_interval_minutes')->default(15)->after('ends_at');
            $table->unsignedSmallInteger('break_minutes')->default(0)->after('slot_interval_minutes');
            $table->boolean('is_active')->default(true)->index()->after('hourly_pay');
        });

        Schema::create('schedule_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('centre_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('centre_branches')->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('exception_date');
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();
            $table->string('type', 20)->default('unavailable');
            $table->string('reason', 250)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['centre_id', 'exception_date', 'type']);
            $table->index(['user_id', 'exception_date']);
        });

        Schema::create('service_tariffs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('centre_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('centre_branches')->nullOnDelete();
            $table->foreignId('topic_id')->constrained('service_topics')->cascadeOnDelete();
            $table->foreignId('counselor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('scope_key', 80);
            $table->unsignedInteger('version');
            $table->unsignedBigInteger('price');
            $table->unsignedBigInteger('counselor_pay')->default(0);
            $table->char('currency', 3)->default('IRR');
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['centre_id', 'topic_id', 'scope_key', 'version'], 'tariff_scope_version_unique');
            $table->index(['centre_id', 'topic_id', 'valid_from', 'valid_until'], 'tariff_effective_lookup');
        });

        Schema::create('appointment_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('centre_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('centre_branches')->nullOnDelete();
            $table->foreignId('topic_id')->constrained('service_topics')->cascadeOnDelete();
            $table->foreignId('counselor_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('room_id')->nullable()->constrained('centre_rooms')->nullOnDelete();
            $table->date('slot_date');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('mode', 20)->default('in_person');
            $table->unsignedSmallInteger('capacity')->default(1);
            $table->unsignedSmallInteger('booked_count')->default(0);
            $table->string('status', 20)->default('available')->index();
            $table->unsignedInteger('lock_version')->default(0);
            $table->string('source', 30)->default('weekly_schedule');
            $table->timestamps();
            $table->unique(['counselor_id', 'topic_id', 'starts_at', 'mode'], 'slot_counselor_topic_start_unique');
            $table->index(['centre_id', 'slot_date', 'status']);
            $table->index(['room_id', 'starts_at', 'ends_at']);
        });

        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->string('appointment_number', 50)->unique();
            $table->foreignId('centre_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('centre_branches')->nullOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('case_id')->nullable()->constrained('cases')->nullOnDelete();
            $table->foreignId('topic_id')->constrained('service_topics')->restrictOnDelete();
            $table->foreignId('counselor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('room_id')->nullable()->constrained('centre_rooms')->nullOnDelete();
            $table->foreignId('slot_id')->constrained('appointment_slots')->restrictOnDelete();
            $table->foreignId('tariff_id')->nullable()->constrained('service_tariffs')->nullOnDelete();
            $table->unsignedSmallInteger('seat_number');
            $table->string('mode', 20);
            $table->dateTime('starts_at')->index();
            $table->dateTime('ends_at');
            $table->string('status', 24)->default('pending')->index();
            $table->unsignedBigInteger('price')->default(0);
            $table->unsignedBigInteger('counselor_pay')->default(0);
            $table->char('currency', 3)->default('IRR');
            $table->text('notes')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['slot_id', 'seat_number'], 'appointment_slot_seat_unique');
            $table->index(['centre_id', 'status', 'starts_at']);
            $table->index(['client_id', 'starts_at']);
            $table->index(['counselor_id', 'starts_at']);
        });

        Schema::create('appointment_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('changed_at')->useCurrent();
            $table->timestamps();
            $table->index(['appointment_id', 'changed_at']);
        });

        DB::table('service_topics')->whereNull('allowed_modes')->update(['allowed_modes' => json_encode(['in_person'])]);
        $this->seedPermissions();
    }

    private function seedPermissions(): void
    {
        $now = now();
        $permissions = [
            ['appointments.status', 'تغییر وضعیت نوبت', 'appointments', 'نوبت‌ها', 'اجرای گردش وضعیت نوبت‌های مجاز', 224],
            ['slots.manage', 'مدیریت اسلات‌ها', 'appointments', 'نوبت‌ها', 'تولید و مسدودسازی زمان‌های قابل رزرو', 225],
            ['tariffs.view', 'مشاهده تعرفه‌های نسخه‌دار', 'fees', 'تعرفه‌ها', 'مشاهده تاریخچه تعرفه‌های خدمت، مشاور و شعبه', 226],
            ['tariffs.manage', 'مدیریت تعرفه‌های نسخه‌دار', 'fees', 'تعرفه‌ها', 'ایجاد نسخه جدید تعرفه بدون بازنویسی سابقه', 227],
            ['schedule_exceptions.manage', 'مدیریت استثناهای برنامه', 'schedules', 'برنامه کاری', 'ثبت ساعات جایگزین و عدم دسترس‌پذیری', 228],
        ];
        foreach ($permissions as [$slug, $name, $groupKey, $groupName, $description, $sortOrder]) {
            DB::table('permissions')->updateOrInsert(['slug' => $slug], [
                'name' => $name, 'group_key' => $groupKey, 'group_name' => $groupName,
                'description' => $description, 'sort_order' => $sortOrder,
                'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $all = array_column($permissions, 0);
        $grants = [
            'super_admin' => $all,
            'manager' => $all,
            'secretary' => ['appointments.status', 'slots.manage', 'tariffs.view'],
            'counselor' => ['appointments.status', 'tariffs.view'],
        ];
        foreach ($grants as $roleSlug => $slugs) {
            $roleId = DB::table('roles')->where('slug', $roleSlug)->value('id');
            if (! $roleId) continue;
            foreach (DB::table('permissions')->whereIn('slug', $slugs)->pluck('id') as $permissionId) {
                DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
            }
        }
    }

    private function mapOfficialHolidays1405(): void
    {
        $monthLengths = [1=>31, 2=>31, 3=>31, 4=>31, 5=>31, 6=>31, 7=>30, 8=>30, 9=>30, 10=>30, 11=>30, 12=>29];
        $origin = new \DateTimeImmutable('2026-03-21');
        foreach (DB::table('official_holidays')->where('jalali_year', 1405)->get(['id', 'jalali_date']) as $holiday) {
            $parts = array_map('intval', explode('/', $holiday->jalali_date));
            if (count($parts) !== 3 || $parts[0] !== 1405) continue;
            $offset = $parts[2] - 1;
            for ($month = 1; $month < $parts[1]; $month++) $offset += $monthLengths[$month];
            DB::table('official_holidays')->where('id', $holiday->id)->update(['gregorian_date' => $origin->modify("+{$offset} days")->format('Y-m-d')]);
        }
    }

    public function down(): void
    {
        $slugs = ['appointments.status', 'slots.manage', 'tariffs.view', 'tariffs.manage', 'schedule_exceptions.manage'];
        $ids = DB::table('permissions')->whereIn('slug', $slugs)->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        Schema::dropIfExists('appointment_status_histories');
        Schema::dropIfExists('appointments');
        Schema::dropIfExists('appointment_slots');
        Schema::dropIfExists('service_tariffs');
        Schema::dropIfExists('schedule_exceptions');
        Schema::table('counselor_shifts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
            $table->dropColumn(['slot_interval_minutes', 'break_minutes', 'is_active']);
        });
        Schema::table('centre_rooms', function (Blueprint $table) {
            $table->dropIndex(['centre_id', 'branch_id', 'is_active']);
            $table->dropConstrainedForeignId('branch_id');
            $table->dropColumn(['capacity', 'room_type']);
        });
        Schema::table('service_topics', fn (Blueprint $table) => $table->dropColumn(['allowed_modes', 'break_minutes', 'capacity', 'requires_room', 'is_active']));
        Schema::table('official_holidays', fn (Blueprint $table) => $table->dropColumn('gregorian_date'));
    }
};
