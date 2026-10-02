{% extends "layout.volt" %}
{% block content %}
<a class="back-link" href="/sites/{{ site['id'] }}/edit"><svg><use href="/assets/icons.svg#arrow-left"></use></svg>К настройкам</a><div class="delete-panel panel"><span class="delete-icon"><svg><use href="/assets/icons.svg#trash"></use></svg></span><h1>Удалить {{ site['name'] }}?</h1><p>Сайт исчезнет из панели вместе с настройками и результатами проверок.</p><p class="field-hint">Сам сайт и GitHub репозиторий останутся на месте.</p><form action="/sites/{{ site['id'] }}/delete" method="post"><input type="hidden" name="_csrf" value="{{ csrf }}"><a class="button secondary" href="/sites/{{ site['id'] }}/edit">Отмена</a><button class="button danger">Удалить сайт</button></form></div>
{% endblock %}
