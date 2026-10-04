<?php
namespace App\Http\Controllers;
use App\Models\ProfileField;
use App\Support\Audit;
use App\Support\ProfileForm;
use App\Support\ProfileOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
class ProfileFieldController extends Controller {
 private const ROLES=['all'=>'همه','secretary'=>'منشی','counselor'=>'مشاور','client'=>'مراجعه‌کننده'];
 private const TYPES=['text'=>'متن کوتاه','textarea'=>'متن بلند','email'=>'ایمیل','tel'=>'تلفن','url'=>'پیوند','number'=>'عدد','date'=>'تاریخ','time'=>'ساعت','datetime'=>'تاریخ و ساعت','select'=>'فهرست انتخاب','radio'=>'تک‌انتخابی','checkbox_group'=>'چندانتخابی','multi_select'=>'فهرست چندانتخابی','boolean'=>'بله / خیر','heading'=>'عنوان بخش','paragraph'=>'متن راهنما','divider'=>'جداکننده'];
 public function index(Request $r): View { $requested=$r->query('role','all'); $role=is_string($requested) && array_key_exists($requested,self::ROLES)?$requested:'all'; $fields=$this->visibleFields($role); $selected=$r->filled('edit')?$fields->firstWhere('id',(int)$r->query('edit')):null; $forms=[];
  foreach(array_keys(self::ROLES) as $tab) {
   $items=$this->visibleFields($tab);
   $forms[$tab]=['version'=>$this->fingerprint($items),'fields'=>$items->map(fn($field)=>['id'=>$field->id,'label'=>$field->label,'key'=>$field->key,'field_type'=>$field->field_type,'options'=>ProfileOptions::normalized($field->options),'settings'=>$field->settings??[],'is_required'=>$field->is_required,'is_active'=>$field->is_active])->values()->all()];
  }
  return view('profile-fields.index',['role'=>$role,'tabs'=>self::ROLES,'types'=>self::TYPES,'fields'=>$fields,'selected'=>$selected,'formsPayload'=>$forms]); }
 private function validated(Request $r,?ProfileField $field=null): array {
  $data=$r->validate(['role'=>['required',Rule::in(array_keys(self::ROLES))],'label'=>['required','string','max:150'],'key'=>['required','regex:/^[a-z][a-z0-9_]*$/','max:100',Rule::unique('profile_fields','key')->where('role',$r->input('role'))->ignore($field?->id)],'field_type'=>['required',Rule::in(array_keys(self::TYPES))],'options'=>['nullable','string','max:4000'],'is_required'=>['nullable','boolean'],'is_active'=>['nullable','boolean'],'settings.placeholder'=>['nullable','string','max:200'],'settings.help'=>['nullable','string','max:500'],'settings.default'=>['nullable','string','max:500'],'settings.width'=>['nullable',Rule::in(['full','half'])]]);
  $options=collect(preg_split('/\r\n|\r|\n/',(string)($data['options']??'')))->map(fn($v)=>trim($v))->filter()->unique()->values()->all();
  if(in_array($data['field_type'],['select','radio','checkbox_group','multi_select'],true) && count($options)<2) throw ValidationException::withMessages(['options'=>'برای فیلد انتخابی دست‌کم دو گزینه در سطرهای جداگانه وارد کنید.']);
  if(count($options)>50) throw ValidationException::withMessages(['options'=>'حداکثر ۵۰ گزینه مجاز است.']);
  $data['options']=$options?:null; $data['settings']=array_filter($data['settings']??[],fn($v)=>$v!==null && $v!==''); $data['is_required']=$r->boolean('is_required') && !in_array($data['field_type'],ProfileForm::DISPLAY_TYPES,true); $data['is_active']=$r->boolean('is_active',true);
  return $data;
 }
 public function store(Request $r): RedirectResponse { $data=$this->validated($r); $data['sort_order']=(ProfileField::where('role',$data['role'])->max('sort_order')??0)+10; $field=ProfileField::create($data); Audit::record('افزودن فیلد پروفایل',$r,'info',['id'=>$field->id,'role'=>$field->role],$field,'profile_field.created'); return redirect()->route('profile-fields.index',['role'=>$field->role,'edit'=>$field->id])->with('success','فیلد ساخته شد.'); }
 public function update(Request $r,ProfileField $profileField): RedirectResponse { abort_unless(array_key_exists($profileField->role,self::ROLES),404); $data=$this->validated($r,$profileField); if($profileField->role!==$data['role']) throw ValidationException::withMessages(['role'=>'برای انتقال فیلد، آن را در تب موردنظر ایجاد کنید.']); if($profileField->values()->exists() && ($data['key']!==$profileField->key || $data['field_type']!==$profileField->field_type)) throw ValidationException::withMessages(['field_type'=>'فیلدی که پاسخ ثبت‌شده دارد، نمی‌تواند کلید یا نوعش تغییر کند.']); $profileField->update($data); Audit::record('ویرایش فیلد پروفایل',$r,'info',['id'=>$profileField->id],$profileField,'profile_field.updated'); return redirect()->route('profile-fields.index',['role'=>$profileField->role,'edit'=>$profileField->id])->with('success','فیلد ویرایش شد.'); }
 public function cloneField(Request $r,ProfileField $profileField): RedirectResponse { abort_unless(array_key_exists($profileField->role,self::ROLES),404); $copy=$profileField->replicate(); $copy->label='کپی '.$profileField->label; $base=substr($profileField->key,0,80).'_copy'; $key=$base; $n=2; while(ProfileField::where('role',$profileField->role)->where('key',$key)->exists()) $key=$base.$n++; $copy->key=$key; $copy->sort_order=(ProfileField::where('role',$copy->role)->max('sort_order')??0)+10; $copy->save(); Audit::record('تکثیر فیلد پروفایل',$r,'info',['source'=>$profileField->id,'copy'=>$copy->id],$copy,'profile_field.cloned'); return redirect()->route('profile-fields.index',['role'=>$copy->role,'edit'=>$copy->id])->with('success','نسخهٔ کپی ساخته شد.'); }
 public function reorder(Request $r) { $data=$r->validate(['role'=>['required',Rule::in(array_keys(self::ROLES))],'ids'=>['required','array','max:250'],'ids.*'=>['required','integer','distinct']]); DB::transaction(function() use($data) { $current=ProfileField::where('role',$data['role'])->lockForUpdate()->orderBy('sort_order')->orderBy('id')->pluck('id')->map(fn($id)=>(int)$id)->all(); if(count($current)!==count($data['ids']) || array_diff($current,$data['ids']) || array_diff($data['ids'],$current)) throw ValidationException::withMessages(['ids'=>'فهرست فیلدها تغییر کرده است؛ صفحه را تازه‌سازی کنید.']); foreach($data['ids'] as $i=>$id) ProfileField::whereKey($id)->where('role',$data['role'])->update(['sort_order'=>($i+1)*10]); }); Audit::record('تغییر ترتیب فیلدها',$r,'info',['role'=>$data['role']]); return response()->json(['ok'=>true]); }


