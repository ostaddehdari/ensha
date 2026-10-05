<!doctype html>
<html class="h-full" data-kt-theme="true" data-kt-theme-mode="light" dir="rtl" lang="fa">
<head>
    <meta charset="utf-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>{{ $title ?? 'داشبورد' }} | انشا</title>
    <link rel="icon" href="{{ asset('assets/media/app/favicon.ico') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Vazirmatn:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('assets/vendors/apexcharts/apexcharts.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/vendors/keenicons/styles.bundle.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/styles.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/core.bundle.css') }}">
    <link rel="stylesheet" href="{{ asset('css/ensha.css').'?v=0.9.0' }}">
    <link rel="stylesheet" href="{{ asset('css/ensha-metronic.css') }}">
    @stack('head')
</head>
<body class="antialiased flex h-full text-base text-foreground bg-background demo1 kt-sidebar-fixed kt-header-fixed ensha-metronic-body">
<script>
    const defaultThemeMode = 'light';
    let themeMode = localStorage.getItem('kt-theme') || defaultThemeMode;
    if (themeMode === 'system') themeMode = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    document.documentElement.classList.add(themeMode);
</script>

<div class="flex grow">
    @include('layouts.sidebar')

    <div class="kt-wrapper flex grow flex-col">
        <header class="kt-header fixed top-0 z-10 start-0 end-0 flex items-stretch shrink-0 bg-background" data-kt-sticky="true" data-kt-sticky-class="border-b border-border" data-kt-sticky-name="header" id="header">
            <div class="kt-container-fixed flex justify-between items-stretch lg:gap-4" id="headerContainer">
                <div class="flex items-center gap-2.5 lg:hidden">
                    <a class="ensha-mobile-brand" href="{{ route('dashboard') }}"><span class="ensha-kt-brand-mark small">ا</span><strong>انشا</strong></a>
                    <button class="kt-btn kt-btn-icon kt-btn-ghost" data-kt-drawer-toggle="#sidebar" type="button" aria-label="نمایش منوی اصلی"><i class="ki-filled ki-menu"></i></button>
                </div>
                <div class="hidden lg:flex items-center gap-3">
                    <div class="ensha-header-page-icon"><i class="ki-filled ki-element-11"></i></div>
                    <div><span class="ensha-header-kicker">مرکز مشاوره انشا</span><strong class="ensha-header-title">{{ $title ?? 'داشبورد' }}</strong></div>
                </div>
                <div class="flex items-center gap-1.5">
                    <button class="kt-btn kt-btn-ghost kt-btn-icon size-9 rounded-full hover:bg-primary/10 hover:[&_i]:text-primary" data-kt-modal-toggle="#ensha_search_modal" type="button" title="جست‌وجو"><i class="ki-filled ki-magnifier text-lg"></i></button>
                    <button class="kt-btn kt-btn-ghost kt-btn-icon size-9 rounded-full hover:bg-primary/10 hover:[&_i]:text-primary relative" data-kt-drawer-toggle="#notifications_drawer" type="button" title="اعلان‌ها"><i class="ki-filled ki-notification-status text-lg"></i><span class="ensha-kt-indicator" data-ensha-unread-count>۳</span></button>
                    <button class="kt-btn kt-btn-ghost kt-btn-icon size-9 rounded-full hover:bg-primary/10 hover:[&_i]:text-primary" data-kt-drawer-toggle="#chat_drawer" type="button" title="چت"><i class="ki-filled ki-messages text-lg"></i></button>
                    <div class="h-5 border-s border-border mx-1"></div>
                    <div class="kt-menu" data-kt-menu="true">
                        <div class="kt-menu-item" data-kt-menu-item-toggle="dropdown" data-kt-menu-item-trigger="click|lg:hover" data-kt-menu-item-placement="bottom-end" data-kt-menu-item-placement-rtl="bottom-start">
                            <button class="kt-menu-toggle flex items-center gap-2 rounded-full p-1 hover:bg-accent/60" type="button">
                                <span class="ensha-kt-avatar">@if(auth()->user()->avatar_url)<img src="{{ auth()->user()->avatar_url }}" alt="" style="width:100%;height:100%;border-radius:50%;object-fit:cover">@else{{ mb_substr(auth()->user()->display_name, 0, 1) }}@endif</span>
                                <span class="hidden sm:flex flex-col items-start leading-none me-1"><span class="text-2sm font-semibold text-mono">{{ auth()->user()->display_name }}</span><span class="text-2xs text-muted-foreground mt-1">{{ auth()->user()->role_label }}</span></span>
                                <i class="ki-filled ki-down text-xs text-muted-foreground"></i>
                            </button>
                            <div class="kt-menu-dropdown kt-menu-default w-56">
                                <div class="px-3 py-3 border-b border-border"><strong class="text-sm text-mono">{{ auth()->user()->display_name }}</strong><span class="block text-2xs text-muted-foreground mt-1" dir="ltr">{{ auth()->user()->phone }}</span></div>
                                <div class="kt-menu-item"><a class="kt-menu-link" href="{{ route('profile.show') }}"><span class="kt-menu-icon"><i class="ki-filled ki-profile-circle"></i></span><span class="kt-menu-title">پروفایل من</span></a></div>
                                <div class="kt-menu-item"><a class="kt-menu-link" href="{{ route('account.roles') }}">تغییر نقش و مرکز</a></div>
                                <div class="kt-menu-separator"></div>
                                <div class="kt-menu-item"><form method="POST" action="{{ route('logout') }}">@csrf<button class="kt-menu-link w-full text-right" type="submit"><span class="kt-menu-icon"><i class="ki-filled ki-exit-right"></i></span><span class="kt-menu-title">خروج از سامانه</span></button></form></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <main class="grow">
            <div class="kt-container-fixed py-6 lg:py-8 ensha-content">
                @if(session()->has('impersonator_id'))
                    <div class="ensha-impersonation-banner">
                        <span><i class="ki-filled ki-information-2"></i><strong>حالت ورود موقت فعال است</strong> پنل را با هویت {{ auth()->user()->display_name }} مشاهده می‌کنید و تمام عملیات ثبت می‌شود.</span>
                        <form method="POST" action="{{ route('impersonation.stop') }}">@csrf<button type="submit"><i class="ki-filled ki-exit-left"></i> بازگشت به حساب ادمین</button></form>
                    </div>
                @endif
                @if(session('success'))
                    <div class="ensha-alert success"><i class="ki-filled ki-check-circle"></i>{{ session('success') }}</div>
                @endif
                @if(session('warning'))
                    <div class="ensha-alert warning"><i class="ki-filled ki-information-2"></i>{{ session('warning') }}</div>
                @endif
                @if($errors->any())
                    <div class="ensha-alert danger"><i class="ki-filled ki-information-2"></i><span>{{ $errors->first() }}</span></div>
                @endif
                @yield('content')
            </div>
        </main>
    </div>
