@extends('layouts.app', ['title'=>'پرونده '.$case->case_number])
@section('content')
<div class="ensha-page-heading">
    <div><span class="ensha-eyebrow">پرونده مشاوره</span><h2>{{ $case->title }}</h2><p dir="ltr">{{ $case->case_number }} · {{ $case->client?->user?->display_name }}</p></div>
    @if(auth()->user()->hasPermission('cases.manage'))<a class="ensha-primary-btn" href="{{ route('cases.edit',$case) }}">ویرایش پرونده</a>@endif
</div>

<div class="grid gap-4 lg:grid-cols-2">
    <section class="ensha-card">
        <h3 class="text-lg font-semibold mb-4">خلاصه پرونده</h3>
        <dl class="grid grid-cols-2 gap-4">
            <div><dt class="text-muted-foreground">مراجع</dt><dd><a href="{{ route('clients.show',$case->client) }}">{{ $case->client?->user?->display_name }}</a></dd></div>
            <div><dt class="text-muted-foreground">وضعیت</dt><dd>{{ ['open'=>'باز','on_hold'=>'در انتظار','closed'=>'بسته','archived'=>'بایگانی‌شده'][$case->status] ?? $case->status }}</dd></div>
            <div><dt class="text-muted-foreground">اولویت</dt><dd>{{ ['normal'=>'عادی','high'=>'بالا','urgent'=>'فوری'][$case->priority] ?? $case->priority }}</dd></div>
            <div><dt class="text-muted-foreground">تاریخ شروع</dt><dd>{{ $case->opened_at?->format('Y-m-d') ?: '—' }}</dd></div>
            <div class="col-span-2"><dt class="text-muted-foreground">علت مراجعه</dt><dd class="whitespace-pre-line">{{ $case->presenting_issue ?: '—' }}</dd></div>
        </dl>
    </section>
    <section class="ensha-card">
        <h3 class="text-lg font-semibold mb-4">تخصیص‌های فعال</h3>
        @forelse($case->assignments->where('status','active') as $assignment)
            <div class="flex items-center justify-between border-b border-border py-3"><div><strong>{{ $assignment->user?->display_name }}</strong><span class="block text-sm text-muted-foreground">{{ ['counselor'=>'مشاور','case_manager'=>'مدیر پرونده','observer'=>'ناظر'][$assignment->assignment_role] ?? $assignment->assignment_role }} @if($assignment->is_primary) · اصلی @endif</span></div>@if(auth()->user()->hasPermission('cases.assign'))<form method="POST" action="{{ route('cases.assignments.destroy',[$case,$assignment]) }}">@csrf @method('DELETE')<button class="ensha-secondary-btn">پایان تخصیص</button></form>@endif</div>
        @empty<p class="text-muted-foreground">تخصیصی ثبت نشده است.</p>@endforelse
        @if(auth()->user()->hasPermission('cases.assign'))
            <form method="POST" action="{{ route('cases.assignments.store',$case) }}" class="mt-4 grid gap-3">@csrf
                <select class="kt-input" name="user_id" required><option value="">انتخاب کارمند</option>@foreach($assignees as $user)<option value="{{ $user->id }}">{{ $user->display_name }}</option>@endforeach</select>
                <select class="kt-input" name="assignment_role"><option value="counselor">مشاور</option><option value="case_manager">مدیر پرونده</option><option value="observer">ناظر</option></select>
                <label><input type="checkbox" name="is_primary" value="1"> تخصیص اصلی</label><button class="ensha-primary-btn">ثبت تخصیص</button>
            </form>
        @endif
    </section>
</div>

