{% extends "layout.volt" %}
{% block content %}<div class="{% if authenticated %}delete-panel panel{% else %}error-page{% endif %}"><h1>{{ title }}</h1><p>{{ message }}</p><a class="button primary" href="/">Вернуться в Tablo</a></div>{% endblock %}
