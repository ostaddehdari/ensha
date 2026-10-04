<div class="ensha-module-tabs" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:18px">
    @if(auth()->user()->hasPermission('fees.view'))
        <a class="{{ request()->routeIs('centres.topics', 'centres.operations') ? 'ensha-primary-btn' : 'ensha-secondary-btn' }}" href="{{ route('centres.topics', $centre) }}">موضوعات و تعرفه</a>
    @endif
    @if(auth()->user()->hasPermission('schedules.view'))
        <a class="{{ request()->routeIs('centres.work-hours') ? 'ensha-primary-btn' : 'ensha-secondary-btn' }}" href="{{ route('centres.work-hours', $centre) }}">روزها و ساعات کاری</a>
        <a class="{{ request()->routeIs('centres.holidays') ? 'ensha-primary-btn' : 'ensha-secondary-btn' }}" href="{{ route('centres.holidays', $centre) }}">تعطیلات مرکز</a>
        <a class="{{ request()->routeIs('centres.leaves') ? 'ensha-primary-btn' : 'ensha-secondary-btn' }}" href="{{ route('centres.leaves', $centre) }}">مرخصی مشاوران</a>
        <a class="{{ request()->routeIs('centres.rooms') ? 'ensha-primary-btn' : 'ensha-secondary-btn' }}" href="{{ route('centres.rooms', $centre) }}">اتاق‌ها</a>
    @endif
</div>
