(() => {
  const root = document.querySelector('#stage06-scheduler');
  if (!root) return;
  const $ = (selector, scope = root) => scope?.querySelector(selector);
  const drawer = document.querySelector('[data-drawer]');
  const form = $('[data-booking]', drawer);
  const error = $('[data-error]');
  const loading = $('[data-loading]');
  if (!drawer || !form || !window.DayPilot?.Calendar) {
    error.textContent = 'تقویم بارگذاری نشد. صفحه را تازه کنید؛ در صورت تکرار، فایل DayPilot و خطای مرورگر را بررسی کنید.';
    if (loading) loading.hidden = true;
    return;
  }
  const state = {date: new Date(), month: new Date(), view: 'day', resources: [], start: null, counselor: null};
  const pad = value => String(value).padStart(2, '0');
  const ymd = date => `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
  const local = iso => {const date = new Date(iso); return `${ymd(date)}T${pad(date.getHours())}:${pad(date.getMinutes())}:00`;};
  const longDate = new Intl.DateTimeFormat('fa-IR-u-ca-persian', {weekday:'long', year:'numeric', month:'long', day:'numeric'});
  const persian = new Intl.DateTimeFormat('en-US-u-ca-persian-nu-latn', {year:'numeric', month:'numeric', day:'numeric'});
  const monthLabel = new Intl.DateTimeFormat('fa-IR-u-ca-persian', {year:'numeric', month:'long'});
  const query = (url, params) => `${url}?${new URLSearchParams(params)}`;
  const parts = date => Object.fromEntries(persian.formatToParts(date).filter(p => ['year','month','day'].includes(p.type)).map(p => [p.type, Number(p.value)]));
  async function json(url, options = {}) {
    const response = await fetch(url, {headers:{Accept:'application/json', ...options.headers}, ...options});
    const value = await response.json();
    if (!response.ok) throw new Error(Object.values(value.errors || {}).flat().join(' ') || value.message || 'خطا در درخواست');
    return value;
  }
  function mini() {
    const grid = $('[data-month-grid]');
    grid.replaceChildren();
    $('[data-month-title]').textContent = monthLabel.format(state.month);
    ['ش','ی','د','س','چ','پ','ج'].forEach(day => {const head=document.createElement('span'); head.textContent=day; head.className='stage06-mini-weekday'; grid.append(head);});
    const target = parts(state.month);
    const first = new Date(state.month.getFullYear(), state.month.getMonth(), state.month.getDate());
    while (parts(first).day !== 1) first.setDate(first.getDate() - 1);
    const offset = (first.getDay() + 1) % 7;
    for (let i=0;i<offset;i++) grid.append(document.createElement('span'));
    for (let day = new Date(first), n=0; n<32 && parts(day).month===target.month && parts(day).year===target.year; n++, day.setDate(day.getDate()+1)) {
      const date = new Date(day); const button = document.createElement('button'); button.type='button';
      button.textContent = new Intl.NumberFormat('fa-IR').format(parts(date).day);
      button.setAttribute('aria-label',longDate.format(date));
      if (ymd(date)===ymd(state.date)) button.classList.add('selected');
      if (ymd(date)===ymd(new Date())) button.classList.add('today');
      button.onclick=()=>{state.date=date;state.month=new Date(date);load();}; grid.append(button);
    }
  }
  function shiftMonth(delta) {
    const previous = parts(state.month); const date = new Date(state.month);
    do {date.setDate(date.getDate() + delta);} while (parts(date).month === previous.month && parts(date).year === previous.year);
    state.month = date; mini();
  }
  $('[data-month-prev]').onclick=()=>shiftMonth(-1);
  $('[data-month-next]').onclick=()=>shiftMonth(1);
  const dp = new DayPilot.Calendar('stage06-daypilot', {
    viewType:'Resources', startDate:ymd(state.date), locale:'fa-ir',
    dayBeginsHour:8, dayEndsHour:21, businessBeginsHour:8, businessEndsHour:21, cellDuration:15,
    onTimeRangeSelected:args=>{if(root.dataset.manage==='1') open(args.start.toString(),args.resource);},
    onEventClick:args=>{if(args.e.data.url) location.href=args.e.data.url;},
    onEventMove:async args=>{args.preventDefault();if(root.dataset.manage!=='1')return;
      try {await json(`${root.dataset.move}/${args.e.data.id}`,{method:'PATCH',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':root.dataset.csrf},body:JSON.stringify({start:args.newStart.toString(),end:args.newEnd.toString(),counselor_id:args.newResource||args.e.data.resource})});await load();}
      catch(e){error.textContent=e.message;await load();}},
    onBeforeEventRender:args=>{args.data.backColor=args.data.topicColor; args.data.barColor=args.data.statusColor; args.data.fontColor='#111827';}
  });
  dp.init();
  function range() {
    let start = new Date(state.date), end;
    if (state.view==='week') start.setDate(start.getDate()-((start.getDay()+1)%7));
    end = new Date(start); end.setDate(end.getDate()+(state.view==='week'?7:1));
    return {start:ymd(start),end:ymd(end)};
  }
  async function load() {
    mini(); $('[data-title]').textContent=longDate.format(state.date); error.textContent=''; loading.hidden=false;
    try {
      const r=range();
      const [resources, events] = await Promise.all([
        json(query(root.dataset.resources,{date:ymd(state.date)})),
        json(query(root.dataset.events,r))
      ]);
      state.resources=resources;
      const mapped=events.map(e=>({id:e.id,start:local(e.start),end:local(e.end),resource:String(e.resourceId),text:e.title,topicColor:e.color,statusColor:e.statusColor,url:e.url}));
      if (state.view==='day') dp.update({viewType:'Resources',startDate:ymd(state.date),columns:resources.map(x=>({id:String(x.id),name:`${x.name} · ${x.hours||'بدون شیفت'}`})),events:mapped});
      else dp.update({viewType:'Week',startDate:ymd(state.date),events:mapped});
      if (!resources.length) error.textContent='مشاور فعالی برای این مرکز ثبت نشده است.';
    } catch(e) {error.textContent=e.message; console.error('Ensha calendar:',e);}
    finally {loading.hidden=true;}
  }
  async function slots() {
    try {
      const r=range(), counselor=$('[data-counselor]',drawer).value, topic=$('[data-topic]',drawer).value;
      const values=await json(query(root.dataset.slots,{...r,counselor_id:counselor,topic_id:topic}));
      const select=$('[data-slot]',drawer); select.replaceChildren(new Option('انتخاب زمان',''));
      values.forEach(slot=>select.add(new Option(slot.label,slot.id)));
      const match=values.find(slot=>state.start && local(slot.start)===state.start.slice(0,19) && (!state.counselor || String(slot.counselor_id)===String(state.counselor)));
      if(match) {select.value=match.id;quote();}
      if(!values.length) $('[data-drawer-error]',drawer).textContent='زمان آزادی برای این انتخاب وجود ندارد. روز یا مشاور دیگری را انتخاب کنید.';
    }catch(e){$('[data-drawer-error]',drawer).textContent=e.message;}
  }
  function open(start=null,counselor=null) {
    state.start=start; state.counselor=counselor;
    form.reset();form.elements.client_id.value='';drawer.hidden=false;
    $('[data-results]',drawer).replaceChildren();$('[data-client-label]',drawer).textContent='';$('[data-drawer-error]',drawer).textContent='';
    $('[data-picked]',drawer).textContent=start ? `زمان انتخابی: ${longDate.format(new Date(start))}، ساعت ${start.slice(11,16)}` : 'زمان مورد نظر را انتخاب کنید.';
    if(counselor) $('[data-counselor]',drawer).value=String(counselor);
    slots();
  }
  drawer.querySelectorAll('[data-close]').forEach(button=>button.onclick=()=>{drawer.hidden=true;});
  $('[data-counselor]',drawer).onchange=slots;$('[data-topic]',drawer).onchange=slots;
  let debounce;
  $('[data-search]',drawer).oninput=e=>{clearTimeout(debounce);const q=e.target.value.trim();if(q.length<2)return;debounce=setTimeout(async()=>{
    try {const rows=await json(query(root.dataset.clients,{q}));const out=$('[data-results]',drawer);out.replaceChildren();out.className='stage06-suggestions';
      rows.forEach(row=>{const button=document.createElement('button');button.type='button';button.textContent=row.text+(row.profile_state==='minimal'?' · پرونده ناقص':'');
        button.onclick=()=>{form.elements.client_id.value=row.id;$('[data-client-label]',drawer).textContent=row.text;out.replaceChildren();};out.append(button);});
      if(!rows.length)out.textContent='مراجعی یافت نشد؛ از ثبت سریع استفاده کنید.';
    }catch(e){$('[data-drawer-error]',drawer).textContent=e.message;}},250);};
  $('[data-client-create]',drawer).onclick=async()=>{try {const body=new FormData();['first_name','last_name','phone','national_id'].forEach(k=>body.set(k,form.elements[k].value));
      const row=await json(root.dataset.createClient,{method:'POST',headers:{'X-CSRF-TOKEN':root.dataset.csrf},body});form.elements.client_id.value=row.id;$('[data-client-label]',drawer).textContent=row.text;$('[data-results]',drawer).replaceChildren();
    }catch(e){$('[data-drawer-error]',drawer).textContent=e.message;}};
  async function quote(){if(!form.elements.slot_id.value)return;try{const q=await json(query(root.dataset.quote,{slot_id:form.elements.slot_id.value,discount_id:form.elements.discount_id.value,paid_amount:form.elements.paid_amount.value||0}));
      $('[data-pricing]',drawer).textContent=`مدت ${q.duration_minutes} دقیقه · پایه ${q.base_price.toLocaleString('fa-IR')} · تخفیف ${q.discount_value_snapshot.toLocaleString('fa-IR')} · نهایی ${q.final_price.toLocaleString('fa-IR')} · مانده ${q.balance_amount.toLocaleString('fa-IR')}`;
    }catch(e){$('[data-pricing]',drawer).textContent=e.message;}}
  ['slot_id','discount_id','paid_amount'].forEach(key=>form.elements[key].addEventListener('change',quote));
  form.onsubmit=async e=>{e.preventDefault();try {const body=new FormData(form);['first_name','last_name','phone','national_id'].forEach(key=>body.delete(key));
      await json(root.dataset.save,{method:'POST',headers:{'X-CSRF-TOKEN':root.dataset.csrf},body});drawer.hidden=true;await load();
    }catch(err){$('[data-drawer-error]',drawer).textContent=err.message;}};
  $('[data-view]').onchange=e=>{state.view=e.target.value;load();};
  $('[data-prev]').onclick=()=>{state.date.setDate(state.date.getDate()-(state.view==='week'?7:1));state.month=new Date(state.date);load();};
  $('[data-next]').onclick=()=>{state.date.setDate(state.date.getDate()+(state.view==='week'?7:1));state.month=new Date(state.date);load();};
  $('[data-today]').onclick=()=>{state.date=new Date();state.month=new Date();load();};
  load();
})();
