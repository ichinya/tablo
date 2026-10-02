<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ title }} · Tablo</title>
    <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
    <link rel="stylesheet" href="/assets/app.css">
    <script src="/assets/app.js" defer></script>
</head>
<body>
{% if authenticated %}
<div class="app-shell">
    <aside class="sidebar">
        <a class="brand" href="/" aria-label="Tablo — обзор сайтов"><span class="brand-mark"><i></i><i></i><i></i><i></i></span>tablo<span class="brand-dot">.</span></a>
        <div class="workspace"><span class="workspace-icon">T</span><div><strong>Моя инфраструктура</strong><span>Личная панель</span></div><span class="workspace-dot"></span></div>
        <div class="nav-label">РАБОЧЕЕ ПРОСТРАНСТВО</div>
        <nav aria-label="Основная навигация">
            <a class="nav-link {% if title == 'Обзор сайтов' %}active{% endif %}" href="/"><svg><use href="/assets/icons.svg#grid"></use></svg>Обзор сайтов</a>
            <a class="nav-link {% if title == 'Добавить сайт' %}active{% endif %}" href="/sites/new"><svg><use href="/assets/icons.svg#plus"></use></svg>Добавить сайт</a>
            <a class="nav-link {% if title == 'Настройки' or title == 'Добавить токен' or title == 'Настройки токена' or title == 'Удалить токен' %}active{% endif %}" href="/settings"><svg><use href="/assets/icons.svg#settings"></use></svg>Настройки</a>
        </nav>
        <div class="sidebar-bottom"><div class="private-note"><svg><use href="/assets/icons.svg#shield"></use></svg><strong>Только ваше</strong><p>Сайты и настройки хранятся<br>в вашей установке Tablo.</p></div><div class="sidebar-version"><span class="tiny-dot"></span>Локальная установка<span>v0.1</span></div></div>
    </aside>
    <div class="workspace-main">
        <header class="topbar"><div class="topbar-path">Рабочее пространство <span>/</span> <strong>{{ title }}</strong></div><div class="account"><span class="account-avatar">A</span><span>Администратор</span><form action="/logout" method="post"><input type="hidden" name="_csrf" value="{{ csrf }}"><button class="icon-button" aria-label="Выйти" title="Выйти"><svg><use href="/assets/icons.svg#logout"></use></svg></button></form></div></header>
        <main class="main-content">
{% else %}
<main class="auth-shell">
{% endif %}
{% block content %}{% endblock %}
</main>
{% if authenticated %}
        <footer class="page-footer"><span>Tablo · Всё на своих местах.</span><span>Одна установка. Ваши сайты.</span></footer>
    </div>
</div>
{% endif %}
</body>
</html>
