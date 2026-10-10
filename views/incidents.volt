{% extends "layout.volt" %}
{% block content %}
<div class="page-heading"><div><div class="eyebrow">ИСТОРИЯ НАБЛЮДЕНИЙ</div><h1>Инциденты</h1><p>Первое наблюдение Offline и фактическое наблюдение восстановления.</p></div></div>
<form class="incident-filter" method="get" action="/incidents">
    <label for="incident-site">ID сайта</label><input id="incident-site" name="site_id" value="{{ site_filter }}" inputmode="numeric" placeholder="Например, 1" required>
    <button class="button" type="submit">Показать</button><a href="/incidents">Все сайты</a>
</form>
<p class="incident-note">Учёт начинается с новых принятых проверок после установки функции. Интервал между наблюдениями не доказывает непрерывную недоступность.</p>
<div class="incident-list">
{% for incident in incidents %}
    <article class="incident-card" data-incident-id="{{ incident['id'] }}">
        <div><a href="/sites/{{ incident['site_id'] }}/edit"><strong>{{ incident['site_name'] }}</strong></a><span class="incident-id">#{{ incident['id'] }}</span></div>
        <p class="incident-status">{{ incident['status'] }}</p>
        {% for qualifier in incident['qualifiers'] %}<p class="incident-qualifier">{{ qualifier }}</p>{% endfor %}
        <dl>
            <div><dt>Первое наблюдение Offline · UTC</dt><dd>{{ incident['opened_at'] }}</dd></div>
            <div><dt>Восстановление · UTC</dt><dd>{% if incident['recovered_at'] %}{{ incident['recovered_at'] }}{% else %}Не наблюдалось{% endif %}</dd></div>
            <div><dt>Интервал наблюдений</dt><dd>{% if incident['interval_seconds'] is not null %}{{ incident['interval_seconds'] }} с{% else %}Неизвестен: {{ incident['interval_reason'] }}{% endif %}</dd></div>
            <div><dt>Причина при открытии</dt><dd>{{ incident['health_reason'] }}{% if incident['health_http_status'] %} · HTTP {{ incident['health_http_status'] }}{% endif %}</dd></div>
        </dl>
    </article>
{% else %}
    <div class="incident-card"><h2>Наблюдаемых инцидентов пока нет</h2><p>Старые состояния и история не создают инциденты. Online и неопределённая первая проверка также не открывают инцидент.</p></div>
{% endfor %}
</div>
{% if next_page %}<p><a class="button" href="{{ next_page }}">Следующая страница</a></p>{% endif %}
<p class="incident-note">Порядок — по идентификатору открытия. Между страницами инциденты могут восстановиться или исчезнуть при удалении сайта.</p>
{% endblock %}
