(() => {
  const root=document.querySelector('#stage06-scheduler'); if(!root) return;
  const $=(s,p=root)=>p.querySelector(s), drawer=$('[data-drawer]'), form=$('[data-booking]',drawer);
  const state={date:new Date(),view:'day',resources:[]};
  const pad=x=>String(x).padStart(2,'0'), ymd=d=>`${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}`;
  const local=s=>{const d=new Date(s);return `${ymd(d)}T${pad(d.getHours())}:${pad(d.getMinutes())}:00`;};
  const label=new Intl.DateTimeFormat('fa-IR',{weekday:'long',year:'numeric',month:'long',day:'numeric'});
  const enc=(url,params)=>`${url}?${new URLSearchParams(params)}`;
  async function json(url,options={}) {const r=await fetch(url,{headers:{Accept:'application/json',...options.headers},...options});const v=await r.json();if(!r.ok)throw new Error(Object.values(v.errors||{}).flat().join(' ')||v.message||'خطا در درخواست');return v;}
  const dp=new DayPilot.Calendar('stage06-daypilot',{viewType:'Resources',startDate:ymd(state.date),
    businessBeginsHour:8,businessEndsHour:21,dayBeginsHour:8,dayEndsHour:21,cellDuration:15,
    onTimeRangeSelected:args=>{if(root.dataset.manage==='1')open(args.start.toString(),args.resource);},
    onEventClick:args=>{if(args.e.data.url)location.href=args.e.data.url;},
    onEventMove:async args=>{args.preventDefault();if(root.dataset.manage!=='1')return;
      try {await json(`${root.dataset.move}/${args.e.data.id}`,{method:'PATCH',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':root.dataset.csrf},body:JSON.stringify({start:args.newStart.toString(),end:args.newEnd.toString(),counselor_id:args.newResource||args.e.data.resource})});await load();}
      catch(e){$('[data-error]').textContent=e.message;await load();}},
    onBeforeEventRender:args=>{args.data.backColor=args.data.topicColor;args.data.barColor=args.data.statusColor;args.data.fontColor='#111827';
      args.data.areas=[{right:0,top:0,bottom:0,width:7,backColor:args.data.statusColor}];}
  });dp.init();
  function range(){let start=new Date(state.date),end=new Date(start);if(state.view==='week'){start.setDate(start.getDate()-((start.getDay()+1)%7));end=new Date(start);end.setDate(end.getDate()+7);}else end.setDate(end.getDate()+1);return {start:ymd(start),end:ymd(end)};}
  async function load(){const r=range();$('[data-title]').textContent=label.format(state.date);$('[data-error]').textContent='';
    try {state.resources=await json(enc(root.dataset.resources,{date:ymd(state.date)}));
      const filtered=state.resources.filter(x=>!$('[data-counselor]').value||String(x.id)===$('[data-counselor]').value);
      const events=await json(enc(root.dataset.events,{...r,counselor_id:$('[data-counselor]').value,topic_id:$('[data-topic]').value,status:$('[data-status]').value,mode:$('[data-mode]').value}));
      const mapped=events.map(e=>({id:e.id,start:local(e.start),end:local(e.end),resource:String(e.resourceId),text:e.title,
        topicColor:e.color,statusColor:e.statusColor,url:e.url}));
      if(state.view==='day')dp.update({viewType:'Resources',startDate:ymd(state.date),columns:filtered.map(x=>({id:String(x.id),name:`${x.name} · ${x.hours||'بدون شیفت'} · ${x.centre||''}`})),events:mapped});
      else dp.update({viewType:'Week',startDate:ymd(state.date),events:mapped});
    }catch(e){$('[data-error]').textContent=e.message;}}
  async function open(start=null,counselor=null){drawer.hidden=false;form.reset();form.elements.client_id.value='';$('[data-results]',drawer).replaceChildren();$('[data-client-label]',drawer).textContent='';$('[data-drawer-error]',drawer).textContent='';
    try {const r=range(),slots=await json(enc(root.dataset.slots,{...r,counselor_id:counselor||$('[data-counselor]').value,topic_id:$('[data-topic]').value}));
      const select=$('[data-slot]',drawer);select.replaceChildren(new Option('انتخاب زمان',''));
      slots.forEach(s=>select.add(new Option(s.label,s.id)));
      const match=slots.find(s=>start&&local(s.start)===start.slice(0,19)&&(!counselor||String(s.counselor_id)===String(counselor)));
      if(match){select.value=match.id;quote();}
    }catch(e){$('[data-drawer-error]',drawer).textContent=e.message;}}
  function close(){drawer.hidden=true;}drawer.querySelectorAll('[data-close]').forEach(b=>b.onclick=close);
  $('[data-new]').onclick=()=>open();
  let debounce; $('[data-search]',drawer).oninput=e=>{clearTimeout(debounce);const q=e.target.value.trim();if(q.length<2)return;debounce=setTimeout(async()=>{
    try {const rows=await json(enc(root.dataset.clients,{q}));const out=$('[data-results]',drawer);out.replaceChildren();out.className='stage06-suggestions';
      rows.forEach(row=>{const b=document.createElement('button');b.type='button';b.textContent=row.text+(row.profile_state==='minimal'?' · پرونده ناقص':'');
        b.onclick=()=>{form.elements.client_id.value=row.id;$('[data-client-label]',drawer).textContent=row.text;out.replaceChildren();};out.append(b);});
      if(!rows.length)out.textContent='مراجعی یافت نشد؛ از ثبت سریع استفاده کنید.';
    }catch(e){$('[data-drawer-error]',drawer).textContent=e.message;}},250);};
  $('[data-client-create]',drawer).onclick=async()=>{try {const body=new FormData();['first_name','last_name','phone','national_id'].forEach(k=>body.set(k,form.elements[k].value));
      const row=await json(root.dataset.createClient,{method:'POST',headers:{'X-CSRF-TOKEN':root.dataset.csrf},body});
      form.elements.client_id.value=row.id;$('[data-client-label]',drawer).textContent=row.text;$('[data-results]',drawer).replaceChildren();
    }catch(e){$('[data-drawer-error]',drawer).textContent=e.message;}};
  async function quote(){if(!form.elements.slot_id.value)return;try{const q=await json(enc(root.dataset.quote,{slot_id:form.elements.slot_id.value,discount_id:form.elements.discount_id.value,paid_amount:form.elements.paid_amount.value||0}));
      $('[data-pricing]',drawer).textContent=`مدت ${q.duration_minutes} دقیقه · پایه ${q.base_price.toLocaleString('fa-IR')} · تخفیف ${q.discount_value_snapshot.toLocaleString('fa-IR')} · نهایی ${q.final_price.toLocaleString('fa-IR')} · مانده ${q.balance_amount.toLocaleString('fa-IR')}`;
    }catch(e){$('[data-pricing]',drawer).textContent=e.message;}}
  ['slot_id','discount_id','paid_amount'].forEach(k=>form.elements[k].addEventListener('change',quote));
  form.onsubmit=async e=>{e.preventDefault();try {const body=new FormData(form);['first_name','last_name','phone','national_id'].forEach(k=>body.delete(k));
      await json(root.dataset.save,{method:'POST',headers:{'X-CSRF-TOKEN':root.dataset.csrf},body});close();await load();
    }catch(err){$('[data-drawer-error]',drawer).textContent=err.message;}};
  $('[data-view]').onchange=e=>{state.view=e.target.value;load();};
  root.querySelectorAll('[data-counselor],[data-topic],[data-status],[data-mode]').forEach(x=>x.onchange=load);
  $('[data-prev]').onclick=()=>{state.date.setDate(state.date.getDate()-(state.view==='week'?7:1));load();};
  $('[data-next]').onclick=()=>{state.date.setDate(state.date.getDate()+(state.view==='week'?7:1));load();};
  $('[data-today]').onclick=()=>{state.date=new Date();load();};load();
})();
