<?php

namespace Database\Seeders;

use App\Models\Centre;
use App\Models\ProfileField;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            ['first_name' => 'مدیر', 'last_name' => 'سیستم', 'phone' => '09120000000', 'national_id' => '1234567891', 'password' => 'ChangeMe123!', 'role' => 'super_admin'],
            ['first_name' => 'مدیر', 'last_name' => 'مرکز', 'phone' => '09120000001', 'national_id' => '1112223339', 'password' => 'ChangeMe123!', 'role' => 'manager'],
            ['first_name' => 'منشی', 'last_name' => 'نمونه', 'phone' => '09120000002', 'national_id' => '2345678909', 'password' => 'ChangeMe123!', 'role' => 'secretary'],
            ['first_name' => 'مشاور', 'last_name' => 'نمونه', 'phone' => '09120000003', 'national_id' => '3456789017', 'password' => 'ChangeMe123!', 'role' => 'counselor'],
            ['first_name' => 'مسئول', 'last_name' => 'تست', 'phone' => '09120000004', 'national_id' => '4567890124', 'password' => 'ChangeMe123!', 'role' => 'test_manager'],
        ];

        $defaultCentre = Centre::query()->where('code', 'ENSHA-MAIN')->firstOrFail();
        foreach ($users as $data) {
            $role = Role::query()->where('slug', $data['role'])->firstOrFail();
            $user = User::firstOrCreate(
                ['phone' => $data['phone']],
                [
                    ...$data,
                    'name' => $data['first_name'].' '.$data['last_name'],
                    'role_id' => $role->id,
                    'centre_id' => $data['role'] === 'super_admin' ? null : $defaultCentre->id,
                    'is_active' => true,
                    'status' => 'active',
                    'status_changed_at' => now(),
                    'password_changed_at' => null,
                    'must_change_password' => true,
                ]
            );

            $user->forceFill([
                'role' => $data['role'],
                'role_id' => $role->id,
                'centre_id' => $data['role'] === 'super_admin' ? null : ($user->centre_id ?: $defaultCentre->id),
                'status' => $user->is_active ? 'active' : 'inactive',
            ]);
            if (preg_match('/^000000000[0-4]$/', (string) $user->national_id)) {
                $user->national_id = $data['national_id'];
            }
            $user->saveQuietly();
        }

        $fields = [
            ['role' => 'super_admin', 'label' => 'واحد سازمانی', 'key' => 'department', 'field_type' => 'text'],
            ['role' => 'manager', 'label' => 'کد مرکز مشاوره', 'key' => 'centre_code', 'field_type' => 'text'],
            ['role' => 'counselor', 'label' => 'تخصص اصلی', 'key' => 'specialty', 'field_type' => 'text'],
            ['role' => 'counselor', 'label' => 'شماره نظام روان‌شناسی', 'key' => 'licence_number', 'field_type' => 'text'],
            ['role' => 'counselor', 'label' => 'مبلغ هر جلسه (ریال)', 'key' => 'session_fee', 'field_type' => 'number'],
            ['role' => 'client', 'label' => 'تاریخ تولد', 'key' => 'birth_date', 'field_type' => 'date'],
            ['role' => 'client', 'label' => 'جنسیت', 'key' => 'gender', 'field_type' => 'select', 'options' => ['زن', 'مرد', 'ترجیح می‌دهم نگویم']],
        ];

        foreach ($fields as $index => $field) {
            ProfileField::updateOrCreate(
                ['role' => $field['role'], 'key' => $field['key']],
                [...$field, 'sort_order' => $index, 'is_active' => true]
            );
        }
    }
}