</div>

<div class="hidden kt-drawer kt-drawer-end card flex-col max-w-[90%] w-[450px] top-5 bottom-5 end-5 rounded-xl border border-border" data-kt-drawer="true" data-kt-drawer-container="body" id="notifications_drawer">
    <div class="flex items-center justify-between gap-2.5 text-sm text-mono font-semibold px-5 py-3.5 border-b border-b-border">
        <div class="flex items-center gap-2"><i class="ki-filled ki-notification-status text-primary"></i> اعلان‌های انشا <span class="kt-badge kt-badge-sm kt-badge-primary" data-ensha-unread-label>۳ جدید</span></div>
        <button class="kt-btn kt-btn-sm kt-btn-icon kt-btn-dim shrink-0" data-kt-drawer-dismiss="true" type="button"><i class="ki-filled ki-cross"></i></button>
    </div>
    <div class="kt-tabs kt-tabs-line justify-between px-5" data-kt-tabs="true"><div class="flex items-center gap-5"><button class="kt-tab-toggle py-3 active" data-kt-tab-toggle="#ensha_notifications_all" type="button">همه</button><button class="kt-tab-toggle py-3" data-kt-tab-toggle="#ensha_notifications_unread" type="button">خوانده‌نشده</button></div></div>
    <div class="grow overflow-auto" id="ensha_notifications_all"><div class="flex flex-col" data-ensha-notifications-list>
        <button class="flex items-start gap-3 text-right px-5 py-4 border-b border-border hover:bg-accent/50" data-ensha-notification type="button"><span class="flex items-center justify-center rounded-full bg-primary/10 text-primary size-9 shrink-0"><i class="ki-filled ki-shield-tick"></i></span><span class="grow"><strong class="block text-sm font-semibold text-mono">ورود امن به پنل</strong><span class="block text-2sm text-secondary-foreground mt-1">ورود اخیر شما با نقش {{ auth()->user()->role_label }} ثبت شد.</span><span class="block text-2xs text-muted-foreground mt-2">همین حالا</span></span><span class="ensha-notification-unread"></span></button>
        <button class="flex items-start gap-3 text-right px-5 py-4 border-b border-border hover:bg-accent/50" data-ensha-notification type="button"><span class="flex items-center justify-center rounded-full bg-success/10 text-success size-9 shrink-0"><i class="ki-filled ki-check-circle"></i></span><span class="grow"><strong class="block text-sm font-semibold text-mono">نسخه پایه آماده است</strong><span class="block text-2sm text-secondary-foreground mt-1">ساختار نقش‌ها و منوی پنل شما آمادهٔ توسعه است.</span><span class="block text-2xs text-muted-foreground mt-2">امروز، ۰۸:۴۰</span></span><span class="ensha-notification-unread"></span></button>
        <button class="flex items-start gap-3 text-right px-5 py-4 border-b border-border hover:bg-accent/50" data-ensha-notification type="button"><span class="flex items-center justify-center rounded-full bg-warning/10 text-warning size-9 shrink-0"><i class="ki-filled ki-calendar-tick"></i></span><span class="grow"><strong class="block text-sm font-semibold text-mono">تقویم نوبت‌دهی</strong><span class="block text-2sm text-secondary-foreground mt-1">موتور نوبت و برنامه کاری Stage 03 فعال است.</span><span class="block text-2xs text-muted-foreground mt-2">امروز</span></span><span class="ensha-notification-unread"></span></button>
    </div></div>
    <div class="hidden grow overflow-auto" id="ensha_notifications_unread"><div class="p-8 text-center text-sm text-muted-foreground">برای مشاهدهٔ وضعیت، روی اعلان‌ها بزنید یا همه را خوانده‌شده کنید.</div></div>
    <div class="grid grid-cols-2 p-5 gap-2.5 border-t border-border"><button class="kt-btn kt-btn-outline justify-center" data-ensha-mark-all type="button">همه خوانده شد</button><button class="kt-btn kt-btn-primary justify-center" data-kt-drawer-dismiss="true" type="button">بستن</button></div>
