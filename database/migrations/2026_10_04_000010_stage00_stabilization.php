<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('official_holidays', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('jalali_year')->index();
            $table->char('jalali_date', 10);
            $table->string('title', 300);
            $table->string('lunar_label', 120)->nullable();
            $table->string('source', 300)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->unique(['jalali_year', 'jalali_date']);
        });

        $this->seedOfficialHolidays();
        $this->preserveSecretaryReadAccess();
    }

    private function seedOfficialHolidays(): void
    {
        $source = 'تقویم رسمی سال ۱۴۰۵؛ شورای مرکز تقویم مؤسسه ژئوفیزیک دانشگاه تهران';
        $now = now();
        $holidays = [
            ['1405/01/01', 'آغاز نوروز و عید سعید فطر', '۱ شوال ۱۴۴۷'],
            ['1405/01/02', 'عید نوروز و تعطیل عید سعید فطر', '۲ شوال ۱۴۴۷'],
            ['1405/01/03', 'عید نوروز', null],
            ['1405/01/04', 'عید نوروز', null],
            ['1405/01/12', 'روز جمهوری اسلامی ایران', null],
            ['1405/01/13', 'روز طبیعت', null],
            ['1405/01/25', 'شهادت امام جعفر صادق (ع)', '۲۵ شوال ۱۴۴۷'],
            ['1405/03/06', 'عید سعید قربان', '۱۰ ذی‌الحجه ۱۴۴۷'],
            ['1405/03/14', 'عید سعید غدیر خم و رحلت امام خمینی (ره)', '۱۸ ذی‌الحجه ۱۴۴۷'],
            ['1405/03/15', 'قیام ۱۵ خرداد', '۱۹ ذی‌الحجه ۱۴۴۷'],
            ['1405/04/03', 'تاسوعای حسینی', '۹ محرم ۱۴۴۸'],
            ['1405/04/04', 'عاشورای حسینی', '۱۰ محرم ۱۴۴۸'],
            ['1405/05/13', 'اربعین حسینی', '۲۰ صفر ۱۴۴۸'],
            ['1405/05/21', 'رحلت رسول اکرم (ص) و شهادت امام حسن مجتبی (ع)', '۲۸ صفر ۱۴۴۸'],
            ['1405/05/22', 'شهادت امام رضا (ع)', '۲۹ صفر ۱۴۴۸'],
            ['1405/05/30', 'شهادت امام حسن عسکری (ع)', '۸ ربیع‌الاول ۱۴۴۸'],
            ['1405/06/08', 'میلاد رسول اکرم (ص) و امام جعفر صادق (ع)', '۱۷ ربیع‌الاول ۱۴۴۸'],
            ['1405/08/22', 'شهادت حضرت فاطمه زهرا (س)', '۳ جمادی‌الثانی ۱۴۴۸'],
            ['1405/10/02', 'میلاد امام علی (ع) و روز پدر', '۱۳ رجب ۱۴۴۸'],
            ['1405/10/16', 'مبعث رسول اکرم (ص)', '۲۷ رجب ۱۴۴۸'],
            ['1405/11/04', 'میلاد حضرت قائم (عج)', '۱۵ شعبان ۱۴۴۸'],
            ['1405/11/22', 'پیروزی انقلاب اسلامی ایران', null],
            ['1405/12/09', 'شهادت امام علی (ع)', '۲۱ رمضان ۱۴۴۸'],
            ['1405/12/19', 'عید سعید فطر', '۱ شوال ۱۴۴۸'],
            ['1405/12/20', 'تعطیل به مناسبت عید سعید فطر', '۲ شوال ۱۴۴۸'],
            ['1405/12/29', 'روز ملی شدن صنعت نفت ایران', null],
        ];

        foreach ($holidays as [$date, $title, $lunar]) {
            DB::table('official_holidays')->insertOrIgnore([
                'jalali_year' => 1405,
                'jalali_date' => $date,
                'title' => $title,
                'lunar_label' => $lunar,
                'source' => $source,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function preserveSecretaryReadAccess(): void
    {
        $roleId = DB::table('roles')->where('slug', 'secretary')->value('id');
        $permissionIds = DB::table('permissions')
            ->whereIn('slug', ['counselors.view', 'schedules.view', 'fees.view'])
            ->pluck('id');

        foreach ($permissionIds as $permissionId) {
            DB::table('permission_role')->insertOrIgnore([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
            ]);
        }
    }

    public function down(): void
    {
        $roleId = DB::table('roles')->where('slug', 'secretary')->value('id');
        $permissionIds = DB::table('permissions')
            ->whereIn('slug', ['counselors.view', 'schedules.view', 'fees.view'])
            ->pluck('id');
        DB::table('permission_role')->where('role_id', $roleId)->whereIn('permission_id', $permissionIds)->delete();
        Schema::dropIfExists('official_holidays');
    }
};
