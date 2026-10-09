@extends('layouts.app', ['title' => 'عملیات روزانه منشی'])

@section('content')
    @include('appointments.partials.nav')

    <div class="ensha-page-heading">
        <div>
            <span class="ensha-eyebrow">مرحله اول · S07-W01</span>
            <h2>عملیات روزانه منشی</h2>
            <p>پذیرش مراجع، صف انتظار، شروع/پایان جلسه، عدم مراجعه، لغو و جابه‌جایی کنترل‌شده.</p>
        </div>
        <a class="ensha-primary-btn" href="{{ route('operations.report', ['date' => $date]) }}">گزارش روزانه</a>
    </div>

    <section class="ensha-card" style="padding:18px">
        <form class="ensha-filter-bar">
            <input class="kt-input" type="date" name="date" value="{{ $date }}">
            <button class="ensha-secondary-btn">نمایش روز</button>
        </form>
        <div class="grid grid-cols-2 md:grid-cols-6 gap-3 mt-4">
            @foreach (['total' => 'کل نوبت', 'pending' => 'در انتظار', 'waiting' => 'صف انتظار', 'in_session' => 'در جلسه', 'completed' => 'تکمیل', 'no_show' => 'عدم مراجعه'] as $key => $label)
                <div class="rounded-xl border border-border p-3 bg-background">
                    <small class="text-muted-foreground">{{ $label }}</small>
                    <strong class="block text-xl mt-1">{{ $stats[$key] }}</strong>
                </div>
            @endforeach
        </div>
    </section>

    @if ($waiting->isNotEmpty())
        <section class="ensha-card mt-5" style="padding:18px">
            <h3>صف پذیرش‌شده‌ها</h3>
            <div class="grid md:grid-cols-2 gap-3 mt-3">
                @foreach ($waiting as $position => $appointment)
                    <div class="rounded-xl border border-border p-3 flex items-center justify-between">
                        <div>
                            <strong>{{ $position + 1 }}. {{ $appointment->client?->user?->display_name }}</strong>
                            <small class="block">
                                ورود {{ $appointment->checked_in_at?->format('H:i') ?: '—' }} ·
                                {{ $appointment->counselor?->display_name }}
                            </small>
                        </div>
                        <span class="kt-badge">
                            {{ $appointment->status === 'in_session' ? 'در جلسه' : 'در صف' }}
                        </span>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    <section class="ensha-card mt-5" style="padding:18px">
        <div class="flex items-center justify-between mb-3">
            <h3>صف و نوبت‌های امروز</h3>
            <form method="POST" action="{{ route('operations.sms.reminders', ['date' => $date]) }}">
                @csrf
                <input type="hidden" name="date" value="{{ $date }}">
                <button class="ensha-secondary-btn">قرار دادن یادآوری‌ها در صف پیامک</button>
            </form>
        </div>

        <div class="ensha-table-wrap">
            <table class="ensha-table">
                <thead>
                    <tr>
                        <th>زمان</th>
                        <th>مراجع</th>
                        <th>خدمت / مشاور</th>
                        <th>وضعیت</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($appointments as $appointment)
                        <tr>
                            <td>{{ $appointment->starts_at->format('H:i') }}</td>
                            <td>{{ $appointment->client?->user?->display_name }}</td>
                            <td>
                                {{ $appointment->topic?->name }}
                                <small>{{ $appointment->counselor?->display_name }}</small>
                            </td>
                            <td>
                                {{ [
                                    'pending' => 'در انتظار',
                                    'confirmed' => 'تأیید',
                                    'arrived' => 'در صف',
                                    'in_session' => 'در جلسه',
                                    'completed' => 'تکمیل',
                                    'cancelled' => 'لغو',
                                    'no_show' => 'عدم مراجعه',
                                ][$appointment->status] }}
                            </td>
                            <td>
                                <div class="flex flex-wrap gap-1">
                                    @if (in_array($appointment->status, ['pending', 'confirmed'], true))
                                        <form method="POST" action="{{ route('operations.check-in', $appointment) }}">
                                            @csrf
                                            <button class="ensha-secondary-btn">پذیرش</button>
                                        </form>
                                    @endif

                                    @if ($appointment->status === 'arrived')
                                        <form method="POST" action="{{ route('operations.start', $appointment) }}">
                                            @csrf
                                            <button class="ensha-primary-btn">شروع جلسه</button>
                                        </form>
                                    @endif

                                    @if ($appointment->status === 'in_session')
                                        <form method="POST" action="{{ route('operations.end', $appointment) }}">
                                            @csrf
                                            <button class="ensha-primary-btn">اتمام جلسه</button>
                                        </form>
                                    @endif

                                    @if (in_array($appointment->status, ['pending', 'confirmed', 'arrived'], true))
                                        <form method="POST" action="{{ route('operations.no-show', $appointment) }}">
                                            @csrf
                                            <input type="hidden" name="reason" value="عدم مراجعه مراجع">
                                            <button class="ensha-secondary-btn">عدم مراجعه</button>
                                        </form>
                                    @endif

                                    <a class="ensha-secondary-btn" href="{{ route('appointments.show', $appointment) }}">جزئیات</a>
                                </div>

                                @if (in_array($appointment->status, ['pending', 'confirmed', 'arrived', 'in_session'], true))
                                    <details class="mt-2">
                                        <summary>لغو / جابه‌جایی</summary>
                                        <div class="grid gap-2 mt-2">
                                            @if ($appointment->status !== 'in_session')
                                                <form method="POST" action="{{ route('operations.reschedule', $appointment) }}" class="flex flex-wrap gap-2">
                                                    @csrf
                                                    <select class="kt-input" name="slot_id" required>
                                                        <option value="">زمان آزاد مقصد</option>
                                                        @foreach ($availableSlots as $slot)
                                                            @if ($slot->topic_id === $appointment->topic_id)
                                                                <option value="{{ $slot->id }}">
                                                                    {{ $slot->starts_at->format('Y-m-d H:i') }} ·
                                                                    {{ $slot->counselor?->display_name }}
                                                                </option>
                                                            @endif
                                                        @endforeach
                                                    </select>
                                                    <input class="kt-input" name="reason" required maxlength="1000" placeholder="دلیل جابه‌جایی">
                                                    <button class="ensha-secondary-btn">ثبت جابه‌جایی</button>
                                                </form>
                                            @endif

                                            <form method="POST" action="{{ route('operations.cancel', $appointment) }}" class="flex flex-wrap gap-2" onsubmit="return confirm('این نوبت لغو شود؟')">
                                                @csrf
                                                <input class="kt-input" name="reason" required maxlength="1000" placeholder="دلیل لغو">
                                                <button class="ensha-secondary-btn text-danger">لغو نوبت</button>
                                            </form>
                                        </div>
                                    </details>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">نوبتی برای این روز نیست.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    @if (auth()->user()->hasPermission('appointments.manage'))
        <div class="grid lg:grid-cols-2 gap-5 mt-5">
            <section class="ensha-card" style="padding:18px">
                <h3>ثبت در لیست انتظار</h3>
                <form method="POST" action="{{ route('operations.waitlist.store') }}" class="space-y-3 mt-3">
                    @csrf
                    <label>
                        مراجع
                        <select class="kt-input" name="client_id" required>
                            <option value="">انتخاب کنید</option>
                            @foreach ($clients as $client)
                                <option value="{{ $client->id }}">
                                    {{ $client->client_code }} — {{ $client->user?->display_name }}
                                </option>
                            @endforeach
                        </select>
                    </label>
                    <label>
                        خدمت
                        <select class="kt-input" name="topic_id" required>
                            @foreach ($topics as $topic)
                                <option value="{{ $topic->id }}">{{ $topic->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <div class="grid grid-cols-2 gap-2">
                        <input class="kt-input" type="datetime-local" name="desired_from">
                        <input class="kt-input" type="number" name="priority" min="0" max="9" placeholder="اولویت ۰ تا ۹">
                    </div>
                    <textarea class="kt-input" name="notes" placeholder="توضیح"></textarea>
                    <button class="ensha-primary-btn">افزودن به صف انتظار</button>
                </form>
            </section>

            <section class="ensha-card" style="padding:18px">
                <h3>لیست انتظار فعال</h3>
                <div class="space-y-3 mt-3">
                    @forelse ($waitlists as $waitlist)
                        <div class="rounded-lg border border-border p-3">
                            <strong>{{ $waitlist->client?->user?->display_name }}</strong>
                            <small class="block">
                                {{ $waitlist->topic?->name }} · اولویت {{ $waitlist->priority }}
                            </small>
                            <form method="POST" action="{{ route('operations.waitlist.promote', $waitlist) }}" class="mt-2 flex gap-2">
                                @csrf
                                <select class="kt-input" name="slot_id" required>
                                    <option value="">اسلات آزاد</option>
                                    @foreach ($availableSlots as $slot)
                                        <option value="{{ $slot->id }}">
                                            {{ $slot->starts_at->format('m/d H:i') }} —
                                            {{ $slot->counselor?->display_name }}
                                        </option>
                                    @endforeach
                                </select>
                                <button class="ensha-secondary-btn">تبدیل به نوبت</button>
                            </form>
                        </div>
                    @empty
                        <p class="text-muted-foreground">مراجعی در صف نیست.</p>
                    @endforelse
                </div>
            </section>
        </div>
    @endif
@endsection
