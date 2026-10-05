<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Client;
use App\Models\TelephoneConsultation;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TelephoneConsultationController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->hasPermission('appointments.view'),403); $centreId=(int)$request->user()->centre_id;
        $calls=TelephoneConsultation::with(['client.user','counselor','appointment'])->where('centre_id',$centreId)->latest('started_at')->paginate(40);
        $clients=Client::with('user')->where('centre_id',$centreId)->where('status','active')->orderBy('client_code')->get();
        $appointments=Appointment::visibleTo($request->user())->where('mode','phone')->where('starts_at','>=',now()->subMonth())->orderByDesc('starts_at')->limit(100)->get();
        return view('operations.telephone',compact('calls','clients','appointments'));
    }
    public function store(Request $request)
    {
        abort_unless($request->user()->hasPermission('appointments.manage'),403); $centreId=(int)$request->user()->centre_id;
        $data=$request->validate(['client_id'=>'required|integer|exists:clients,id','appointment_id'=>'nullable|integer|exists:appointments,id','phone_number'=>'required|string|max:32','started_at'=>'required|date','ended_at'=>'nullable|date|after_or_equal:started_at','status'=>['required',Rule::in(['scheduled','dialing','answered','missed','failed','completed'])],'outcome'=>'nullable|string|max:1000','counselor_note'=>'nullable|string|max:5000']);
        abort_unless(Client::whereKey($data['client_id'])->where('centre_id',$centreId)->exists(),403);
        if(!empty($data['appointment_id'])) abort_unless(Appointment::whereKey($data['appointment_id'])->where('centre_id',$centreId)->exists(),403);
        $call=TelephoneConsultation::create($data+['centre_id'=>$centreId,'counselor_id'=>$request->user()->id,'created_by'=>$request->user()->id]);
        return redirect()->route('operations.telephone')->with('success','گزارش تماس تلفنی ثبت شد: #'.$call->id);
    }
}
