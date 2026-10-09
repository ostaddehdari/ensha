(() => {
  const tabs = [...document.querySelectorAll('[data-tab]')];
  const panes = [...document.querySelectorAll('[data-pane]')];
  if (!tabs.length) return;
  const show = key => {
    if (!panes.some(p => p.dataset.pane === key)) key = tabs[0].dataset.tab;
    tabs.forEach(tab => { const active = tab.dataset.tab === key; tab.classList.toggle('active', active); tab.setAttribute('aria-selected', active ? 'true' : 'false'); });
    panes.forEach(pane => { const active = pane.dataset.pane === key; pane.hidden = !active; pane.classList.toggle('active', active); });
    history.replaceState(null, '', `#${key}`);
  };
  tabs.forEach(tab => tab.addEventListener('click', () => show(tab.dataset.tab)));
  show(location.hash.slice(1) || tabs[0].dataset.tab);

  const builder = document.querySelector('[data-report-template-form]');
  if (!builder) return;
  const fields = builder.querySelector('[data-report-fields]');
  const template = builder.querySelector('[data-report-field-template]');
  const rename = () => [...fields.querySelectorAll('[data-report-field]')].forEach((row, index) => {
    row.querySelectorAll('[data-name]').forEach(input => { input.name = `fields[${index}][${input.dataset.name}]`; });
    row.querySelectorAll('[name^="fields["]').forEach(input => { input.name = input.name.replace(/fields\[\d+\]/, `fields[${index}]`); });
  });
  const bind = row => {
    const type = row.querySelector('[data-report-field-type]');
    const options = row.querySelector('[data-report-options]');
    const toggleOptions = () => { options.disabled = type.value !== 'select'; if (options.disabled) options.value = ''; };
    type.addEventListener('change', toggleOptions);
    toggleOptions();
    row.querySelector('[data-remove-report-field]').addEventListener('click', () => {
      if (fields.querySelectorAll('[data-report-field]').length === 1) return;
      row.remove(); rename();
    });
  };
  fields.querySelectorAll('[data-report-field]').forEach(bind);
  builder.querySelector('[data-add-report-field]').addEventListener('click', () => {
    if (fields.querySelectorAll('[data-report-field]').length >= 30) return;
    const row = template.content.firstElementChild.cloneNode(true);
    fields.append(row); bind(row); rename();
  });
})();
