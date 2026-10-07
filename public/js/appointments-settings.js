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
})();
