document.addEventListener('DOMContentLoaded', () => {
  const dashboard = document.querySelector('[data-dashboard]');
  if (dashboard) {
    const search = dashboard.querySelector('#site-search');
    const filters = [...dashboard.querySelectorAll('[data-filter]')];
    const modes = [...dashboard.querySelectorAll('[data-view]')];
    const views = [...dashboard.querySelectorAll('[data-dashboard-view]')];
    const groups = new Map();
    dashboard.querySelectorAll('[data-site]').forEach(item => {
      const id = item.dataset.siteId;
      if (!groups.has(id)) groups.set(id, []);
      groups.get(id).push(item);
    });
    let filter = 'all';
    let view = 'tile';
    let storage;
    // Accessing the storage object, reading it and writing it can each throw.
    try { storage = window.localStorage; } catch {}
    try {
      const saved = storage?.getItem('tablo.dashboard.view.v1');
      if (saved === 'tile' || saved === 'table') view = saved;
    } catch {}
    let historyTimer;
    const remember = () => {
      clearTimeout(historyTimer);
      try {
        const current = history.state;
        const base = current && typeof current === 'object' && !Array.isArray(current) ? current : {};
        history.replaceState({...base, tabloDashboard: {query: search.value, filter}}, '');
      } catch {}
    };
    const restore = () => {
      let state;
      try { state = history.state?.tabloDashboard; } catch {}
      search.value = state && typeof state.query === 'string' ? state.query : '';
      filter = ['all', 'attention', 'paused'].includes(state?.filter) ? state.filter : 'all';
    };
    const apply = () => {
      const query = search.value.trim().toLocaleLowerCase();
      let visible = 0;
      const active = document.activeElement;
      groups.forEach(items => {
        const site = items[0];
        const match = site.dataset.search.toLocaleLowerCase().includes(query)
          && (filter === 'all' || site.dataset[filter] === '1');
        items.forEach(item => { item.hidden = !match; });
        visible += Number(match);
      });
      views.forEach(item => { item.hidden = item.dataset.dashboardView !== view || (view === 'table' && visible === 0); });
      modes.forEach(button => { button.setAttribute('aria-pressed', String(button.dataset.view === view)); });
      filters.forEach(button => {
        const selected = button.dataset.filter === filter;
        button.classList.toggle('selected', selected);
        button.setAttribute('aria-pressed', String(selected));
      });
      dashboard.querySelector('.no-results').hidden = visible > 0 || groups.size === 0;
      dashboard.querySelector('.add-card').hidden = filter !== 'all' || Boolean(query);
      dashboard.querySelector('[data-table-add]').hidden = view !== 'table';
      // A hidden row/view must not leave keyboard focus inside an invisible action.
      if (active instanceof HTMLElement && active.closest('[data-site]') && active.closest('[hidden]')) {
        modes.find(button => button.dataset.view === view)?.focus();
      }
    };
    modes.forEach(button => button.addEventListener('click', () => {
      view = button.dataset.view;
      try { storage?.setItem('tablo.dashboard.view.v1', view); } catch {}
      apply();
    }));
    filters.forEach(button => button.addEventListener('click', () => {
      filter = button.dataset.filter;
      remember();
      apply();
    }));
    search.addEventListener('input', () => {
      apply();
      clearTimeout(historyTimer);
      historyTimer = setTimeout(remember, 150);
    });
    window.addEventListener('pagehide', remember);
    window.addEventListener('popstate', () => { clearTimeout(historyTimer); restore(); apply(); });
    window.addEventListener('pageshow', () => { clearTimeout(historyTimer); restore(); apply(); });
    restore();
    apply();
    dashboard.querySelector('[data-dashboard-controls]').hidden = false;
  }
  document.querySelectorAll('[data-check]').forEach(checkForm => {
    const buttons = [...checkForm.elements].filter(element => element.matches('[data-check-button]'));
    const labels = new Map(buttons.map(button => [button, button.getAttribute('aria-label')]));
    let pending = false;
    checkForm.addEventListener('submit', event => {
      if (pending || checkForm.dataset.paused === '1') { event.preventDefault(); return; }
      pending = true;
      buttons.forEach(button => {
        button.disabled = true;
        button.classList.add('checking');
        button.setAttribute('aria-label', 'Проверка выполняется');
      });
    });
    // Only a restored navigation entry can complete its old pending UI state.
    // Synthetic pageshow or local interactions cannot revive a pending check.
    window.addEventListener('pageshow', event => {
      if (!event.isTrusted || !event.persisted) return;
      pending = false;
      buttons.forEach(button => {
        button.disabled = checkForm.dataset.paused === '1';
        button.classList.remove('checking');
        button.setAttribute('aria-label', labels.get(button));
      });
    });
  });

  const form = document.querySelector('[data-site-form]');
  if (!form) return;
  const healthMode = form.querySelector('#health_check_mode');
  const healthEndpoint = form.querySelector('#health_path');
  const healthModeFields = form.querySelector('#health-mode-fields');
  const healthJsonFields = form.querySelector('#health-json-fields');
  const healthJsonPath = form.querySelector('#health_json_path');
  const versionEndpoint = form.querySelector('#version_path');
  const versionJsonPath = form.querySelector('#version_json_path');
  const checkFields = () => {
    const homePage = healthEndpoint.value.trim() === '';
    const json = !homePage && healthMode.value === 'json';
    healthModeFields.hidden = homePage;
    healthJsonFields.hidden = !json;
    healthJsonPath.required = json;
    versionJsonPath.disabled = versionEndpoint.value.trim() === '';
  };
  healthMode.addEventListener('change', checkFields);
  healthEndpoint.addEventListener('input', checkFields);
  versionEndpoint.addEventListener('input', checkFields);
  checkFields();
  const repository = form.querySelector('#repository');
  const token = form.querySelector('#github_token');
  const savedToken = form.querySelector('#git_token_id');
  const projectTokenFields = form.querySelector('#project-token-fields');
  const removeToken = form.querySelector('[name="remove_github_token"]');
  const branch = form.querySelector('#branch');
  const options = form.querySelector('#branch-options');
  const status = form.querySelector('#branch-status');
  const load = form.querySelector('#load-branches');
  const link = form.querySelector('#repository-link');
  let active;
  let revision = 0;
  const invalidate = () => {
    ++revision;
    active?.abort();
    load.disabled = false;
    load.classList.remove('checking');
    options.hidden = true;
    options.replaceChildren();
    branch.hidden = false;
    branch.required = true;
    status.classList.remove('is-error');
    status.textContent = 'Загрузите ветки из GitHub с указанным токеном. При сохранении выбранная ветка проверяется повторно.';
    const slug = repository.value.trim().replace(/^https:\/\/github\.com\//i, '').replace(/\/$/, '').replace(/\.git$/, '');
    const valid = /^[a-zA-Z0-9][a-zA-Z0-9-]{0,38}\/[a-zA-Z0-9_.-]{1,100}$/.test(slug) && !/\/(\.|\.\.)$/.test(slug);
    link.hidden = !valid;
    if (valid) link.href = `https://github.com/${slug}`;
  };
  repository.addEventListener('input', invalidate);
  token.addEventListener('input', invalidate);
  const tokenMode = () => {
    const selected = savedToken.value !== '';
    projectTokenFields.hidden = selected;
    projectTokenFields.querySelectorAll('input').forEach(input => { input.disabled = selected; });
  };
  savedToken.addEventListener('change', () => { tokenMode(); invalidate(); });
  tokenMode();
  removeToken?.addEventListener('change', invalidate);
  options.addEventListener('change', () => { branch.value = options.value; });
  invalidate();
  load.addEventListener('click', async () => {
    active?.abort();
    active = new AbortController();
    const controller = active;
    const requestRevision = ++revision;
    const timeout = setTimeout(() => controller.abort(), 35000);
    load.disabled = true;
    load.classList.add('checking');
    status.classList.remove('is-error');
    status.textContent = 'Проверяем доступ и загружаем ветки…';
    const data = new FormData();
    ['_csrf', 'repository', 'github_token', 'site_id', 'git_token_id'].forEach(name => {
      const field = form.elements.namedItem(name);
      if (field && !field.disabled) data.set(name, field.value);
    });
    if (removeToken?.checked && !removeToken.disabled) data.set('remove_github_token', '1');
    try {
      const response = await fetch('/sites/branches', { method: 'POST', body: data,
        credentials: 'same-origin', signal: controller.signal, headers: { Accept: 'application/json' } });
      if (!response.headers.get('content-type')?.includes('application/json')) {
        throw new Error('Сессия или форма устарела. Обновите страницу и войдите снова.');
      }
      const result = await response.json();
      if (!response.ok) throw new Error(result.error || 'Не удалось получить ветки.');
      if (requestRevision !== revision) return;
      const selected = result.branches.includes(branch.value) ? branch.value
        : (result.branches.includes(result.default_branch) ? result.default_branch : result.branches[0]);
      options.replaceChildren(...result.branches.map(name => new Option(name === result.default_branch ? `${name} — основная` : name, name)));
      options.value = selected;
      branch.value = selected;
      branch.required = false;
      branch.hidden = true;
      options.hidden = false;
      status.textContent = `Доступ подтверждён. Веток: ${result.branches.length}. Выберите ветку и сохраните сайт.`;
    } catch (error) {
      if (requestRevision !== revision) return;
      options.hidden = true;
      branch.hidden = false;
      branch.required = true;
      status.classList.add('is-error');
      status.textContent = error.name === 'AbortError' ? 'GitHub не ответил вовремя. Повторите загрузку.' : error.message;
    } finally {
      clearTimeout(timeout);
      if (requestRevision === revision) {
        load.disabled = false;
        load.classList.remove('checking');
      }
    }
  });
});
