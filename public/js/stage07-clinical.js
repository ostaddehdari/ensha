(() => {
  const formatter = new Intl.DateTimeFormat('fa-IR-u-ca-persian', {year:'numeric', month:'long', day:'numeric', hour:'2-digit', minute:'2-digit'});
  document.querySelectorAll('[data-jalali-datetime]').forEach(element => {
    const date = new Date(element.dataset.jalaliDatetime);
    if (!Number.isNaN(date.getTime())) element.textContent = formatter.format(date);
  });
})();
