(() => {
  const select=document.querySelector('[data-role-select]');
  if(!select) return;
  const sections=[...document.querySelectorAll('[data-profile-role]')];
  function sync(){ const value=select.value; sections.forEach(section=>{const show=section.dataset.profileRole==='all'||section.dataset.roleId===value; section.hidden=!show; section.querySelectorAll('input,select,textarea').forEach(input=>{input.disabled=!show;});}); }
  select.addEventListener('change',sync); sync();
})();
