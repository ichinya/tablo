{% extends "layout.volt" %}
{% block content %}
<a class="back-link" href="/"><svg><use href="/assets/icons.svg#arrow-left"></use></svg>Назад к сайтам</a>
<div class="page-heading form-heading"><div><div class="eyebrow">{% if editing %}НАСТРОЙКИ{% else %}НОВЫЙ САЙТ{% endif %}</div><h1>{{ title }}<span>.</span></h1><p>Пара деталей — и ваш сайт появится в общей картине.</p></div></div>
<div class="form-layout"><form class="settings-form panel" action="{{ action }}" method="post" data-site-form>
    <input type="hidden" name="_csrf" value="{{ csrf }}">
    {% if editing %}<input type="hidden" name="site_id" value="{{ site['id'] }}">{% endif %}
    {% if errors %}<div class="form-errors" role="alert"><strong>Проверьте данные формы</strong>{% for field, message in errors %}<p>{{ message }}</p>{% endfor %}</div>{% endif %}
    <div class="form-section"><h2>О сайте</h2><p class="section-description">Как сайт будет отображаться в вашей панели.</p>
        <label for="name">Название</label><input id="name" name="name" value="{{ site['name'] }}" placeholder="Название проекта" maxlength="80" required autofocus>
        <label for="url">Адрес сайта</label><input type="url" id="url" name="url" value="{{ site['url'] }}" placeholder="https://example.com" maxlength="500" required><p class="field-hint">Полный адрес с https:// или http://.</p>
    </div>
    <div class="form-section"><div class="section-title"><h2>Репозиторий</h2><span class="provider-pill"><svg><use href="/assets/icons.svg#github"></use></svg>GitHub</span></div><p class="section-description">Откуда брать последнюю версию и количество открытых задач.</p>
        <label for="repository">Ссылка на GitHub репозиторий</label><input id="repository" name="repository" value="{{ site['repository'] }}" placeholder="https://github.com/owner/repository" maxlength="200" required><p class="field-hint">Полный URL или owner/repository. <a id="repository-link" href="https://github.com" target="_blank" rel="noopener noreferrer" hidden>Открыть репозиторий ↗</a></p>
        <label for="git_token_id">Токен доступа</label><select id="git_token_id" name="git_token_id"><option value="">Ввести токен вручную</option>{% for token in git_tokens %}<option value="{{ token['id'] }}" {% if site['git_token_id'] == token['id'] %}selected{% endif %}>{{ token['name'] }} · GitHub</option>{% endfor %}</select><p class="field-hint">Сохранённые токены можно добавить и изменить в <a href="/settings">настройках</a>.</p>
        <div id="project-token-fields">
        <label for="github_token">Токен для этого проекта</label><input type="password" id="github_token" name="github_token" value="" placeholder="Токен доступа" maxlength="512" autocomplete="off" spellcheck="false"><p class="field-hint">{% if site['has_github_token'] %}Токен сохранён. Оставьте поле пустым, чтобы использовать его, или введите новый.{% else %}Для приватного репозитория нужен токен с доступом к нему. Для публичного можно оставить пустым.{% endif %} Для веток: Contents — Read. <a href="https://github.com/settings/personal-access-tokens/new" target="_blank" rel="noopener noreferrer">Создать токен ↗</a></p>
        {% if site['has_github_token'] %}<label class="checkbox-label token-remove"><input type="checkbox" name="remove_github_token" value="1"><span>Удалить токен проекта</span></label>{% endif %}
        </div>
        <div class="branch-heading"><label for="branch">Ветка</label><button class="button secondary compact" type="button" id="load-branches"><svg><use href="/assets/icons.svg#refresh"></use></svg>Получить ветки</button></div>
        <input id="branch" name="branch" value="{{ site['branch'] }}" placeholder="main" maxlength="128" required>
        <select id="branch-options" aria-label="Ветка из GitHub" hidden></select>
        <p class="field-hint branch-status" id="branch-status" role="status" aria-live="polite">Загрузите ветки из GitHub с указанным токеном. При сохранении выбранная ветка проверяется повторно.</p>
        <fieldset class="comparison-choice"><legend>Сравнивать установленную версию с</legend><label class="radio-card"><input type="radio" name="comparison_mode" value="release" {% if site['comparison_mode'] == 'release' %}checked{% endif %}><span><strong>Последним релизом</strong><small>Последний опубликованный стабильный release</small></span></label><label class="radio-card"><input type="radio" name="comparison_mode" value="branch" {% if site['comparison_mode'] == 'branch' %}checked{% endif %}><span><strong>HEAD ветки</strong><small>Последний коммит выбранной ветки</small></span></label></fieldset>
    </div>
    <div class="form-section"><h2>Проверки</h2><p class="section-description">Пути относительно адреса сайта, без перехода на другой домен.</p>
        <label for="health_path">Health endpoint</label><input id="health_path" name="health_path" value="{{ site['health_path'] }}" placeholder="/up" maxlength="300" required>
        <label for="health_check_mode">Проверка доступности</label>
        <select id="health_check_mode" name="health_check_mode" aria-describedby="health-mode-hint{% if errors['health_check_mode'] is defined %} health_check_mode-error{% endif %}" {% if errors['health_check_mode'] is defined %}aria-invalid="true"{% endif %}>
            <option value="http" {% if site['health_check_mode'] == 'http' %}selected{% endif %}>HTTP-статус</option>
            <option value="json" {% if site['health_check_mode'] == 'json' %}selected{% endif %}>JSON-поле</option>
        </select>
        <p class="field-hint" id="health-mode-hint">HTTP 2xx означает Online. В режиме JSON дополнительно проверяется выбранное значение.</p>
        {% if errors['health_check_mode'] is defined %}<p class="field-error" id="health_check_mode-error">{{ errors['health_check_mode'] }}</p>{% endif %}
        <div class="json-check-fields" id="health-json-fields">
            <label for="health_json_path">JSON path</label><input id="health_json_path" name="health_json_path" value="{{ site['health_json_path'] }}" placeholder="$.result" maxlength="512" spellcheck="false" aria-describedby="health-json-hint{% if errors['health_json_path'] is defined %} health_json_path-error{% endif %}" {% if errors['health_json_path'] is defined %}aria-invalid="true"{% endif %}>
            <p class="field-hint" id="health-json-hint">Поле из JSON-ответа, например $.result или $.checks[0].status.</p>
            {% if errors['health_json_path'] is defined %}<p class="field-error" id="health_json_path-error">{{ errors['health_json_path'] }}</p>{% endif %}
            <div class="json-condition-row">
                <div><label for="health_json_operator">Условие</label><select id="health_json_operator" name="health_json_operator" {% if errors['health_json_operator'] is defined %}aria-invalid="true" aria-describedby="health_json_operator-error"{% endif %}>{% for operator in json_operators %}<option value="{{ operator }}" {% if site['health_json_operator'] == operator %}selected{% endif %}>{{ operator }}</option>{% endfor %}</select>
                {% if errors['health_json_operator'] is defined %}<p class="field-error" id="health_json_operator-error">{{ errors['health_json_operator'] }}</p>{% endif %}</div>
                <div><label for="health_json_expected_value">Ожидаемое значение</label><input id="health_json_expected_value" name="health_json_expected_value" value="{{ site['health_json_expected_value'] }}" placeholder="ok" maxlength="512" aria-describedby="health-expected-hint{% if errors['health_json_expected_value'] is defined %} health_json_expected_value-error{% endif %}" {% if errors['health_json_expected_value'] is defined %}aria-invalid="true"{% endif %}>
                <p class="field-hint" id="health-expected-hint">Строка без кавычек, число или true/false. Пустая строка допустима.</p>
                {% if errors['health_json_expected_value'] is defined %}<p class="field-error" id="health_json_expected_value-error">{{ errors['health_json_expected_value'] }}</p>{% endif %}</div>
            </div>
        </div>
        <div class="version-check-fields">
            <label for="version_path">Version endpoint · необязательно</label><input id="version_path" name="version_path" value="{{ site['version_path'] }}" placeholder="/version" maxlength="300">
            <p class="field-hint">JSON с version и/или commit. Пустое поле отключает проверку установленной версии.</p>
            <label for="version_json_path">JSON path версии · необязательно</label><input id="version_json_path" name="version_json_path" value="{{ site['version_json_path'] }}" placeholder="$.build.version" maxlength="512" spellcheck="false" aria-describedby="version-json-hint{% if errors['version_json_path'] is defined %} version_json_path-error{% endif %}" {% if errors['version_json_path'] is defined %}aria-invalid="true"{% endif %}>
            <p class="field-hint" id="version-json-hint">Путь к строке версии. Пустое поле использует version/deployed_version. SHA коммита определяется как раньше.</p>
            {% if errors['version_json_path'] is defined %}<p class="field-error" id="version_json_path-error">{{ errors['version_json_path'] }}</p>{% endif %}
        </div>
        <div class="field-row form-options"><label class="checkbox-label"><input type="checkbox" name="enabled" value="1" {% if site['enabled'] %}checked{% endif %}><span>Проверки включены</span></label><div class="sort-field"><label for="sort_order">Порядок</label><input type="number" id="sort_order" name="sort_order" value="{{ site['sort_order'] }}" min="0" max="9999" required></div></div>
    </div>
    <div class="form-footer"><a class="button secondary" href="/">Отмена</a><button class="button primary" type="submit"><svg><use href="/assets/icons.svg#check"></use></svg>Сохранить сайт</button></div>
</form><aside class="form-aside"><div class="aside-icon"><svg><use href="/assets/icons.svg#globe"></use></svg></div><h3>Всё начинается с адреса</h3><p>Tablo проверяет доступность сайта и сравнивает установленную версию с вашим репозиторием.</p><div class="aside-rule"></div><h4>Ответ version endpoint</h4><pre><code>{
  "version": "1.3.1",
  "commit": "abcdef1"
}</code></pre><p>Для сравнения с релизом нужна версия. Для сравнения с веткой — SHA коммита.</p>{% if editing %}<div class="aside-rule"></div><a class="danger-link" href="/sites/{{ site['id'] }}/delete"><svg><use href="/assets/icons.svg#trash"></use></svg>Удалить сайт</a>{% endif %}</aside></div>
{% endblock %}
