globalThis.TabloDashboardView = (() => {
  function attach(dashboard) {
    const search = dashboard.querySelector('#site-search');
    const filters = [...dashboard.querySelectorAll('[data-filter]')];
    const modes = [...dashboard.querySelectorAll('[data-view]')];
    const snapshot = dashboard.querySelector('[data-dashboard-snapshot]');
    const status = dashboard.querySelector('[data-dashboard-refresh]');
    let views;
    let groups;
    let filter = 'all';
    let view = 'tile';
    let navigation = false;
    let polling;
    let storage;
    try { storage = window.localStorage; } catch {}
    try {
      const saved = storage?.getItem('tablo.dashboard.view.v1');
      if (saved === 'tile' || saved === 'table') view = saved;
    } catch {}
    let historyTimer;
    const rebuild = () => {
      views = [...dashboard.querySelectorAll('[data-dashboard-view]')];
      groups = new Map();
      dashboard.querySelectorAll('[data-site]').forEach(item => {
        const id = item.dataset.siteId;
        if (!groups.has(id)) groups.set(id, []);
        groups.get(id).push(item);
      });
    };
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
    const fallbackFocus = () => modes.find(button => button.dataset.view === view)?.focus({preventScroll: true});
    const apply = (manageFocus = true) => {
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
      if (manageFocus && active instanceof HTMLElement && active.closest('[data-site]') && active.closest('[hidden]')) fallbackFocus();
    };

    const protocolError = () => { throw new TabloDashboardPolling.ProtocolError('Invalid dashboard markup'); };
    const prepare = result => {
      const template = document.createElement('template');
      template.innerHTML = result.html;
      const fragment = template.content;
      if (fragment.querySelector('script,style,link,meta,base,iframe,object,embed')
        || [...fragment.querySelectorAll('*')].some(item => [...item.attributes].some(attr => /^on/i.test(attr.name)))) protocolError();
      const tile = fragment.querySelectorAll('[data-dashboard-view="tile"]');
      const table = fragment.querySelectorAll('[data-dashboard-view="table"]');
      if (tile.length !== 1 || table.length !== 1 || fragment.querySelectorAll('[data-dashboard-view]').length !== 2
        || fragment.querySelectorAll('.add-card').length !== 1) protocolError();
      const tileIds = [...tile[0].querySelectorAll('[data-site]')].map(item => item.dataset.siteId);
      const tableIds = [...table[0].querySelectorAll('[data-site]')].map(item => item.dataset.siteId);
      const forms = [...fragment.querySelectorAll('form')];
      if (tileIds.length !== result.stats.total || forms.length !== tileIds.length
        || new Set(tileIds).size !== tileIds.length || tileIds.some((id, index) => !/^[1-9][0-9]*$/.test(id) || tableIds[index] !== id)
        || tableIds.length !== tileIds.length || fragment.querySelectorAll('[data-site]').length !== tileIds.length * 2) protocolError();
      const formById = new Map(forms.map(form => [form.id, form]));
      if (formById.size !== forms.length) protocolError();
      for (const id of tileIds) {
        const form = formById.get(`check-site-${id}`);
        if (!form || !form.matches('[data-check]') || form.getAttribute('action') !== `/sites/${id}/check`
          || form.method !== 'post' || form.querySelectorAll('input[name="_csrf"]').length !== 1
          || form.querySelector('input[name="_csrf"]').value !== dashboard.dataset.dashboardCsrf
          || !['0', '1'].includes(form.dataset.paused)) protocolError();
        for (const root of [tile[0], table[0]]) {
          const site = [...root.querySelectorAll('[data-site]')].find(item => item.dataset.siteId === id);
          const buttons = site.querySelectorAll('[data-check-button]');
          if (typeof site.dataset.search !== 'string' || !['0', '1'].includes(site.dataset.paused)
            || !['0', '1'].includes(site.dataset.attention) || buttons.length !== 1
            || buttons[0].getAttribute('form') !== form.id || buttons[0].getAttribute('type') !== 'submit') protocolError();
        }
      }
      return {fragment, forms};
    };

    const focusIdentity = element => {
      if (!(element instanceof HTMLElement) || !snapshot.contains(element)) return;
      const site = element.closest('[data-site]');
      const root = element.closest('[data-dashboard-view]');
      if (!site) return {region: root?.dataset.dashboardView, kind: element.matches('.add-card') ? 'add' : 'region'};
      const candidates = [...site.querySelectorAll('a,button,summary,input,[tabindex]')];
      const kind = element.matches('[data-check-button]') ? 'check'
        : element.matches('summary') ? 'summary' : element.tagName.toLowerCase();
      return {id: site.dataset.siteId, mode: root?.dataset.dashboardView, kind,
        href: element.getAttribute('href'), index: candidates.filter(item => item.tagName === element.tagName
          && item.getAttribute('href') === element.getAttribute('href')).indexOf(element)};
    };
    const restoreFocus = identity => {
      if (!identity) return;
      const root = views.find(item => item.dataset.dashboardView === (identity.mode || identity.region));
      const site = root && [...root.querySelectorAll('[data-site]')].find(item => item.dataset.siteId === identity.id);
      let target;
      if (!identity.id) target = identity.kind === 'add' ? root?.querySelector('.add-card') : root?.tabIndex >= 0 ? root : null;
      else if (identity.kind === 'check') target = site?.querySelector('[data-check-button]');
      else if (identity.kind === 'summary') target = site?.querySelectorAll('summary')[identity.index];
      else target = [...(site?.querySelectorAll('a,button,summary,input,[tabindex]') || [])]
        .filter(item => item.tagName.toLowerCase() === identity.kind && item.getAttribute('href') === identity.href)[identity.index];
      if (target instanceof HTMLElement && !target.closest('[hidden]') && !target.disabled) target.focus({preventScroll: true});
      else fallbackFocus();
    };
    const commit = result => {
      const {fragment, forms} = prepare(result);
      const identity = focusIdentity(document.activeElement);
      const scroll = {x: window.scrollX, y: window.scrollY};
      const oldTable = views.find(item => item.dataset.dashboardView === 'table');
      const tableScroll = {left: oldTable?.scrollLeft || 0, top: oldTable?.scrollTop || 0};
      const expanded = new Set();
      views.forEach(root => root.querySelectorAll('[data-site]').forEach(site => site.querySelectorAll('details').forEach((item, index) => {
        if (item.open) expanded.add(`${root.dataset.dashboardView}/${site.dataset.siteId}/${index}`);
      })));
      const oldForms = new Map([...snapshot.querySelectorAll('form[data-check]')].map(form => [form.id, form]));
      forms.forEach(form => {
        const existing = oldForms.get(form.id);
        if (existing) { existing.dataset.paused = form.dataset.paused; form.replaceWith(existing); }
      });
      snapshot.replaceChildren(fragment);
      rebuild();
      views.forEach(root => root.querySelectorAll('[data-site]').forEach(site => site.querySelectorAll('details').forEach((item, index) => {
        item.open = expanded.has(`${root.dataset.dashboardView}/${site.dataset.siteId}/${index}`);
      })));
      Object.entries(result.stats).forEach(([key, value]) => document.querySelectorAll(`[data-dashboard-count="${key}"]`).forEach(item => { item.textContent = String(value); }));
      apply(false);
      restoreFocus(identity);
      const table = views.find(item => item.dataset.dashboardView === 'table');
      if (table) { table.scrollLeft = tableScroll.left; table.scrollTop = tableScroll.top; }
      window.scrollTo(scroll.x, scroll.y);
    };

    modes.forEach(button => button.addEventListener('click', () => {
      view = button.dataset.view;
      try { storage?.setItem('tablo.dashboard.view.v1', view); } catch {}
      apply();
    }));
    filters.forEach(button => button.addEventListener('click', () => { filter = button.dataset.filter; remember(); apply(); }));
    search.addEventListener('input', () => { apply(); clearTimeout(historyTimer); historyTimer = setTimeout(remember, 150); });
    const availability = () => polling?.setAvailable(!navigation && document.visibilityState !== 'hidden' && navigator.onLine !== false);
    const pauseNavigation = () => { navigation = true; availability(); };
    window.addEventListener('pagehide', () => { remember(); pauseNavigation(); });
    window.addEventListener('popstate', () => { clearTimeout(historyTimer); restore(); apply(); });
    window.addEventListener('pageshow', event => {
      clearTimeout(historyTimer); restore(); apply();
      if (event.isTrusted && event.persisted) navigation = false;
      availability();
    });
    document.addEventListener('visibilitychange', availability);
    window.addEventListener('online', availability);
    window.addEventListener('offline', availability);
    document.addEventListener('click', event => {
      const anchor = event.target instanceof Element ? event.target.closest('a[href]') : null;
      if (!anchor || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey
        || anchor.hasAttribute('download') || (anchor.target && anchor.target !== '_self')) return;
      const url = new URL(anchor.href, location.href);
      if (url.origin === location.origin && url.pathname === location.pathname && url.search === location.search && url.hash) return;
      pauseNavigation();
    });
    rebuild(); restore(); apply();
    dashboard.querySelector('[data-dashboard-controls]').hidden = false;
    if (snapshot && status && dashboard.dataset.dashboardCsrf && dashboard.dataset.dashboardState === '/dashboard/state'
      && globalThis.TabloDashboardPolling && globalThis.fetch && globalThis.AbortController && globalThis.ReadableStream) {
      const onState = state => {
        // Keep routine polling silent, including retries that are still pending.
        if (state === 'loading') return;
        const message = state === 'stopped' ? 'Автоматическое обновление остановлено. Обновить страницу'
          : state === 'retrying' ? 'Не удалось обновить данные. Повторим автоматически.'
            : 'Сохранённые результаты обновляются автоматически.';
        if (status.textContent === message) return;
        if (state === 'stopped') {
          status.replaceChildren(document.createTextNode('Автоматическое обновление остановлено. '));
          const link = document.createElement('a'); link.href = '/'; link.textContent = 'Обновить страницу'; status.append(link);
        } else status.textContent = message;
      };
      polling = TabloDashboardPolling.create({request: TabloDashboardPolling.transport({
        url: new URL('/dashboard/state', location.origin).href, csrf: dashboard.dataset.dashboardCsrf}),
        commit, onState, available: !navigation && document.visibilityState !== 'hidden' && navigator.onLine !== false});
    }
    return {pauseNavigation};
  }
  return {attach};
})();
