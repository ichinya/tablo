{% extends "layout.volt" %}
{% block content %}
<div class="page-heading"><div><div class="eyebrow"><span class="tiny-dot"></span> ОБЗОР ИНФРАСТРУКТУРЫ</div><h1>Ваши сайты. Одним взглядом<span>.</span></h1><p>Доступность, версии и репозитории — всё в одном месте.</p></div><a class="button primary" href="/sites/new"><svg><use href="/assets/icons.svg#plus"></use></svg>Добавить сайт</a></div>
{% if notice %}<div class="notice" role="status"><svg><use href="/assets/icons.svg#check"></use></svg>{{ notice }}</div>{% endif %}
<section class="stats" aria-label="Сводка">
    <div class="stat-card"><div class="stat-label">Всего сайтов<svg><use href="/assets/icons.svg#globe"></use></svg></div><div class="stat-value"><strong data-dashboard-count="total">{{ stats['total'] }}</strong><span>в вашей панели</span></div></div>
    <div class="stat-card"><div class="stat-label">В сети<span class="status-dot green"></span></div><div class="stat-value"><strong data-dashboard-count="online">{{ stats['online'] }}</strong><span>последняя проверка</span></div></div>
    <div class="stat-card"><div class="stat-label">Требуют внимания<svg class="amber"><use href="/assets/icons.svg#activity"></use></svg></div><div class="stat-value"><strong data-dashboard-count="attention">{{ stats['attention'] }}</strong><span>проверки и обновления</span></div></div>
    <div class="stat-card"><div class="stat-label">На паузе<svg><use href="/assets/icons.svg#pause"></use></svg></div><div class="stat-value"><strong data-dashboard-count="paused">{{ stats['paused'] }}</strong><span>проверки отключены</span></div></div>
</section>
<section class="sites-section" data-dashboard data-dashboard-csrf="{{ csrf }}" data-dashboard-state="/dashboard/state" aria-label="Сайты">
    <div class="section-toolbar" data-dashboard-controls hidden>
        <div class="filter-tabs" role="group" aria-label="Фильтр сайтов"><button type="button" class="filter-tab selected" data-filter="all" aria-pressed="true">Все сайты<span data-dashboard-count="total">{{ stats['total'] }}</span></button><button type="button" class="filter-tab" data-filter="attention" aria-pressed="false">Требуют внимания</button><button type="button" class="filter-tab" data-filter="paused" aria-pressed="false">На паузе</button></div>
        <div class="dashboard-controls"><label class="search"><svg><use href="/assets/icons.svg#search"></use></svg><input type="search" id="site-search" placeholder="Найти сайт…" aria-label="Найти сайт"></label><div class="view-switch" role="group" aria-label="Вид сайтов"><button type="button" data-view="tile" aria-controls="dashboard-tiles" aria-pressed="true">Плитки</button><button type="button" data-view="table" aria-controls="dashboard-table" aria-pressed="false">Таблица</button></div></div>
    </div>
    <div data-dashboard-snapshot>{% include "dashboard-snapshot.volt" %}</div>
    <p class="no-results" role="status" hidden>По вашему запросу ничего не найдено.</p>
    <a class="table-add button secondary" data-table-add href="/sites/new" hidden>Добавить сайт</a>
    <div class="dashboard-note"><svg><use href="/assets/icons.svg#info"></use></svg><span>Время последней проверки указано для каждого сайта.</span></div>
    <p class="dashboard-refresh" data-dashboard-refresh role="status" aria-live="polite" aria-atomic="true"></p>
</section>
{% endblock %}
