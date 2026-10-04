(() => {
  const root = document.querySelector('.ensha-builder');
  if (!root) return;
  const cards = document.getElementById('builder-cards');
  const feedback = document.getElementById('builder-feedback');
  const settings = document.getElementById('builder-settings-panel');
  const palette = document.getElementById('builder-palette-panel');
  const rows = document.getElementById('builder-option-rows');
  const choiceTypes = ['select','radio','checkbox_group','multi_select'];
  const displayTypes = ['heading','paragraph','divider'];
  const typeNames = Object.fromEntries([...document.querySelectorAll('.ensha-builder-type')].map(button => [button.dataset.type,button.dataset.label]));
  const initial = JSON.parse(document.getElementById('builder-initial').textContent);
  const state = {forms:Object.fromEntries(Object.entries(initial).map(([role,form])=>[role,{version:form.version,fields:form.fields.map(item=>({...item,uid:`saved-${item.id}`,options:normalize(item.options),settings:item.settings||{}}))}])), selected:null, dirty:false, saving:false};
  Object.defineProperty(state,'fields',{get(){return state.forms[root.dataset.role].fields;},set(value){state.forms[root.dataset.role].fields=value;}});
  let dragged=null;
  let serial=0;
  let suppressPaletteClick=false;
  let pointerDrag=null;
  function normalize(options){ return (options||[]).map(item=>typeof item==='string'?{label:item,value:item}:{label:String(item.label??''),value:String(item.value??'')}); }
  function node(tag,cls,text){ const e=document.createElement(tag);if(cls)e.className=cls;if(text!==undefined)e.textContent=text;return e; }
  function mark(){state.dirty=true;document.getElementById('builder-dirty').hidden=false;feedback.textContent='تغییرات هنوز ذخیره نشده‌اند.';}
  function selected(){return state.fields.find(f=>f.uid===state.selected);}
  function newField(type,label,index=state.fields.length){
    const uid=`draft-${++serial}`;
    const slug=`field_${Date.now().toString(36)}_${serial}`;
    const field={uid,id:null,role:root.dataset.role,label,key:slug,field_type:type,is_required:false,is_active:true,options:choiceTypes.includes(type)?[{label:'گزینهٔ اول',value:'option_1'},{label:'گزینهٔ دوم',value:'option_2'}]:[],settings:{width:'full'}};
    state.fields.splice(index,0,field);mark();state.selected=uid;render();
  }
  function renderPreview(field,where){
    where.replaceChildren();
    if(field.field_type==='divider'){where.append(node('span','ensha-builder-static','────────────────────'));return;}
    if(field.field_type==='heading'||field.field_type==='paragraph'){where.append(node('span','ensha-builder-static',field.settings.help||field.label));return;}
    if(choiceTypes.includes(field.field_type)){
      if(['select','multi_select'].includes(field.field_type)){const select=node('select');select.disabled=true;select.append(node('option','',field.settings.placeholder||'یک گزینه انتخاب کنید'));field.options.forEach(o=>{const option=node('option','',o.label);select.append(option);});where.append(select);}
      else {const group=node('div','ensha-builder-options');field.options.forEach(o=>group.append(node('span','',(field.field_type==='radio'?'◯ ':'□ ')+o.label)));where.append(group);}
      return;
    }
    if(field.field_type==='boolean'){where.append(node('span','','□ بله / خیر'));return;}
    const input=node(field.field_type==='textarea'?'textarea':'input');input.disabled=true;
    if(input.tagName==='INPUT')input.type=['email','tel','url','number','date','time'].includes(field.field_type)?field.field_type:'text';
    input.placeholder=field.settings.placeholder||'';where.append(input);
  }
  function render(){
    cards.replaceChildren();
    state.fields.forEach((field,index)=>{
      const card=node('article','ensha-builder-card'+(!field.is_active?' is-inactive':'')+(field.uid===state.selected?' is-selected':''));
      card.dataset.uid=field.uid;card.draggable=true;
      const head=node('div','ensha-builder-card-top');const grip=node('span','ensha-builder-grip','⠿');grip.title='برای جابه‌جایی بکشید';head.append(grip);
      const title=node('strong','ensha-builder-card-label',field.label+(field.is_required?' *':''));head.append(title);
      head.append(node('small','',typeNames[field.field_type]||field.field_type));if(!field.is_active)head.append(node('small','','غیرفعال'));card.append(head);
      const preview=node('div','ensha-builder-preview-control');renderPreview(field,preview);card.append(preview);
      if(field.settings.help)card.append(node('small','ensha-builder-help',field.settings.help));
      const actions=node('div','ensha-builder-actions');
      [['edit','ویرایش'],['clone','کلون'],['up','↑'],['down','↓'],['delete','حذف']].forEach(([action,label])=>{const button=node('button','',label);button.type='button';button.dataset.action=action;button.disabled=(action==='up'&&index===0)||(action==='down'&&index===state.fields.length-1);actions.append(button);});
      card.append(actions);cards.append(card);
    });
    document.getElementById('builder-count').textContent=`${state.fields.length} فیلد سفارشی`;
    showSettings();
  }
  function showSettings(){
    const field=selected();palette.hidden=!!field;settings.hidden=!field;document.getElementById('builder-panel-back').hidden=!field;document.getElementById('builder-panel-title').textContent=field?'تنظیمات فیلد':'افزودن فیلد';
    if(!field)return;
    settings.querySelectorAll('[data-property]').forEach(input=>{input.type==='checkbox'?input.checked=!!field[input.dataset.property]:input.value=field[input.dataset.property]??'';});
    settings.querySelectorAll('[data-setting]').forEach(input=>input.value=field.settings[input.dataset.setting]??(input.dataset.setting==='width'?'full':''));
    document.getElementById('builder-options-editor').hidden=!choiceTypes.includes(field.field_type);
    renderOptions(field);
  }
  function renderOptions(field){
    rows.replaceChildren();
    field.options.forEach((option,index)=>{
      const row=node('div','ensha-builder-option-row');
      const label=node('input','kt-input');label.type='text';label.placeholder='برچسب نمایشی';label.value=option.label;label.maxLength=150;label.dataset.index=index;label.dataset.part='label';
      const value=node('input','kt-input');value.type='text';value.placeholder='مقدار ذخیره‌شونده';value.value=option.value;value.maxLength=150;value.dataset.index=index;value.dataset.part='value';value.dir='ltr';
      const remove=node('button','','×');remove.type='button';remove.dataset.remove=index;remove.title='حذف این گزینه';row.append(label,value,remove);rows.append(row);
    });
  }
  document.querySelectorAll('.ensha-builder-type').forEach(button=>{
    button.addEventListener('click',event=>{if(suppressPaletteClick){event.preventDefault();return;}newField(button.dataset.type,button.dataset.label);});
    button.addEventListener('dragstart',e=>{if(pointerDrag){e.preventDefault();return;}dragged={type:button.dataset.type,label:button.dataset.label};e.dataTransfer.effectAllowed='copy';e.dataTransfer.setData('text/plain',button.dataset.type);e.dataTransfer.setData('application/x-ensha-field',button.dataset.type);});
    button.addEventListener('dragend',()=>dragged=null);
  });
  // Pointer drag is reliable even when another dashboard library intercepts native drag events.
  document.getElementById('builder-palette').addEventListener('pointerdown',event=>{
    const button=event.target.closest('.ensha-builder-type');
    if(!button || event.button!==0)return;
    pointerDrag={type:button.dataset.type,label:button.dataset.label,startX:event.clientX,startY:event.clientY,moved:false,ghost:null};
  });
  document.addEventListener('pointermove',event=>{
    if(!pointerDrag)return;
    if(!pointerDrag.moved && Math.hypot(event.clientX-pointerDrag.startX,event.clientY-pointerDrag.startY)<7)return;
    if(!pointerDrag.moved){
      pointerDrag.moved=true;
      const ghost=node('div','ensha-builder-drag-ghost',pointerDrag.label);
      document.body.append(ghost);pointerDrag.ghost=ghost;
    }
    event.preventDefault();
    pointerDrag.ghost.style.left=`${event.clientX+14}px`;
    pointerDrag.ghost.style.top=`${event.clientY+14}px`;
    document.getElementById('builder-dropzone').classList.toggle('over',!!document.elementFromPoint(event.clientX,event.clientY)?.closest('.ensha-builder-canvas'));
  });
  document.addEventListener('pointerup',event=>{
    if(!pointerDrag)return;
    const current=pointerDrag;pointerDrag=null;
    current.ghost?.remove();document.getElementById('builder-dropzone').classList.remove('over');
    if(!current.moved)return;
    suppressPaletteClick=true;setTimeout(()=>suppressPaletteClick=false,100);
    const target=document.elementFromPoint(event.clientX,event.clientY);
    if(target?.closest('.ensha-builder-canvas')){
      const card=target.closest('.ensha-builder-card');
      const index=card?state.fields.findIndex(field=>field.uid===card.dataset.uid):state.fields.length;
      newField(current.type,current.label,index<0?state.fields.length:index);
    }
  });
  document.addEventListener('pointercancel',()=>{pointerDrag?.ghost?.remove();pointerDrag=null;document.getElementById('builder-dropzone').classList.remove('over');});
  document.getElementById('builder-search').addEventListener('input',e=>document.querySelectorAll('.ensha-builder-type').forEach(button=>button.hidden=!button.dataset.label.includes(e.target.value.trim())));
  cards.addEventListener('click',e=>{
    const card=e.target.closest('.ensha-builder-card');if(!card)return;
    const action=e.target.closest('[data-action]')?.dataset.action;
    const index=state.fields.findIndex(f=>f.uid===card.dataset.uid);
    if(action==='delete'){state.fields.splice(index,1);if(state.selected===card.dataset.uid)state.selected=null;mark();render();return;}
    if(action==='clone'){const source=state.fields[index];const uid=`draft-${++serial}`;const copy=JSON.parse(JSON.stringify(source));copy.uid=uid;copy.id=null;copy.label=`کپی ${source.label}`.slice(0,150);copy.key=`${source.key.slice(0,65)}_copy_${serial}`;state.fields.splice(index+1,0,copy);state.selected=uid;mark();render();return;}
    if(action==='up'||action==='down'){const next=index+(action==='up'?-1:1);if(next<0||next>=state.fields.length)return;[state.fields[index],state.fields[next]]=[state.fields[next],state.fields[index]];mark();render();return;}
    state.selected=card.dataset.uid;render();
  });
  document.getElementById('builder-panel-back').addEventListener('click',()=>{state.selected=null;render();});
  settings.addEventListener('input',e=>{
    const field=selected();if(!field)return;const input=e.target;const key=input.dataset.property;
    if(key){field[key]=input.type==='checkbox'?input.checked:input.value;if(key==='field_type'){if(!choiceTypes.includes(field.field_type))field.options=[];else if(field.options.length===0)field.options=[{label:'گزینهٔ اول',value:'option_1'},{label:'گزینهٔ دوم',value:'option_2'}];render();}}
    else if(input.dataset.setting)field.settings[input.dataset.setting]=input.value;
    else return;
    mark();if(key==='label'||key==='is_required'){const title=cards.querySelector('.is-selected .ensha-builder-card-label');if(title)title.textContent=field.label+(field.is_required?' *':'');}
  });
  settings.addEventListener('change',e=>{if(e.target.dataset.setting||e.target.dataset.property)render();});
  rows.addEventListener('input',e=>{const field=selected();if(!field||!e.target.dataset.part)return;field.options[Number(e.target.dataset.index)][e.target.dataset.part]=e.target.value;mark();const preview=cards.querySelector('.is-selected .ensha-builder-preview-control');if(preview)renderPreview(field,preview);});
  rows.addEventListener('click',e=>{const remove=e.target.closest('[data-remove]');if(!remove)return;const field=selected();field.options.splice(Number(remove.dataset.remove),1);mark();renderOptions(field);renderPreview(field,cards.querySelector('.is-selected .ensha-builder-preview-control'));});
  document.getElementById('builder-option-add').addEventListener('click',()=>{const field=selected();field.options.push({label:'',value:''});mark();renderOptions(field);rows.lastElementChild?.querySelector('input')?.focus();});
  const drop=document.getElementById('builder-dropzone');
  cards.addEventListener('dragstart',e=>{const card=e.target.closest('.ensha-builder-card');if(!card)return;dragged={uid:card.dataset.uid};card.classList.add('dragging');e.dataTransfer.effectAllowed='move';e.dataTransfer.setData('text/plain',card.dataset.uid);});
  cards.addEventListener('dragend',()=>{dragged=null;cards.querySelectorAll('.dragging').forEach(card=>card.classList.remove('dragging'));});
  cards.addEventListener('dragover',e=>{e.preventDefault();if(!dragged)return;const target=e.target.closest('.ensha-builder-card');if(!target||!dragged.uid)return;const moving=cards.querySelector('.dragging');if(!moving||target===moving)return;cards.insertBefore(moving,e.clientY>target.getBoundingClientRect().top+target.offsetHeight/2?target.nextSibling:target);});
  cards.addEventListener('drop',e=>{e.preventDefault();const type=dragged?.type||e.dataTransfer?.getData('application/x-ensha-field');if(type&&typeNames[type]){const target=e.target.closest('.ensha-builder-card');newField(type,typeNames[type],target?state.fields.findIndex(f=>f.uid===target.dataset.uid):state.fields.length);return;}if(!dragged?.uid)return;const order=[...cards.children].map(card=>card.dataset.uid);state.fields.sort((a,b)=>order.indexOf(a.uid)-order.indexOf(b.uid));mark();render();});
  drop.addEventListener('dragover',e=>{e.preventDefault();drop.classList.add('over');});
  drop.addEventListener('dragleave',()=>drop.classList.remove('over'));
  drop.addEventListener('drop',e=>{e.preventDefault();drop.classList.remove('over');const type=dragged?.type||e.dataTransfer?.getData('application/x-ensha-field')||e.dataTransfer?.getData('text/plain');if(type&&typeNames[type])newField(type,typeNames[type]);else if(dragged?.uid){const index=state.fields.findIndex(f=>f.uid===dragged.uid);state.fields.push(state.fields.splice(index,1)[0]);mark();render();}});
  document.querySelectorAll('.ensha-builder-tabs a').forEach(tab=>tab.addEventListener('click',e=>{
    e.preventDefault();const url=new URL(tab.href);const role=url.searchParams.get('role');if(!state.forms[role])return;
    root.dataset.role=role;state.selected=null;
    document.querySelectorAll('.ensha-builder-tabs a').forEach(a=>a.classList.toggle('active',a===tab));
    document.getElementById('builder-form-title').textContent=`فرم ${tab.textContent.trim()}`;
    document.getElementById('builder-core').hidden=role!=='all';
    document.getElementById('builder-shared').hidden=role==='all';
    history.replaceState(null,'',url.pathname+url.search);render();
  }));
  document.getElementById('builder-preview-toggle').addEventListener('click',e=>{root.classList.toggle('preview-only');e.currentTarget.textContent=root.classList.contains('preview-only')?'بازگشت به ویرایش':'پیش‌نمایش';});
  window.addEventListener('beforeunload',e=>{if(!state.dirty)return;e.preventDefault();e.returnValue='';});
  document.getElementById('builder-save').addEventListener('click',async e=>{
    if(state.saving||!state.dirty)return;
    state.saving=true;e.currentTarget.disabled=true;feedback.textContent='در حال ذخیرهٔ همهٔ تغییرات…';
    try{
      const payload={forms:Object.fromEntries(Object.entries(state.forms).map(([role,form])=>[role,{version:form.version,fields:form.fields.map(({id,label,key,field_type,is_required,is_active,options,settings})=>({id,label,key,field_type,is_required,is_active,options:choiceTypes.includes(field_type)?options:[],settings}))}]))};
      const response=await fetch(root.dataset.saveUrl,{method:'PUT',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content},body:JSON.stringify(payload)});
      const result=await response.json();
      if(!response.ok)throw new Error(Object.values(result.errors||{})[0]?.[0]||result.message||'ذخیره انجام نشد.');
      Object.entries(result.forms).forEach(([role,form])=>{state.forms[role]={version:form.version,fields:form.fields.map(f=>({...f,uid:`saved-${f.id}`,options:normalize(f.options),settings:f.settings||{}}))};});state.selected=null;state.dirty=false;document.getElementById('builder-dirty').hidden=true;render();feedback.textContent='تمام تغییرات فرم ذخیره شد.';
    }catch(error){feedback.textContent=error.message;}finally{state.saving=false;e.currentTarget.disabled=false;}
  });
  render();
  feedback.textContent='فرم‌ساز آماده است؛ برای افزودن کلیک کنید یا بکشید.';
  root.dataset.builderReady='true';
})();
