<?php
namespace App\Support;
use App\Models\ProfileField;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
final class ProfileForm {
 public const INPUT_TYPES=['text','textarea','email','tel','url','number','date','time','datetime','select','radio','checkbox_group','multi_select','boolean'];
 public const DISPLAY_TYPES=['heading','paragraph','divider'];
 public static function fields(string $role) { return ProfileField::query()->where('is_active',true)->whereIn('role',array_unique(['all',$role]))->orderByRaw("CASE WHEN role = 'all' THEN 0 ELSE 1 END")->orderBy('sort_order')->orderBy('id')->get(); }
 public static function validate(Request $request, Role $role): array {
  $fields=self::fields($role->slug); $rules=[]; $attributes=[];
  foreach($fields as $field) {
   if(in_array($field->field_type,self::DISPLAY_TYPES,true)) continue;
   $name='profile.'.$field->id; $attributes[$name]=$field->label; $base=$field->is_required?'required':'nullable';
   if(in_array($field->field_type,['checkbox_group','multi_select'],true)) {
    $rules[$name]=[$base,'array','max:30']; $rules[$name.'.*']=['string',Rule::in(ProfileOptions::values($field->options))];
   } elseif($field->field_type==='boolean') $rules[$name]=[$base,'boolean'];
   else {
    $rule=match($field->field_type) {
     'email'=>'email', 'url'=>'url', 'number'=>'numeric', 'date'=>'date', 'time'=>'date_format:H:i', 'datetime'=>'date_format:Y-m-d\TH:i', default=>'string'
    };
    $rules[$name]=[$base,$rule];
    if(in_array($field->field_type,['text','textarea','email','tel','url'],true)) $rules[$name][]='max:2000';
    if(in_array($field->field_type,['select','radio'],true)) $rules[$name][]=Rule::in(ProfileOptions::values($field->options));
   }
  }
  $data=Validator::make($request->all(),$rules,[], $attributes)->validate();
  return $data['profile']??[];
 }
 public static function save(User $user, Role $role, array $values): void {
  foreach(self::fields($role->slug) as $field) {
   if(in_array($field->field_type,self::DISPLAY_TYPES,true)) continue;
   $raw=$values[$field->id]??null; $value=is_array($raw)?json_encode($raw,JSON_UNESCAPED_UNICODE):($raw===null?null:(string)$raw);
   $user->profileValues()->updateOrCreate(['profile_field_id'=>$field->id],['value'=>$value]);
  }
 }
}