 public function saveAll(Request $request)
 {
  $payload=$request->validate(['forms'=>['required','array','size:4'],'forms.*'=>['required','array']]);
  $forms=$payload['forms'];
  if(array_keys($forms)!==array_keys(self::ROLES)) throw ValidationException::withMessages(['forms'=>'ساختار تب‌های فرم معتبر نیست.']);
  $results=DB::transaction(function() use($request,$forms) {
   $results=[];
   foreach($forms as $role=>$form) {
    $copy=clone $request;
    $copy->replace(['role'=>$role,'version'=>$form['version']??null,'fields'=>$form['fields']??null]);
    try { $results[$role]=json_decode($this->saveDraft($copy)->getContent(),true); }
    catch (ValidationException $e) {
     $message=collect($e->errors())->flatten()->first() ?: 'خطای اعتبارسنجی';
     throw ValidationException::withMessages(['forms.'.$role=>'تب «'.self::ROLES[$role].'»: '.$message]);
    }
   }
   return $results;
  });
  return response()->json(['ok'=>true,'forms'=>$results]);
 }
 private function visibleFields(string $role)
 {
  return ProfileField::where('role',$role)->orderBy('sort_order')->orderBy('id')->get()
   ->filter(fn($field)=>!($field->settings['archived'] ?? false))->values();
 }
 private function fingerprint($fields): string
 {
  return hash('sha256',$fields->map(fn($field)=>$field->getAttributes())->toJson());
 }
 public function saveDraft(Request $r)
 {
  $data=$r->validate([
   'role'=>['required',Rule::in(array_keys(self::ROLES))],
   'version'=>['required','string','size:64'],
   'fields'=>['present','array','max:250'],
   'fields.*.id'=>['nullable','integer','distinct','min:1'],
   'fields.*.label'=>['required','string','max:150'],
   'fields.*.key'=>['required','regex:/^[a-z][a-z0-9_]*$/','max:100'],
   'fields.*.field_type'=>['required',Rule::in(array_keys(self::TYPES))],
   'fields.*.is_required'=>['required','boolean'],
   'fields.*.is_active'=>['required','boolean'],
   'fields.*.options'=>['nullable','array','max:50'],
   'fields.*.options.*.label'=>['required','string','max:150'],
   'fields.*.options.*.value'=>['required','string','max:150'],
   'fields.*.settings'=>['nullable','array'],
   'fields.*.settings.placeholder'=>['nullable','string','max:200'],
   'fields.*.settings.help'=>['nullable','string','max:500'],
   'fields.*.settings.default'=>['nullable','string','max:500'],
   'fields.*.settings.width'=>['nullable',Rule::in(['full','half'])],
  ]);
  $keys=[];
  foreach($data['fields'] as $i=>$field) {
   if(isset($keys[$field['key']])) throw ValidationException::withMessages(["fields.$i.key"=>'کلید فنی در این تب تکراری است.']);
   $keys[$field['key']]=true;
   $choice=in_array($field['field_type'],['select','radio','checkbox_group','multi_select'],true);
   $options=ProfileOptions::normalized($field['options'] ?? []);
   if($choice && count($options)<2) throw ValidationException::withMessages(["fields.$i.options"=>'حداقل دو گزینه لازم است.']);
   $values=array_column($options,'value');
   if(count($values)!==count(array_unique($values))) throw ValidationException::withMessages(["fields.$i.options"=>'مقدار گزینه‌ها باید یکتا باشد.']);
   if(!$choice) $data['fields'][$i]['options']=null;
  }
  $result=DB::transaction(function() use($data) {
   // قفل مشترک برای ذخیره‌های هم‌زمان همه تب‌ها.
   DB::table('permissions')->where('slug','profile_fields.manage')->lockForUpdate()->first();
   $existing=$this->visibleFields($data['role']);
   if(!hash_equals($this->fingerprint($existing),$data['version'])) throw ValidationException::withMessages(['version'=>'فرم در صفحهٔ دیگری تغییر کرده است. ابتدا صفحه را تازه‌سازی کنید.']);
   $byId=$existing->keyBy('id'); $seen=[];
   $requestedIds=collect($data['fields'])->pluck('id')->filter()->map(fn($id)=>(int)$id)->all();
   foreach($data['fields'] as $i=>$input) {
    $id=$input['id']??null;
    if($id!==null && !$byId->has($id)) throw ValidationException::withMessages(["fields.$i.id"=>'فیلد متعلق به این تب نیست.']);
    $reserved=ProfileField::where('role',$data['role'])->where('key',$input['key'])->first();
    if($reserved && $reserved->id!==$id && !in_array($reserved->id,$requestedIds,true) && $reserved->values()->exists())
     throw ValidationException::withMessages(["fields.$i.key"=>'این کلید برای فیلدی با پاسخ‌های ثبت‌شده رزرو شده است.']);
   }
   // حذف فیلدهای بدون پاسخ و آزاد کردن کلیدهای قدیمی پیش از ساخت کلیدهای جدید.
   foreach($existing as $field) {
    if(!in_array($field->id,$requestedIds,true) && !$field->values()->exists()) $field->delete();
    elseif(in_array($field->id,$requestedIds,true)) {
     $requested=collect($data['fields'])->firstWhere('id',$field->id);
     if($requested && $requested['key']!==$field->key && !$field->values()->exists()) {
      $field->key='staging_'.$field->id.'_'.bin2hex(random_bytes(5)); $field->save();
     }
    }
   }
   foreach($data['fields'] as $i=>$input) {
    $id=$input['id']??null;
    $field=$id!==null?$byId->get($id):new ProfileField(['role'=>$data['role']]);
    if($id!==null) $seen[$id]=true;
    $options=$input['options']??null;
    if($id!==null && $field->values()->exists()) {
     if($field->key!==$input['key'] || $field->field_type!==$input['field_type']) throw ValidationException::withMessages(["fields.$i.field_type"=>'نوع و کلید فیلد دارای پاسخ را نمی‌توان تغییر داد.']);
     if(ProfileOptions::values($field->options)!==ProfileOptions::values($options)) throw ValidationException::withMessages(["fields.$i.options"=>'مقدار گزینه‌های دارای پاسخ را نمی‌توان تغییر داد؛ برچسب قابل ویرایش است.']);
    }
    $field->fill([
     'role'=>$data['role'], 'label'=>$input['label'], 'key'=>$input['key'],
     'field_type'=>$input['field_type'], 'options'=>$options,
     'settings'=>array_intersect_key($input['settings']??[],array_flip(['placeholder','help','default','width'])),
     'is_required'=>in_array($input['field_type'],ProfileForm::DISPLAY_TYPES,true)?false:$input['is_required'],
     'is_active'=>$input['is_active'], 'sort_order'=>($i+1)*10,
    ]);
    $field->save();
   }
   foreach($existing as $field) {
    if(isset($seen[$field->id])) continue;
    if($field->values()->exists()) {
     $field->is_active=false;
     $field->settings=array_merge($field->settings??[],['archived'=>true]);
     $field->save();
    } else $field->delete();
   }
   $fresh=$this->visibleFields($data['role']);
   return ['fields'=>$fresh->map(fn($field)=>$field->only(['id','label','key','field_type','options','settings','is_required','is_active','sort_order']))->all(),'version'=>$this->fingerprint($fresh)];
  });
  Audit::record('ذخیره فرم پروفایل',$r,'info',['role'=>$data['role'],'count'=>count($data['fields'])],null,'profile_form.saved');
  return response()->json(['ok'=>true,...$result]);
 }
 public function destroy(Request $r,ProfileField $profileField): RedirectResponse { abort_unless(array_key_exists($profileField->role,self::ROLES),404); if($profileField->values()->exists()) { $profileField->update(['is_active'=>false]); $message='فیلد دارای پاسخ است؛ برای حفظ داده‌ها غیرفعال شد.'; } else { $profileField->delete(); $message='فیلد حذف شد.'; } Audit::record('حذف یا غیرفعال‌سازی فیلد پروفایل',$r,'warning',['id'=>$profileField->id,'role'=>$profileField->role]); return redirect()->route('profile-fields.index',['role'=>$profileField->role])->with('success',$message); }
}
