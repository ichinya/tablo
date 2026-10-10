{% extends "layout.volt" %}
{% block content %}
<section class="panel">
<h2>Уведомления webhook</h2>
{% if notifications['blocked'] %}<p class="field-error">Остановка дочернего процесса не подтверждена. Доставка заблокирована; требуется проверка процесса оператором.</p>{% endif %}
<p>По умолчанию выключены. Отправляются только новые подтверждённые события после сохранения настроек.</p>
{% if errors['notifications'] is defined %}<p class="field-error">{{ errors['notifications'] }}</p>{% endif %}
<form method="post" action="/settings/notifications" autocomplete="off">
<input type="hidden" name="_csrf" value="{{ csrf }}">
<input type="hidden" name="revision" value="{{ notifications['revision'] }}">
<label><input type="checkbox" name="enabled" value="1" {% if notifications['enabled'] %}checked{% endif %}> Включить webhook</label>
<label for="notification-endpoint">Адрес HTTPS (пустое поле сохраняет прежний)</label>
<input id="notification-endpoint" type="password" name="endpoint" maxlength="500" value="" autocomplete="new-password">
<p>Адрес: {% if notifications['endpoint_present'] %}сохранён{% else %}не задан{% endif %}.</p>
<label><input type="checkbox" name="remove_endpoint" value="1"> Удалить адрес</label>
<label for="notification-bearer">Bearer (необязательно; пустое поле сохраняет прежний)</label>
<input id="notification-bearer" type="password" name="bearer" maxlength="512" value="" autocomplete="new-password">
<p>Bearer: {% if notifications['bearer_present'] %}сохранён{% else %}не задан{% endif %}.</p>
<label><input type="checkbox" name="remove_bearer" value="1"> Удалить Bearer</label>
<label><input type="checkbox" name="unavailable" value="1" {% if notifications['unavailable'] %}checked{% endif %}> Недоступность</label>
<label><input type="checkbox" name="recovery" value="1" {% if notifications['recovery'] %}checked{% endif %}> Восстановление</label>
<label><input type="checkbox" name="version_lag" value="1" {% if notifications['version_lag'] %}checked{% endif %}> Отставание версии / различие ветки</label>
<label><input type="checkbox" name="include_name" value="1" {% if notifications['include_name'] %}checked{% endif %}> Включать приватное имя сайта</label>
<label><input type="checkbox" name="include_url" value="1" {% if notifications['include_url'] %}checked{% endif %}> Включать origin сайта (без пути и параметров)</label>
<p>Недоступность и версия: две принятые проверки за не менее 60 секунд. Восстановление: реальный переход инцидента. До трёх попыток; повторы через 60 и 300 секунд, срок один час. Повторы требуют следующего запуска проверок. При потерянном ответе доставка может повториться; получатель использует Idempotency-Key.</p>
<button class="button primary" type="submit">Сохранить уведомления</button>
</form>
{% for item in notification_status %}<p>Сайт {{ item['site_id'] }} · {{ item['event'] }} · {{ item['status'] }} · попытки {{ item['attempts'] }} · объединено {{ item['coalesced'] }}{% if item['last_code'] %} · {{ item['last_code'] }}{% endif %}</p>{% endfor %}
</section>
<section class="panel">
<h2>Автоматические проверки</h2>
<p>Воркер применяет интервал после завершения текущего прохода.</p>
<form method="post" action="/settings">
<input type="hidden" name="_csrf" value="{{ csrf }}">
<label for="check-interval">Интервал в минутах</label>
<input id="check-interval" name="check_interval_minutes" type="text" inputmode="numeric" value="{{ check_interval_minutes }}" required>
{% if errors['check_interval_minutes'] is defined %}<p class="field-error">{{ errors['check_interval_minutes'] }}</p>{% endif %}
<button class="button primary" type="submit">Сохранить интервал</button>
</form>
</section>
<div class="page-heading"><div><div class="eyebrow">НАСТРОЙКИ</div><h1>Git-токены<span>.</span></h1><p>Сохраните токен один раз и выбирайте его в своих проектах.</p></div><a class="button primary" href="/settings/tokens/new"><svg><use href="/assets/icons.svg#plus"></use></svg>Добавить токен</a></div>
{% if notice %}<div class="notice" role="status"><svg><use href="/assets/icons.svg#check"></use></svg>{{ notice }}</div>{% endif %}
<section class="panel token-list" aria-label="Сохранённые Git-токены">
{% if tokens %}
    {% for token in tokens %}<article class="token-row"><div class="token-identity"><span class="token-icon"><svg><use href="/assets/icons.svg#shield"></use></svg></span><div><h2><a href="/settings/tokens/{{ token['id'] }}/edit">{{ token['name'] }}</a></h2><p>{% if providers[token['provider']] is defined %}{{ providers[token['provider']] }}{% else %}{{ token['provider'] }}{% endif %} · Проектов: {{ token['site_count'] }}</p></div></div><div class="token-actions"><a class="button secondary compact" href="/settings/tokens/{{ token['id'] }}/edit">Изменить</a><a class="icon-button" href="/settings/tokens/{{ token['id'] }}/delete" aria-label="Удалить {{ token['name'] }}"><svg><use href="/assets/icons.svg#trash"></use></svg></a></div></article>{% endfor %}
{% else %}<div class="tokens-empty"><svg><use href="/assets/icons.svg#shield"></use></svg><h2>Пока нет сохранённых токенов</h2><p>Добавьте токен GitHub здесь или введите отдельный токен при добавлении проекта.</p><a class="button secondary" href="/settings/tokens/new">Добавить токен</a></div>{% endif %}
</section>
<p class="dashboard-note">Значения токенов скрыты. Доступ к репозиторию проверяется при загрузке веток проекта.</p>
{% endblock %}
