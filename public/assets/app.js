document.addEventListener('DOMContentLoaded', () => {
  const cards = [...document.querySelectorAll('[data-site]')];
  const search = document.querySelector('#site-search');
  const tabs = [...document.querySelectorAll('[data-filter]')];
  let filter = 'all';
  const apply = () => {
    const query = (search?.value || '').trim().toLocaleLowerCase();
    let visible = 0;
    cards.forEach(card => {
      const match = card.dataset.search.toLocaleLowerCase().includes(query)
        && (filter === 'all' || card.dataset[filter] === '1');
      card.hidden = !match;
      visible += Number(match);
    });
    const empty = document.querySelector('.no-results');
    if (empty) empty.hidden = visible > 0 || (cards.length === 0 && !query && filter === 'all');
    const add = document.querySelector('.add-card');
    if (add) add.hidden = filter !== 'all' || Boolean(query);
  };
  tabs.forEach(tab => tab.addEventListener('click', () => {
    filter = tab.dataset.filter;
    tabs.forEach(item => {
      item.classList.toggle('selected', item === tab);
      item.setAttribute('aria-pressed', String(item === tab));
    });
    apply();
  }));
  search?.addEventListener('input', apply);
  document.querySelectorAll('[data-check]').forEach(form => form.addEventListener('submit', () => {
    const button = form.querySelector('button');
    button.disabled = true;
    button.classList.add('checking');
    button.setAttribute('aria-label', 'Проверка выполняется');
  }));

  const form = document.querySelector('[data-site-form]');
  if (!form) return;
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
