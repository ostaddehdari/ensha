<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private array $permissionSlugs = [
        'payments.view', 'payments.create', 'payments.refund', 'payments.void',
        'cash_register.view', 'cash_register.manage', 'finance.reports.view',
    ];

    public function up(): void
    {
        Schema::create('financial_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('centre_id')->constrained()->cascadeOnDelete();
            $table->string('sequence_key', 32);
            $table->unsignedSmallInteger('sequence_year');
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();
            $table->unique(['centre_id', 'sequence_key', 'sequence_year'], 'financial_sequences_scope_unique');
        });

        Schema::create('cash_register_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('session_number', 48)->unique();
            $table->foreignId('centre_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('centre_branches')->nullOnDelete();
            $table->foreignId('cashier_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('opened_at');
            $table->unsignedBigInteger('opening_cash_amount')->default(0);
            $table->string('status', 16)->default('open')->index();
            $table->bigInteger('cash_payments_amount')->default(0);
            $table->bigInteger('cash_refunds_amount')->default(0);
            $table->bigInteger('non_cash_net_amount')->default(0);
            $table->bigInteger('expected_cash_amount')->default(0);
            $table->unsignedBigInteger('counted_cash_amount')->nullable();
            $table->bigInteger('difference_amount')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('closed_at')->nullable();
            $table->text('opening_note')->nullable();
            $table->text('closing_note')->nullable();
            $table->timestamps();
            $table->index(['centre_id', 'opened_at'], 'cash_register_centre_date');
            $table->index(['cashier_id', 'status'], 'cash_register_cashier_status');
        });

        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('transaction_number', 48)->unique();
            $table->foreignId('centre_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('centre_branches')->nullOnDelete();
            $table->foreignId('appointment_id')->constrained('appointments')->restrictOnDelete();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->foreignId('cash_register_session_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('parent_transaction_id')->nullable()->constrained('payment_transactions')->restrictOnDelete();
            $table->string('kind', 16)->index();
            $table->string('method', 24)->index();
            $table->unsignedBigInteger('amount');
            $table->bigInteger('signed_amount');
            $table->char('currency', 3)->default('IRR');
            $table->string('status', 16)->default('posted')->index();
            $table->string('reference_number', 100)->nullable();
            $table->string('idempotency_key', 100)->nullable()->unique();
            $table->text('note')->nullable();
            $table->json('metadata')->nullable();
            $table->dateTime('occurred_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['centre_id', 'occurred_at'], 'payment_transactions_centre_date');
            $table->index(['appointment_id', 'occurred_at'], 'payment_transactions_appointment_date');
        });

        Schema::create('payment_receipts', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('receipt_number', 48)->unique();
            $table->foreignId('transaction_id')->unique()->constrained('payment_transactions')->restrictOnDelete();
            $table->longText('snapshot');
            $table->dateTime('issued_at');
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->string('payment_status', 16)->default('unpaid')->index()->after('balance_amount');
            $table->dateTime('financial_updated_at')->nullable()->after('payment_status');
        });

        $this->seedAccessControl();
        $this->backfillLegacyPayments();
    }

    private function seedAccessControl(): void
    {
        $now = now();
        DB::table('roles')->updateOrInsert(['slug' => 'finance'], [
            'name' => 'مسئول مالی', 'description' => 'صندوق، دریافت‌ها، برگشت وجه و گزارش‌های مالی مرکز',
            'scope' => 'centre', 'color' => 'violet', 'sort_order' => 45,
            'is_system' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $definitions = [
            ['payments.view', 'مشاهده تراکنش‌ها و رسیدها', 'finance', 'مالی و صندوق', 'مشاهده دفتر تراکنش‌های مرکز', 310],
            ['payments.create', 'ثبت دریافت', 'finance', 'مالی و صندوق', 'ثبت پرداخت کامل یا مرحله‌ای برای نوبت', 311],
            ['payments.refund', 'برگشت وجه', 'finance', 'مالی و صندوق', 'ثبت برگشت وجه با سند جبرانی', 312],
            ['payments.void', 'ابطال تراکنش', 'finance', 'مالی و صندوق', 'ابطال کنترل‌شده تراکنش با سند جبرانی', 313],
            ['cash_register.view', 'مشاهده صندوق روزانه', 'finance', 'مالی و صندوق', 'مشاهده گردش و وضعیت صندوق', 314],
            ['cash_register.manage', 'مدیریت صندوق روزانه', 'finance', 'مالی و صندوق', 'بازکردن، بستن و تطبیق صندوق', 315],
            ['finance.reports.view', 'گزارش مالی مرکز', 'finance', 'مالی و صندوق', 'مشاهده جمع دریافت‌ها و مانده‌ها', 316],
        ];
        foreach ($definitions as [$slug, $name, $groupKey, $groupName, $description, $sortOrder]) {
            DB::table('permissions')->updateOrInsert(['slug' => $slug], [
                'name' => $name, 'group_key' => $groupKey, 'group_name' => $groupName,
                'description' => $description, 'sort_order' => $sortOrder,
                'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $all = array_column($definitions, 0);
        $grants = [
            'super_admin' => $all,
            'manager' => $all,
            'finance' => array_merge($all, ['dashboard.view', 'appointments.view', 'clients.view']),
            'secretary' => ['payments.view', 'payments.create', 'cash_register.view', 'cash_register.manage'],
        ];
        foreach ($grants as $roleSlug => $slugs) {
            $roleId = DB::table('roles')->where('slug', $roleSlug)->value('id');
            if (! $roleId) continue;
            foreach (DB::table('permissions')->whereIn('slug', $slugs)->pluck('id') as $permissionId) {
                DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
            }
        }
    }

    private function backfillLegacyPayments(): void
    {
        DB::table('appointments')->orderBy('id')->chunkById(200, function ($appointments) {
            foreach ($appointments as $appointment) {
                $paid = (int) $appointment->paid_amount;
                if ($paid > 0 && ! DB::table('payment_transactions')->where('appointment_id', $appointment->id)->exists()) {
                    $transactionId = DB::table('payment_transactions')->insertGetId([
                        'public_id' => (string) Str::uuid(),
                        'transaction_number' => 'LEG-TRX-'.str_pad((string) $appointment->id, 10, '0', STR_PAD_LEFT),
                        'centre_id' => $appointment->centre_id, 'branch_id' => $appointment->branch_id,
                        'appointment_id' => $appointment->id, 'client_id' => $appointment->client_id,
                        'kind' => 'payment', 'method' => 'legacy', 'amount' => $paid, 'signed_amount' => $paid,
                        'currency' => $appointment->currency ?: 'IRR', 'status' => 'posted',
                        'note' => $appointment->payment_note ?: 'انتقال مانده پرداخت قبلی به دفتر تراکنش Stage 09',
                        'metadata' => json_encode(['source' => 'stage09_backfill'], JSON_UNESCAPED_UNICODE),
                        'occurred_at' => $appointment->created_at ?: now(), 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $snapshot = json_encode([
                        'legacy' => true, 'appointment_id' => $appointment->id,
                        'appointment_number' => $appointment->appointment_number, 'amount' => $paid,
                        'paid_after' => $paid, 'balance_after' => max(0, (int) $appointment->final_price - $paid),
                    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                    DB::table('payment_receipts')->insert([
                        'public_id' => (string) Str::uuid(),
                        'receipt_number' => 'LEG-RC-'.str_pad((string) $appointment->id, 10, '0', STR_PAD_LEFT),
                        'transaction_id' => $transactionId, 'snapshot' => Crypt::encryptString($snapshot),
                        'issued_at' => $appointment->created_at ?: now(), 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                $final = (int) $appointment->final_price;
                $status = $paid <= 0 ? 'unpaid' : ($paid < $final ? 'partial' : ($paid === $final ? 'paid' : 'credit'));
                DB::table('appointments')->where('id', $appointment->id)->update([
                    'balance_amount' => max(0, $final - $paid), 'payment_status' => $status,
                    'financial_updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('appointments', fn (Blueprint $table) => $table->dropColumn(['payment_status', 'financial_updated_at']));
        Schema::dropIfExists('payment_receipts');
        Schema::dropIfExists('payment_transactions');
        Schema::dropIfExists('cash_register_sessions');
        Schema::dropIfExists('financial_sequences');
        $permissionIds = DB::table('permissions')->whereIn('slug', $this->permissionSlugs)->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }
};
