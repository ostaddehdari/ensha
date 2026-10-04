@extends('layouts.app', ['title' => 'روزها و ساعات کاری'])

@section('content')
    @include('centres.partials.operation-tabs', ['centre' => $centre])

    <div class="ensha-page-heading">
        <div>
            <h2>روزها و ساعات کاری</h2>
            <p>زمان‌های متوالی در یک شیفت و انتخاب‌های جدا از هم در شیفت‌های مستقل ذخیره می‌شوند.</p>
        </div>
    </div>

    <section class="ensha-card" style="padding:22px">
        <table class="ensha-table">
            <thead><tr><th>مشاور</th>@foreach(['شنبه','یکشنبه','دوشنبه','سه‌شنبه','چهارشنبه','پنجشنبه','جمعه'] as $dayName)<th>{{ $dayName }}</th>@endforeach</tr></thead>
            <tbody>
            @foreach($counselors as $counselor)
                <tr>
                    <td><strong>{{ $counselor->display_name }}</strong></td>
                    @for($day = 0; $day < 7; $day++)
                        @php($dayShifts = ($shifts->get($counselor->id, collect()))->where('weekday', $day))
                        <td>
                            <details>
                                <summary>{{ $dayShifts->count() }} بازه</summary>
                                @if(auth()->user()->hasPermission('schedules.manage'))
                                    <form method="POST" action="{{ route('centres.operations.shift', $centre) }}" class="work-slot-form slot-picker">
                                        @csrf
                                        <input type="hidden" name="user_id" value="{{ $counselor->id }}">
                                        <input type="hidden" name="weekday" value="{{ $day }}">
                                        <div style="max-height:160px;overflow:auto;display:grid;grid-template-columns:repeat(2,1fr);gap:3px">
                                            @for($minute = 360; $minute < 1320; $minute += 30)
                                                @php($hour = intdiv($minute, 60))
                                                @php($part = $minute % 60)
                                                @php($value = sprintf('%02d:%02d', $hour, $part))
                                                <label style="font-size:11px"><input type="checkbox" class="slot-check" name="slots[]" value="{{ $value }}"> {{ $value }}</label>
                                            @endfor
                                        </div>
                                        <label>دستمزد<input type="number" name="hourly_pay" value="0" min="0" required></label>
                                        <button class="ensha-primary-btn">افزودن ساعات انتخاب‌شده</button>
                                    </form>
                                @endif
                                @forelse($dayShifts as $shift)
                                    <div>
                                        {{ substr($shift->starts_at, 0, 5) }} تا {{ substr($shift->ends_at, 0, 5) }}
                                        @if(auth()->user()->hasPermission('schedules.manage'))
                                            <form method="POST" action="{{ route('centres.operations.shift.delete', [$centre, $shift->id]) }}" style="display:inline">
                                                @csrf @method('DELETE')
                                                <button aria-label="حذف بازه">×</button>
                                            </form>
                                        @endif
                                    </div>
                                @empty
                                    <p>بازه‌ای ثبت نشده است.</p>
                                @endforelse
                            </details>
                        </td>
                    @endfor
                </tr>
            @endforeach
            </tbody>
        </table>
    </section>
@endsection

@push('scripts')
<script>
document.querySelectorAll('.slot-picker').forEach((form) => form.addEventListener('submit', (event) => {
    if (!form.querySelector('.slot-check:checked')) {
        event.preventDefault();
        alert('حداقل یک بازه نیم‌ساعته انتخاب کنید');
    }
}));
</script>
@endpush
