<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\User;
use App\Support\ProfileForm;
use App\Support\ProfileOptions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;

class Stage06ClientController extends Controller
{
    private function centre(Request $request): int
    {
        abort_unless($request->user()->hasPermission('clients.view') && $request->user()->centre_id,403);
        return (int) $request->user()->centre_id;
    }
    public function search(Request $request)
    {
        $centre = $this->centre($request);
        $q = trim((string) $request->validate(['q'=>'required|string|min:2|max:100'])['q']);
        $term = addcslashes($q, '%_\\');
        return Client::where('centre_id',$centre)->whereNull('merged_into_id')->where('status','active')
            ->with('user:id,first_name,last_name,phone,national_id')
            ->where(function ($query) use ($term) {
                $query->where('client_code','like',"%{$term}%")
                    ->orWhereHas('user',fn ($u) => $u->where('first_name','like',"%{$term}%")
                        ->orWhere('last_name','like',"%{$term}%")
                        ->orWhere('name','like',"%{$term}%")
                        ->orWhere('phone','like',"%{$term}%")
                        ->orWhere('national_id','like',"%{$term}%"))
                    ->orWhereHas('externalIdentities',fn ($x) => $x->where('external_id','like',"%{$term}%"));
            })->limit(15)->get()->map(fn ($c) => ['id'=>$c->id,'text'=>trim($c->user?->display_name.' · '.$c->user?->phone.' · '.$c->client_code),'profile_state'=>$c->profile_state]);
    }
    public function quickCreate(Request $request)
    {
        $centre = $this->centre($request); abort_unless($request->user()->hasPermission('clients.manage'),403);
        $data = $request->validate(['first_name'=>'nullable|string|max:100','last_name'=>'nullable|string|max:100',
            'phone'=>'nullable|string|max:20','national_id'=>'nullable|string|max:20']);
        $phone = trim($data['phone'] ?? ''); $national = trim($data['national_id'] ?? '');
        if ($phone || $national) {
            $matches = User::where(fn ($q) => $q->when($phone,fn ($q) => $q->where('phone',$phone))
                ->when($national,fn ($q) => $q->orWhere('national_id',$national)))->get();
            if ($matches->isNotEmpty()) throw ValidationException::withMessages(['client'=>'احتمال رکورد تکراری وجود دارد؛ ابتدا مراجع را جستجو کنید.']);
        }
        $client = DB::transaction(function () use ($centre,$data,$phone,$national,$request) {
            $role = DB::table('roles')->where('slug','client')->value('id');
            $user = User::create(['first_name'=>($data['first_name'] ?? null) ?: 'مراجع','last_name'=>($data['last_name'] ?? null) ?: 'جدید',
                'name'=>trim((($data['first_name'] ?? null) ?: 'مراجع').' '.(($data['last_name'] ?? null) ?: 'جدید')),
                'phone'=>$phone ?: 'TMP'.Str::upper(Str::random(16)), 'national_id'=>$national ?: null,
                'password'=>Str::random(48),'role'=>'client','role_id'=>$role,'centre_id'=>$centre,
                'is_active'=>false,'status'=>'inactive','must_change_password'=>true,'created_by'=>$request->user()->id]);
            if ($role) DB::table('user_role_centres')->insert(['user_id'=>$user->id,'role_id'=>$role,'centre_id'=>$centre,'created_at'=>now(),'updated_at'=>now()]);
            return Client::create(['user_id'=>$user->id,'centre_id'=>$centre,
                'client_code'=>'CL-'.Str::upper(Str::random(12)),'status'=>'active','profile_state'=>'minimal','created_by'=>$request->user()->id]);
        });
        return response()->json(['id'=>$client->id,'text'=>$client->user->display_name.' · '.$client->client_code,'profile_state'=>'minimal'],201);
    }
    public function edit(Request $request, Client $client)
    {
        $this->allowed($request,$client,false);
        $fields = ProfileForm::fields('client')->filter(fn ($field) => $this->fieldAllowed($request,$client,$field->id,'can_view'));
        $client->load('user.profileValues');
        return view('clients.stage06-complete',compact('client','fields'));
    }
    public function update(Request $request, Client $client)
    {
        $this->allowed($request,$client,true);
        $fields = ProfileForm::fields('client')->filter(fn ($field) => $this->fieldAllowed($request,$client,$field->id,'can_edit'));
        $rules = ['first_name'=>'nullable|string|max:100','last_name'=>'nullable|string|max:100'];
        foreach ($fields as $field) {
            if (in_array($field->field_type,ProfileForm::DISPLAY_TYPES,true)) continue;
            $name='profile.'.$field->id;
            if (in_array($field->field_type,['multi_select','checkbox_group'],true)) {
                $rules[$name]=[$field->is_required?'required':'nullable','array','max:30'];
                $rules[$name.'.*']=['string',Rule::in(ProfileOptions::values($field->options))];
            } elseif ($field->field_type==='boolean') $rules[$name]=[$field->is_required?'required':'nullable','boolean'];
            else {
                $rules[$name]=[$field->is_required?'required':'nullable',match ($field->field_type) {
                    'email'=>'email','url'=>'url','number'=>'numeric','date'=>'date','time'=>'date_format:H:i',
                    'datetime'=>'date_format:Y-m-d\\TH:i',default=>'string'},'max:2000'];
                if (in_array($field->field_type,['select','radio'],true)) $rules[$name][]=Rule::in(ProfileOptions::values($field->options));
            }
        }
        $data=$request->validate($rules);
        DB::transaction(function () use ($client,$data,$fields) {
            $client->user->update(['first_name'=>($data['first_name'] ?? null) ?: $client->user->first_name,'last_name'=>($data['last_name'] ?? null) ?: $client->user->last_name,
                'name'=>trim((($data['first_name'] ?? null) ?: $client->user->first_name).' '.(($data['last_name'] ?? null) ?: $client->user->last_name))]);
            foreach ($fields as $field) {
                if (in_array($field->field_type,ProfileForm::DISPLAY_TYPES,true)) continue;
                $raw=$data['profile'][$field->id] ?? null;
                $client->user->profileValues()->updateOrCreate(['profile_field_id'=>$field->id],['value'=>is_array($raw)?json_encode($raw,JSON_UNESCAPED_UNICODE):$raw]);
            }
            $client->update(['profile_state'=>'complete']);
        });
        return redirect()->route('clients.show',$client)->with('success','پرونده تکمیل شد.');
    }
    private function allowed(Request $request, Client $client, bool $edit): void
    {
        abort_unless($client->centre_id === $this->centre($request),404);
        $role=$request->user()->assignedRole?->slug;
        $settings=DB::table('client_record_settings')->where('centre_id',$client->centre_id)->first();
        $roles=json_decode($settings?->completer_roles ?? '["manager","secretary","counselor","client"]',true);
        if ($edit) abort_unless(in_array($role,$roles,true) && ($request->user()->hasPermission('clients.manage') || ($role==='client' && $client->user_id===$request->user()->id)),403);
    }
    private function fieldAllowed(Request $request, Client $client, int $fieldId, string $action): bool
    {
        $role=$request->user()->assignedRole?->slug;
        $permission=DB::table('client_profile_field_permissions')->where('centre_id',$client->centre_id)->where('profile_field_id',$fieldId)->where('role',$role)->first();
        return $permission ? (bool) $permission->{$action} : in_array($role,['manager','secretary'],true);
    }
}
