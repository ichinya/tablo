{% extends "layout.volt" %}
{% block content %}
<a class="back-link" href="/settings"><svg><use href="/assets/icons.svg#arrow-left"></use></svg>Назад к настройкам</a>
<div class="page-heading form-heading"><div><div class="eyebrow">GIT-ТОКЕНЫ</div><h1>{{ title }}<span>.</span></h1><p>Укажите название, по которому будет удобно выбирать токен.</p></div></div>
<div class="form-layout"><form class="settings-form panel" action="{{ action }}" method="post">
    <input type="hidden" name="_csrf" value="{{ csrf }}">
    {% if errors %}<div class="form-errors" role="alert"><strong>Проверьте данные формы</strong>{% for field, message in errors %}<p>{{ message }}</p>{% endfor %}</div>{% endif %}
    <div class="form-section"><h2>Токен доступа</h2><p class="section-description">Один токен можно использовать в нескольких проектах.</p>
        <label for="token-name">Название</label><input id="token-name" name="name" value="{{ token['name'] }}" placeholder="Название токена" maxlength="80" required autofocus>
        <label for="token-provider">Git-провайдер</label><select id="token-provider" name="provider">{% for id, label in providers %}<option value="{{ id }}" {% if token['provider'] == id %}selected{% endif %}>{{ label }}</option>{% endfor %}</select>
        <label for="token-value">Токен</label><input id="token-value" type="password" name="token" value="" placeholder="Токен доступа" maxlength="512" autocomplete="off" spellcheck="false" {% if not editing %}required{% endif %}>
        <p class="field-hint">{% if editing %}Токен сохранён. Пустое поле сохранит его, новый токен заменит.{% else %}Значение будет скрыто после сохранения.{% endif %} Для чтения веток GitHub нужен доступ к репозиторию и Contents — Read. <a href="https://github.com/settings/personal-access-tokens/new" target="_blank" rel="noopener noreferrer">Создать токен ↗</a></p>
    </div><div class="form-footer"><a class="button secondary" href="/settings">Отмена</a><button class="button primary" type="submit">Сохранить токен</button></div>
</form><aside class="form-aside"><div class="aside-icon"><svg><use href="/assets/icons.svg#shield"></use></svg></div><h3>Токен для ваших проектов</h3><p>При добавлении проекта выберите сохранённый токен из списка или введите свой.</p><div class="aside-rule"></div><p>При замене токена результаты проверок связанных проектов сбрасываются. Запустите проверку ещё раз.</p></aside></div>
{% endblock %}
