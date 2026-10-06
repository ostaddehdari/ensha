@php
    $actor = auth()->user();
    $role = $actor->assignedRole?->slug ?? $actor->role;
    $items = collect(array_merge(config('panels.menus.common', []), config("panels.menus.{$role}", [])))
        ->filter(fn ($item) => isset($item['section']) || (
            isset($item['route']) && \Illuminate\Support\Facades\Route::has($item['route'])
            && (empty($item['centre']) || $actor->centre_id)
            && (empty($item['permission']) || $actor->hasPermission($item['permission']))
        ))
        ->values();
    // Remove headings with no visible child links.
    $items = $items->filter(function ($item, $index) use ($items) {
        if (! isset($item['section'])) return true;
        return $items->slice($index + 1)->takeUntil(fn ($next) => isset($next['section']))->contains(fn ($next) => isset($next['route']));
    });
@endphp

<div class="kt-sidebar bg-background border-e border-e-border fixed top-0 bottom-0 z-20 hidden lg:flex flex-col items-stretch shrink-0 [--kt-drawer-enable:true] lg:[--kt-drawer-enable:false] ensha-light-sidebar" data-kt-drawer="true" data-kt-drawer-class="kt-drawer kt-drawer-start top-0 bottom-0" id="sidebar">
    <div class="kt-sidebar-header hidden lg:flex items-center relative justify-between px-3 lg:px-6 shrink-0" id="sidebar_header">
        <div class="kt-sidebar-logo min-w-0"><a class="ensha-kt-brand" href="{{ route('dashboard') }}"><span class="ensha-kt-brand-mark">ا</span><span class="ensha-kt-brand-copy"><strong>انشا</strong><small>مرکز مشاوره</small></span></a></div>
        <button class="kt-btn kt-btn-outline kt-btn-icon size-[30px] absolute start-full top-2/4 z-40 -translate-x-2/4 -translate-y-2/4 rtl:translate-x-2/4" data-kt-toggle="body" data-kt-toggle-class="kt-sidebar-collapse" id="sidebar_toggle" type="button"><i class="ki-filled ki-black-left-line kt-toggle-active:rotate-180 transition-all duration-300 rtl:translate rtl:rotate-180 rtl:kt-toggle-active:rotate-0"></i></button>
    </div>
    <div class="kt-sidebar-content flex grow shrink-0 py-5 pe-2" id="sidebar_content">
        <div class="kt-scrollable-y-hover grow shrink-0 flex ps-2 lg:ps-5 pe-1 lg:pe-3" data-kt-scrollable="true" data-kt-scrollable-dependencies="#sidebar_header, #sidebar_footer" data-kt-scrollable-height="auto" data-kt-scrollable-offset="0px" data-kt-scrollable-wrappers="#sidebar_content" id="sidebar_scrollable">
            <div class="kt-menu flex flex-col grow gap-1" data-kt-menu="true" data-kt-menu-accordion-expand-all="false" id="sidebar_menu">
                <div class="kt-menu-item pt-2.25 pb-px"><span class="kt-menu-heading uppercase text-xs font-medium text-muted-foreground ps-[10px] pe-[10px]">پنل عملیاتی</span></div>
                @foreach($items as $item)
                    @if(isset($item['section']))
                        <div class="kt-menu-item pt-2.25 pb-px"><span class="kt-menu-heading uppercase text-xs font-medium text-muted-foreground ps-[10px] pe-[10px]">{{ $item['section'] }}</span></div>
                        @continue
                    @endif
                    @php
                        $href = ! empty($item['centre']) ? route($item['route'], $actor->centre_id) : route($item['route']);
                        $isActive = request()->routeIs($item['route']) || match ($item['slug'] ?? '') {
                            'profile' => request()->routeIs('profile.*'),
                            'users' => request()->routeIs('users.*'),
                            'roles' => request()->routeIs('roles.*', 'permissions.*'),
                            'centres' => request()->routeIs('centres.index', 'centres.show'),
                            'clients' => request()->routeIs('clients.index', 'clients.show', 'clients.edit'),
                            'cases' => request()->routeIs('cases.*'),
                            'appointments' => request()->routeIs('appointments.calendar', 'appointments.show'),
                            default => false,
                        };
                    @endphp
                    <div class="kt-menu-item {{ $isActive ? 'active' : '' }}"><a class="kt-menu-link border border-transparent items-center grow kt-menu-item-active:bg-accent/60 kt-menu-item-active:rounded-lg hover:bg-accent/60 hover:rounded-lg gap-[10px] ps-[10px] pe-[10px] py-[8px] {{ $isActive ? 'active bg-accent/60 rounded-lg' : '' }}" href="{{ $href }}"><span class="kt-menu-icon items-start text-muted-foreground w-[20px]"><i class="{{ $item['icon'] ?? 'ki-filled ki-element-11' }} text-lg"></i></span><span class="kt-menu-title text-sm font-medium text-foreground kt-menu-item-active:text-primary">{{ $item['label'] }}</span></a></div>
                @endforeach
            </div>
        </div>
    </div>
    <div class="kt-sidebar-footer border-t border-border p-4" id="sidebar_footer"><div class="flex items-center gap-2.5 mb-3 p-2.5 rounded-lg bg-success/5 border border-success/10"><i class="ki-filled ki-shield-tick text-success text-lg"></i><div><strong class="block text-2sm font-semibold text-mono">اتصال امن</strong><span class="block text-2xs text-muted-foreground mt-0.5">ورود نقش‌محور فعال است</span></div></div><form method="POST" action="{{ route('logout') }}">@csrf<button class="kt-btn kt-btn-outline w-full justify-center" type="submit"><i class="ki-filled ki-exit-right"></i> خروج از سامانه</button></form></div>
</div>
