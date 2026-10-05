@extends('layouts.app', ['title' => 'اتاق‌های مرکز'])

@section('content')
    @include('centres.partials.operation-tabs', ['centre' => $centre])
    <div class="ensha-page-heading"><div><h2>اتاق‌های مرکز</h2><p>مشاور اولویت‌دار و موضوعات قابل ارائه در هر اتاق</p></div></div>
    <section class="ensha-card" style="padding:22px">
        @if(auth()->user()->hasPermission('schedules.manage'))
            <form method="POST" action="{{ route('centres.operations.room', $centre) }}" class="ensha-form-grid">@csrf<label>نام اتاق<input name="name" required></label><label>شعبه<select name="branch_id"><option value="">همه شعب</option>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select></label><label>ظرفیت<input type="number" name="capacity" min="1" value="1" required></label><label>نوع<select name="room_type"><option value="consulting">مشاوره</option><option value="group">گروهی</option><option value="virtual">مجازی</option></select></label><label>مشاور اولویت‌دار<select name="priority_user_id"><option value="">بدون اولویت</option>@foreach($counselors as $counselor)<option value="{{ $counselor->id }}">{{ $counselor->display_name }}</option>@endforeach</select></label><label>موضوعات مجاز<select name="topic_ids[]" multiple size="3">@foreach($topics as $topic)<option value="{{ $topic->id }}">{{ $topic->category_name }} / {{ $topic->name }}</option>@endforeach</select></label><button class="ensha-primary-btn">تعریف اتاق</button></form>
        @endif
        <table class="ensha-table"><thead><tr><th>اتاق</th><th>مشاور اولویت‌دار و موضوعات</th><th>وضعیت</th><th>ظرفیت</th></tr></thead><tbody>
        @forelse($rooms as $room)
            <tr><td>{{ $room->name }}</td><td>
                @if(auth()->user()->hasPermission('schedules.manage'))
                    <form method="POST" action="{{ route('centres.operations.room.update', [$centre, $room->id]) }}" class="ensha-form-grid">@csrf @method('PUT')<input type="number" name="capacity" min="1" value="{{ $room->capacity }}" required><select name="room_type"><option value="consulting" @selected($room->room_type==='consulting')>مشاوره</option><option value="group" @selected($room->room_type==='group')>گروهی</option><option value="virtual" @selected($room->room_type==='virtual')>مجازی</option></select><select name="priority_user_id"><option value="">بدون اولویت</option>@foreach($counselors as $counselor)<option value="{{ $counselor->id }}" @selected($room->priority_user_id == $counselor->id)>{{ $counselor->display_name }}</option>@endforeach</select><select name="topic_ids[]" multiple size="3">@foreach($topics as $topic)<option value="{{ $topic->id }}" @selected(in_array($topic->id, $room->topic_ids ?? []))>{{ $topic->category_name }} / {{ $topic->name }}</option>@endforeach</select><button class="ensha-secondary-btn">ذخیره تنظیمات</button></form>
                @else
                    {{ optional($counselors->firstWhere('id', $room->priority_user_id))->display_name ?? 'بدون اولویت' }} · {{ $topics->whereIn('id', $room->topic_ids ?? [])->pluck('name')->join('، ') ?: 'بدون موضوع' }}
                @endif
            </td><td>{{ $room->is_active ? 'فعال' : 'غیرفعال' }}</td><td>{{ $room->capacity }}</td></tr>
        @empty
            <tr><td colspan="4">اتاقی تعریف نشده است.</td></tr>
        @endforelse
        </tbody></table>
    </section>
@endsection
