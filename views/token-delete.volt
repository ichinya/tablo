{% extends "layout.volt" %}
{% block content %}
<a class="back-link" href="/settings"><svg><use href="/assets/icons.svg#arrow-left"></use></svg>Назад к настройкам</a>
<div class="panel delete-panel"><div class="delete-icon"><svg><use href="/assets/icons.svg#trash"></use></svg></div><h1>Удалить токен «{{ token['name'] }}»?</h1><p>Токен можно удалить после того, как вы выберете другой токен для связанных проектов.</p>
{% if errors %}<div class="form-errors" role="alert">{% for field, message in errors %}<p>{{ message }}</p>{% endfor %}</div>{% endif %}
<form action="/settings/tokens/{{ token['id'] }}/delete" method="post"><input type="hidden" name="_csrf" value="{{ csrf }}"><a class="button secondary" href="/settings">Отмена</a><button class="button danger" type="submit">Удалить токен</button></form></div>
{% endblock %}
