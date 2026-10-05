@extends('layouts.app', ['title' => 'موضوعات و تعرفه مرکز'])

@section('content')
    @include('centres.partials.operation-tabs', ['centre' => $centre])
    <div class="ensha-page-heading"><div><h2>موضوعات و تعرفه</h2><p>دسته‌ها، موضوعات، مدت جلسه و تخصیص چند موضوع به هر مشاور</p></div></div>

    <section class="ensha-card" style="padding:22px">
        <div class="ensha-card-head">
            <h3>دسته‌بندی و موضوعات</h3>
            @if(auth()->user()->hasPermission('fees.manage'))
                <form method="POST" action="{{ route('centres.operations.category', $centre) }}">@csrf<input name="name" placeholder="دسته جدید" required><button class="ensha-primary-btn">دسته جدید</button></form>
            @endif
        </div>
        <table class="ensha-table">
            <thead><tr><th>دسته</th><th>موضوعات</th>@if(auth()->user()->hasPermission('fees.manage'))<th>عملیات</th>@endif</tr></thead>
            <tbody>
            @forelse($categories as $category)
                <tr>
                    <td><strong>{{ $category->name }}</strong></td>
                    <td>@foreach($topics->where('category_id', $category->id) as $topic)<span class="ensha-badge-soft" style="border-color:{{ $topic->color }}">{{ $topic->name }} · {{ $topic->session_minutes }}+{{ $topic->break_minutes }} دقیقه · ظرفیت {{ $topic->capacity }} · {{ implode(' / ', json_decode($topic->allowed_modes ?: '["in_person"]', true)) }} · {{ number_format($topic->price) }} تومان</span>@endforeach</td>
                    @if(auth()->user()->hasPermission('fees.manage'))
                        <td><button type="button" class="ensha-secondary-btn" onclick="document.getElementById('topic-{{ $category->id }}').hidden=!document.getElementById('topic-{{ $category->id }}').hidden">افزودن موضوع</button><form hidden id="topic-{{ $category->id }}" method="POST" action="{{ route('centres.operations.topic', $centre) }}" style="margin-top:10px">@csrf<input type="hidden" name="category_id" value="{{ $category->id }}"><input name="name" placeholder="نام موضوع" required><input type="number" name="minimum_minutes" value="15" min="1" required><input type="number" name="session_minutes" value="60" min="1" required><input type="number" name="break_minutes" value="0" min="0" required><input type="number" name="capacity" value="1" min="1" required><label><input type="checkbox" name="allowed_modes[]" value="in_person" checked> حضوری</label><label><input type="checkbox" name="allowed_modes[]" value="phone"> تلفنی</label><label><input type="checkbox" name="allowed_modes[]" value="video"> ویدیویی</label><label><input type="checkbox" name="requires_room" value="1" checked> نیازمند اتاق</label><input type="number" name="price" min="0" placeholder="قیمت تومان" required><input type="color" name="color" value="#3b82f6"><button class="ensha-primary-btn">ثبت</button></form></td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="3">دسته‌ای ثبت نشده است.</td></tr>
            @endforelse
            </tbody>
        </table>
    </section>

    <section class="ensha-card" style="padding:22px">
        <h3>موضوعات مشاوران</h3>
        <table class="ensha-table">
            <thead><tr><th>مشاور</th><th>موضوعات مشاوره</th>@if(auth()->user()->hasPermission('counselors.manage'))<th>عملیات</th>@endif</tr></thead>
            <tbody>
            @foreach($counselors as $counselor)
                <tr>
                    <td>{{ $counselor->display_name }}</td>
                    <td>@foreach($topics->whereIn('id', $assignments[$counselor->id] ?? []) as $topic)<span class="ensha-badge-soft">{{ $topic->name }}</span>@endforeach</td>
                    @if(auth()->user()->hasPermission('counselors.manage'))
                        <td><button type="button" class="ensha-secondary-btn" onclick="document.getElementById('assign-{{ $counselor->id }}').hidden=!document.getElementById('assign-{{ $counselor->id }}').hidden">ویرایش</button><form hidden id="assign-{{ $counselor->id }}" method="POST" action="{{ route('centres.operations.assign', $centre) }}">@csrf<input type="hidden" name="user_id" value="{{ $counselor->id }}">@foreach($topics as $topic)<label style="display:block"><input type="checkbox" name="topic_ids[]" value="{{ $topic->id }}" @checked(in_array($topic->id, $assignments[$counselor->id] ?? []))> {{ $topic->category_name }} / {{ $topic->name }}</label>@endforeach<button class="ensha-primary-btn">ذخیره موضوعات</button></form></td>
                    @endif
                </tr>
            @endforeach
            </tbody>
        </table>
    </section>
@endsection
