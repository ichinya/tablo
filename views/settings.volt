{% extends "layout.volt" %}
{% block content %}
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
