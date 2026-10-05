<nav class="flex flex-wrap gap-2 mb-4">
    <a class="ensha-secondary-btn" href="{{ route('appointments.index') }}">نوبت‌ها</a>
    <a class="ensha-primary-btn" href="{{ route('appointments.calendar') }}">تقویم منشی</a>
    <a class="ensha-secondary-btn" href="{{ route('operations.index') }}">عملیات روزانه</a>
    <a class="ensha-secondary-btn" href="{{ route('operations.telephone') }}">مشاوره تلفنی</a>
    @if(auth()->user()->hasPermission('appointments.manage'))<a class="ensha-secondary-btn" href="{{ route('appointments.create') }}">ثبت نوبت</a>@endif
    <a class="ensha-secondary-btn" href="{{ route('appointments.slots.index') }}">اسلات‌های کاری</a>
    @if(auth()->user()->hasPermission('tariffs.view'))<a class="ensha-secondary-btn" href="{{ route('appointments.tariffs.index') }}">تعرفه‌های نسخه‌دار</a>@endif
</nav>
