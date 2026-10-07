<?php

namespace App\Http\Controllers;

use App\Models\Centre;
use App\Models\ProfileField;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class Stage06SettingsController extends Controller
{
    private function centre(Request $request, Centre $centre): int
    {
        abort_unless($request->user()->isSuperAdmin() || $request->user()->assignedRole?->slug==='manager',403);
        abort_unless($request->user()->isSuperAdmin() || (int) $request->user()->centre_id === (int) $centre->id, 403);
        return (int) $centre->id;
    }
    public function index(Request $request, Centre $centre)
    {
        $centreId=$this->centre($request, $centre);
        $discounts=DB::table('discounts')->where('centre_id',$centreId)->orderByDesc('id')->get();
        $statuses=DB::table('appointment_statuses')->where('centre_id',$centreId)->orderBy('sort_order')->get();
        $topics=DB::table('service_topics as t')->join('service_categories as c','c.id','=','t.category_id')->where('c.centre_id',$centreId)->select('t.*')->get();
        $counselors=DB::table('users as u')->join('user_role_centres as a','a.user_id','=','u.id')->join('roles as r','r.id','=','a.role_id')
            ->where('a.centre_id',$centreId)->where('r.slug','counselor')->select('u.id','u.name')->distinct()->get();
        $mappings=DB::table('counselor_topics as m')
            ->join('users as u','u.id','=','m.user_id')->join('service_topics as t','t.id','=','m.topic_id')
            ->where('m.centre_id',$centreId)
            ->select('m.*','u.name as counselor_name','t.name as topic_name')->orderBy('u.name')->orderBy('t.name')->get();
        $fields=ProfileField::whereIn('role',['all','client'])->orderBy('sort_order')->get();
        $record=DB::table('client_record_settings')->where('centre_id',$centreId)->first();
        $bookingPolicy=\App\Services\BookingPolicy::forCentre($centreId);
        $fieldPermissions=DB::table('client_profile_field_permissions')->where('centre_id',$centreId)->get()->keyBy(fn ($v) => $v->profile_field_id.'_'.$v->role);
        return view('appointments.stage06-settings',compact('centre','centreId','discounts','statuses','topics','counselors','mappings','fields','record','fieldPermissions','bookingPolicy'));
    }
    public function bookingPolicy(Request $request, Centre $centre)
    {
        $centreId = $this->centre($request, $centre);
        $data = $request->validate([
            'check_rooms' => 'required|boolean',
            'allow_past_bookings' => 'required|boolean',
        ]);
        DB::table('centre_booking_policies')->updateOrInsert(
            ['centre_id' => $centreId],
            ['check_rooms' => (bool) $data['check_rooms'],
             'allow_past_bookings' => (bool) $data['allow_past_bookings'],
             'updated_at' => now()]
        );
        return redirect()->to(route('centres.appointments-settings.index',$centre).'#policy')->with('success', 'تنظیمات نوبت‌دهی ذخیره شد.');
    }

    public function discount(Request $request, Centre $centre)
    {
        $centreId=$this->centre($request, $centre);
        $d=$request->validate(['name'=>'required|string|max:120','type'=>['required',Rule::in(['percent','fixed'])],
            'value'=>'required|integer|min:1','starts_at'=>'nullable|date','ends_at'=>'nullable|date|after:starts_at',
            'minimum_amount'=>'nullable|integer|min:0','maximum_discount'=>'nullable|integer|min:0','is_active'=>'nullable|boolean']);
        abort_if($d['type']==='percent' && $d['value']>100,422,'درصد باید حداکثر ۱۰۰ باشد.');
        DB::table('discounts')->insert([...$d,'centre_id'=>$centreId,'is_active'=>(bool) ($d['is_active']??false),'created_at'=>now(),'updated_at'=>now()]);
        return redirect()->to(route('centres.appointments-settings.index',$centre).'#discounts')->with('success','تخفیف ثبت شد.');
    }
    public function status(Request $request, Centre $centre)
    {
        $centreId=$this->centre($request, $centre);
        $d=$request->validate(['name'=>'required|string|max:80','slug'=>['required','alpha_dash','max:24',Rule::unique('appointment_statuses')->where('centre_id',$centreId)],
            'indicator_color'=>['required','regex:/^#[0-9A-Fa-f]{6}$/'],'text_color'=>['required','regex:/^#[0-9A-Fa-f]{6}$/'],
            'sort_order'=>'integer|min:0|max:999','is_final'=>'nullable|boolean','blocks_slot'=>'nullable|boolean']);
        DB::table('appointment_statuses')->insert([...$d,'centre_id'=>$centreId,'sort_order'=>$d['sort_order']??99,
            'is_initial'=>false,'is_final'=>(bool) ($d['is_final']??false),'blocks_slot'=>(bool) ($d['blocks_slot']??false),
            'is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);
        return redirect()->to(route('centres.appointments-settings.index',$centre).'#statuses')->with('success','وضعیت ثبت شد.');
    }
    public function mapping(Request $request, Centre $centre)
    {
        $centreId=$this->centre($request, $centre);
        $d=$request->validate(['counselor_id'=>'required|integer','topic_id'=>'required|integer','is_active'=>'nullable|boolean',
            'price_override'=>'nullable|integer|min:0','duration_override'=>'nullable|integer|min:1|max:1440',
            'valid_from'=>'nullable|date','valid_until'=>'nullable|date|after_or_equal:valid_from']);
        abort_unless(DB::table('user_role_centres as a')->join('roles as r','r.id','=','a.role_id')->where('a.user_id',$d['counselor_id'])->where('a.centre_id',$centreId)->where('r.slug','counselor')->exists(),422);
        abort_unless(DB::table('service_topics as t')->join('service_categories as c','c.id','=','t.category_id')->where('t.id',$d['topic_id'])->where('c.centre_id',$centreId)->exists(),422);
        DB::table('counselor_topics')->updateOrInsert(['user_id'=>$d['counselor_id'],'topic_id'=>$d['topic_id']],
            ['centre_id'=>$centreId,'is_active'=>(bool) ($d['is_active']??false),'price_override'=>$d['price_override']??null,
                'duration_override'=>$d['duration_override']??null,'valid_from'=>$d['valid_from']??null,'valid_until'=>$d['valid_until']??null]);
        return redirect()->to(route('centres.appointments-settings.index',$centre).'#mappings')->with('success','تخصص مشاور تنظیم شد.');
    }
    public function record(Request $request, Centre $centre)
    {
        $centreId=$this->centre($request, $centre);
        $d=$request->validate(['draft_on'=>['required',Rule::in(['booking','manual'])],
            'complete_required_on'=>['required',Rule::in(['never','arrival','session'])],
            'completer_roles'=>'required|array|min:1','completer_roles.*'=>[Rule::in(['manager','secretary','counselor','client'])]]);
        DB::table('client_record_settings')->updateOrInsert(['centre_id'=>$centreId],
            ['draft_on'=>$d['draft_on'],'complete_required_on'=>$d['complete_required_on'],
                'completer_roles'=>json_encode($d['completer_roles']),'updated_at'=>now(),'created_at'=>now()]);
        return redirect()->to(route('centres.appointments-settings.index',$centre).'#records')->with('success','تنظیمات پرونده ثبت شد.');
    }
    public function fieldPermission(Request $request, Centre $centre)
    {
        $centreId=$this->centre($request, $centre);
        $d=$request->validate(['field_id'=>'required|integer|exists:profile_fields,id','role'=>[Rule::in(['manager','secretary','counselor','client'])],
            'can_view'=>'nullable|boolean','can_edit'=>'nullable|boolean']);
        abort_unless(ProfileField::whereKey($d['field_id'])->whereIn('role',['client','all'])->exists(),422);
        DB::table('client_profile_field_permissions')->updateOrInsert(['centre_id'=>$centreId,'profile_field_id'=>$d['field_id'],'role'=>$d['role']],
            ['can_view'=>(bool) ($d['can_view']??false),'can_edit'=>(bool) ($d['can_edit']??false)]);
        return redirect()->to(route('centres.appointments-settings.index',$centre).'#permissions')->with('success','دسترسی فیلد ثبت شد.');
    }
}
