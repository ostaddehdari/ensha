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
  const columnColors = ['#fffbea','#fff1f2','#eff6ff','#ecfdf5','#faf5ff','#fff7ed','#f0fdfa','#fdf2f8'];
  const colorIndex = value => [...String(value ?? '')].reduce((sum,char)=>sum+char.charCodeAt(0),0)%columnColors.length;
  const query = (url, params) => `${url}?${new URLSearchParams(params)}`;
  const parts = date => Object.fromEntries(persian.formatToParts(date).filter(p => ['year','month','day'].includes(p.type)).map(p => [p.type, Number(p.value)]));
  async function json(url, options = {}) {
    const response = await fetch(url, {
      ...options,
      headers: { ...options.headers, Accept: 'application/json' },
      credentials: 'same-origin',
      redirect: 'manual'
    });
    if (response.type === 'opaqueredirect' || response.status === 302 || response.status === 303) {
      throw new Error('درخواست به صفحهٔ دیگری هدایت شد. صفحه را تازه کنید و دوباره وارد حساب شوید.');
    }
    const contentType = response.headers.get('content-type') || '';
    if (!contentType.includes('application/json')) {
      throw new Error(`پاسخ سرور JSON نیست (HTTP ${response.status}). صفحه را تازه کنید؛ اگر تکرار شد، گزارش خطای سرور را بررسی کنید.`);
    }
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
    // Calendar Lite accepts Full, BusinessHours, and BusinessHoursNoScroll.
    // BusinessHours keeps a fixed viewport with an internal time-grid scroll,
    // so counselor headers stay visible while browsing afternoon hours.
    heightSpec:'BusinessHours',
    dayBeginsHour:8, dayEndsHour:21, businessBeginsHour:8, businessEndsHour:16, cellDuration:15,
    onTimeRangeSelected:args=>{if(root.dataset.manage==='1') open(args.start.toString(),args.resource);},
    onEventClick:args=>{if(args.e.data.url) location.href=args.e.data.url;},
    onEventMove:async args=>{args.preventDefault();if(root.dataset.manage!=='1')return;
      try {await json(`${root.dataset.move}/${args.e.data.id}`,{method:'PATCH',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':root.dataset.csrf},body:JSON.stringify({start:args.newStart.toString(),end:args.newEnd.toString(),counselor_id:args.newResource||args.e.data.resource})});await load();}
      catch(e){error.textContent=e.message;await load();}},
    onBeforeCellRender:args=>{const key=args.cell.resource ?? args.cell.x ?? 0;const color=columnColors[colorIndex(key)];if(args.cell.properties)args.cell.properties.backColor=color;else args.cell.backColor=color;},
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
  function open(start,counselor=null) {
    const selected = /^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})/.exec(start || "");
    if (!selected) {error.textContent="زمان انتخاب‌شده از تقویم معتبر نیست؛ دوباره کلیک کنید.";return;}
    state.start=start; state.counselor=counselor;
    form.reset();form.elements.client_id.value='';drawer.hidden=false;
    $('[data-results]',drawer).replaceChildren();$('[data-client-label]',drawer).textContent='';$('[data-drawer-error]',drawer).textContent='';
    if(counselor) $('[data-counselor]',drawer).value=String(counselor);
    form.elements.appointment_date.value = selected[1];
    form.elements.start_time.value = selected[2];
  }
  drawer.querySelectorAll('[data-close]').forEach(button=>button.onclick=()=>{drawer.hidden=true;});

  let debounce;
  $('[data-search]',drawer).oninput=e=>{clearTimeout(debounce);form.elements.client_id.value='';$('[data-client-label]',drawer).textContent='';const q=e.target.value.trim();if(q.length<2){$('[data-results]',drawer).replaceChildren();return;}debounce=setTimeout(async()=>{
    try {const rows=await json(query(root.dataset.clients,{q}));const out=$('[data-results]',drawer);out.replaceChildren();out.className='stage06-suggestions';
      rows.forEach(row=>{const button=document.createElement('button');button.type='button';button.textContent=row.text+(row.profile_state==='minimal'?' · پرونده ناقص':'');
        button.onclick=()=>{form.elements.client_id.value=row.id;$('[data-search]',drawer).value=row.text;$('[data-client-label]',drawer).textContent=`مراجع انتخاب‌شده: ${row.text}`;out.replaceChildren();$('[data-drawer-error]',drawer).textContent='';};out.append(button);});
      if(!rows.length)out.textContent='مراجعی یافت نشد؛ مشخصات مراجع جدید را در همین فرم وارد کنید.';
    }catch(e){$('[data-drawer-error]',drawer).textContent=e.message;}},250);};
  ['first_name','last_name','phone','national_id'].forEach(key => {
    form.elements[key].addEventListener('input', () => {
      form.elements.client_id.value='';
      $('[data-client-label]',drawer).textContent='';
      $('[data-search]',drawer).value='';
      $('[data-results]',drawer).replaceChildren();
    });
  });
  form.onsubmit=async e=>{e.preventDefault();try {if (!form.elements.appointment_date.value || !form.elements.start_time.value) throw new Error('زمان نوبت را از تقویم انتخاب کنید.');if (!form.elements.client_id.value && (!form.elements.first_name.value.trim() || !form.elements.last_name.value.trim())) throw new Error('مراجع موجود را از نتایج انتخاب کنید، یا نام و نام خانوادگی مراجع جدید را وارد کنید.');const body=new FormData(form);
      $('[data-drawer-error]',drawer).textContent='';
      const submit=form.querySelector('[type=submit]');submit.disabled=true;
      try { await json(root.dataset.save,{method:'POST',headers:{'X-CSRF-TOKEN':root.dataset.csrf},body});drawer.hidden=true;await load(); }
      finally { submit.disabled=false; }
    }catch(err){$('[data-drawer-error]',drawer).textContent=err.message;}};
  $('[data-view]').onchange=e=>{state.view=e.target.value;load();};
  $('[data-prev]').onclick=()=>{state.date.setDate(state.date.getDate()-(state.view==='week'?7:1));state.month=new Date(state.date);load();};
  $('[data-next]').onclick=()=>{state.date.setDate(state.date.getDate()+(state.view==='week'?7:1));state.month=new Date(state.date);load();};
  $('[data-today]').onclick=()=>{state.date=new Date();state.month=new Date();load();};
  load();
})();