</div>

<div class="hidden kt-drawer kt-drawer-end card flex-col max-w-[90%] w-[450px] top-5 bottom-5 end-5 rounded-xl border border-border" data-kt-drawer="true" data-kt-drawer-container="body" id="chat_drawer">
    <div class="flex items-center justify-between gap-2.5 text-sm text-mono font-semibold px-5 py-3.5 border-b border-b-border"><div class="flex items-center gap-2"><i class="ki-filled ki-messages text-primary"></i> گفتگوی داخلی</div><button class="kt-btn kt-btn-sm kt-btn-icon kt-btn-dim shrink-0" data-kt-drawer-dismiss="true" type="button"><i class="ki-filled ki-cross"></i></button></div>
    <div class="flex items-center gap-3 px-5 py-4 border-b border-border"><span class="ensha-chat-avatar">ا</span><div><strong class="block text-sm font-semibold text-mono">تیم مرکز مشاوره انشا</strong><span class="block text-2xs text-success mt-1"><span class="inline-block rounded-full size-1.5 bg-success align-middle me-1"></span> آماده پاسخ‌گویی</span></div></div>
    <div class="grow overflow-auto p-5 space-y-3" id="ensha-chat-stream"><div class="flex items-end gap-2"><span class="ensha-chat-avatar mini">ا</span><div class="ensha-chat-bubble incoming">سلام {{ auth()->user()->first_name }}، این بخش برای چت داخلی آماده شده است.<small>اکنون</small></div></div><div class="flex items-end justify-end gap-2"><div class="ensha-chat-bubble outgoing">ممنون، آمادهٔ اتصال به پیام‌رسان واقعی است.<small>اکنون</small></div></div></div>
    <form class="flex items-center gap-2 p-4 border-t border-border" data-ensha-chat-form><input class="kt-input grow" id="ensha_chat_input" name="message" placeholder="پیام خود را بنویسید..." autocomplete="off"><button class="kt-btn kt-btn-primary kt-btn-icon" type="submit" title="ارسال"><i class="ki-filled ki-send"></i></button></form>
</div>

<div class="kt-modal" data-kt-modal="true" id="ensha_search_modal"><div class="kt-modal-content max-w-[520px]"><div class="flex items-center justify-between px-5 py-4 border-b border-border"><strong class="text-sm text-mono">جست‌وجوی سریع</strong><button class="kt-btn kt-btn-sm kt-btn-icon kt-btn-dim" data-kt-modal-dismiss="true" type="button"><i class="ki-filled ki-cross"></i></button></div><div class="p-5"><div class="kt-input-group"><i class="ki-filled ki-magnifier text-muted-foreground"></i><input class="kt-input" data-ensha-search-input placeholder="نام کاربر یا ماژول را جست‌وجو کنید..."></div><div class="mt-4 text-2sm text-muted-foreground" data-ensha-search-result>برای شروع جست‌وجو عبارت خود را وارد کنید.</div></div></div></div>
<div class="ensha-metronic-toast" data-ensha-toast hidden></div>

<script src="{{ asset('assets/js/core.bundle.js') }}"></script>
<script src="{{ asset('assets/vendors/ktui/ktui.min.js') }}"></script>
<script src="{{ asset('assets/vendors/apexcharts/apexcharts.min.js') }}"></script>
<script src="{{ asset('assets/js/widgets/general.js') }}"></script>
<script src="{{ asset('assets/js/layouts/demo1.js') }}"></script>
<script src="{{ asset('js/ensha.js') }}"></script>
@stack('scripts')
</body>
</html>