@if(auth()->user()->hasPermission('sessions.view'))
<section class="ensha-card mt-4">
    <div class="flex justify-between items-center mb-4"><h3 class="text-lg font-semibold">جلسات و یادداشت‌های محرمانه</h3><span class="text-sm text-muted-foreground">یادداشت نهایی قفل می‌شود و فقط الحاقیه می‌پذیرد.</span></div>
    @forelse($case->sessions as $session)
        <article class="border border-border rounded-lg p-4 mb-4">
            <div class="flex justify-between"><div><strong>جلسه {{ $session->session_number }}</strong><span class="block text-sm text-muted-foreground">{{ $session->counselor?->display_name ?: 'بدون مشاور' }} · {{ $session->scheduled_at?->format('Y-m-d H:i') ?: 'بدون زمان' }} · {{ $session->status }}</span></div><span>{{ ['in_person'=>'حضوری','phone'=>'تلفنی','video'=>'ویدئویی','chat'=>'چت'][$session->channel] ?? $session->channel }}</span></div>
            @if($session->administrative_summary)<p class="mt-3 whitespace-pre-line">{{ $session->administrative_summary }}</p>@endif
            @if(auth()->user()->hasPermission('notes.view'))
            <div class="mt-4 space-y-3">
                @foreach($session->notes as $note)
                    <div class="rounded-lg bg-accent/40 p-4">
                        <div class="flex justify-between"><strong>یادداشت {{ $note->status === 'finalized' ? 'نهایی' : 'پیش‌نویس' }}</strong><span class="text-sm text-muted-foreground">{{ $note->author?->display_name }}</span></div>
                        <p class="mt-3 whitespace-pre-line">{{ $note->body }}</p>
                        @if($note->status === 'draft' && auth()->user()->hasPermission('notes.manage'))
                            <form method="POST" action="{{ route('cases.notes.update',[$case,$note]) }}" class="mt-3 grid gap-2">@csrf @method('PUT')<textarea class="kt-input" name="body" required>{{ $note->body }}</textarea><button class="ensha-secondary-btn">ذخیره پیش‌نویس</button></form>
                            <form method="POST" action="{{ route('cases.notes.finalize',[$case,$note]) }}" class="mt-2">@csrf @method('PATCH')<button class="ensha-primary-btn">نهایی و قفل کن</button></form>
                        @endif
                        @foreach($note->addenda as $addendum)
                            <div class="mt-3 border-s-4 border-primary ps-3"><strong>الحاقیه</strong><p class="whitespace-pre-line">{{ $addendum->body }}</p><small>{{ $addendum->author?->display_name }} · {{ $addendum->created_at?->format('Y-m-d H:i') }}</small></div>
                        @endforeach
                        @if($note->status === 'finalized' && auth()->user()->hasPermission('notes.manage'))
                            <form method="POST" action="{{ route('cases.notes.addenda.store',[$case,$note]) }}" class="mt-3 flex gap-2">@csrf<input class="kt-input" name="body" placeholder="متن الحاقیه" required><button class="ensha-secondary-btn">ثبت الحاقیه</button></form>
                        @endif
                    </div>
                @endforeach
            </div>
            @if(auth()->user()->hasPermission('notes.manage'))
                <form method="POST" action="{{ route('cases.notes.store',[$case,$session]) }}" class="mt-4 grid gap-2">@csrf<textarea class="kt-input" name="body" rows="4" placeholder="یادداشت محرمانه جلسه" required></textarea><button class="ensha-primary-btn">ثبت پیش‌نویس یادداشت</button></form>
            @endif
            @endif
        </article>
    @empty<p class="text-muted-foreground">جلسه‌ای ثبت نشده است.</p>@endforelse

    @if(auth()->user()->hasPermission('sessions.manage'))
        <form method="POST" action="{{ route('cases.sessions.store',$case) }}" class="grid gap-3 border-t border-border pt-4">@csrf
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3"><select class="kt-input" name="counselor_id"><option value="">مشاور جاریِ تخصیص‌یافته</option>@foreach($case->assignments->where('status','active')->where('assignment_role','counselor') as $assignment)<option value="{{ $assignment->user_id }}">{{ $assignment->user?->display_name }}</option>@endforeach</select><input class="kt-input" type="datetime-local" name="scheduled_at"><select class="kt-input" name="channel"><option value="in_person">حضوری</option><option value="phone">تلفنی</option><option value="video">ویدئویی</option><option value="chat">چت</option></select><select class="kt-input" name="status"><option value="scheduled">برنامه‌ریزی‌شده</option><option value="completed">انجام‌شده</option><option value="cancelled">لغوشده</option><option value="no_show">عدم حضور</option></select></div>
            <textarea class="kt-input" name="administrative_summary" placeholder="خلاصه اداری غیرمحرمانه"></textarea><button class="ensha-primary-btn">ثبت جلسه</button>
        </form>
    @endif
</section>
@endif

<section class="ensha-card mt-4"><h3 class="text-lg font-semibold mb-4">تاریخچه وضعیت</h3>@foreach($case->statusHistories->sortByDesc('changed_at') as $history)<div class="border-b border-border pb-3 mb-3"><strong>{{ $history->from_status ? $history->from_status.' ← ' : '' }}{{ $history->to_status }}</strong><span class="block text-sm text-muted-foreground">{{ $history->changer?->display_name ?: 'سامانه' }} · {{ $history->changed_at?->format('Y-m-d H:i') }}</span>@if($history->reason)<span>{{ $history->reason }}</span>@endif</div>@endforeach</section>
@endsection
