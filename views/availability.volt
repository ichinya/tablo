{% extends "layout.volt" %}
{% block content %}
<section class="availability-page" aria-labelledby="availability-heading">
    <a class="back-link" href="/"><svg aria-hidden="true"><use href="/assets/icons.svg#arrow-left"></use></svg>К обзору сайтов</a>
    <div class="page-heading availability-heading"><div><div class="eyebrow">ИСТОРИЯ ПРОВЕРОК</div><h1 id="availability-heading">Доступность и время ответа</h1><p class="availability-site" data-availability-site>{{ site['name'] }}</p></div></div>
    {% if not site['enabled'] %}<div class="availability-paused" role="status"><strong>Сейчас на паузе</strong><p>Сохранённые проверки показаны. Длительность прошлых пауз неизвестна.</p></div>{% endif %}
    <form class="availability-filter" method="get" action="/sites/{{ site['id'] }}/history">
        <div><label for="availability-period">Период истории</label><select id="availability-period" name="period">{% for option in period['options'] %}<option value="{{ option['value'] }}"{% if option['selected'] %} selected{% endif %}>{{ option['label'] }}</option>{% endfor %}</select></div>
        <button class="button primary" type="submit">Показать историю</button>
    </form>
    <p class="availability-window">{{ period['label'] }} · UTC: от <time datetime="{{ period['from'] }}" data-availability-from>{{ period['from'] }}</time> включительно до <time datetime="{{ period['to'] }}" data-availability-to>{{ period['to'] }}</time> не включительно.</p>
    {% if overflow %}
    <div class="availability-panel availability-overflow" data-availability-overflow><h2>Слишком много проверок</h2><p>{{ overflow_message }}</p><div class="availability-period-links" role="group" aria-label="Выбрать период истории">{% for option in period['options'] %}<a class="button secondary" href="/sites/{{ site['id'] }}/history?period={{ option['value'] }}">{{ option['label'] }}</a>{% endfor %}</div></div>
    {% else %}
    <section class="availability-panel" aria-labelledby="availability-summary-heading" data-availability-state="{{ summary['availability_state'] }}">
        <h2 id="availability-summary-heading">Доступность среди известных результатов</h2>
        <div class="availability-metric"><strong data-availability-percent>{{ summary['availability_label'] }}</strong><span>Online: <span data-availability-count="online">{{ summary['online'] }}</span> из <span data-availability-count="known">{{ summary['known'] }}</span> известных результатов</span></div>
        <p class="availability-explanation">{{ summary['explanation'] }}</p>
        <dl class="availability-counts"><div><dt>Все проверки</dt><dd data-availability-count="total">{{ summary['total'] }}</dd></div><div><dt>Offline</dt><dd data-availability-count="offline">{{ summary['offline'] }}</dd></div><div><dt>Unknown</dt><dd data-availability-count="unknown">{{ summary['unknown'] }}</dd></div><div><dt>Известен результат</dt><dd>{{ summary['known_label'] }}</dd></div></dl>
        <p class="availability-note">Частые ручные проверки могут изменить долю результатов.</p>
    </section>
    {% if summary['total'] == 0 %}
    <div class="availability-panel availability-empty"><h2>За этот период проверок нет</h2><p>Сохранённых наблюдений недостаточно для статистики. Отсутствие проверок не означает недоступность сайта.</p></div>
    {% else %}
    <section class="availability-panel" aria-labelledby="availability-response-heading">
        <h2 id="availability-response-heading">Время ответа, мс</h2>
        <p class="availability-note">Среднее по известным Online и Offline с измеренным временем в каждом из 120 интервалов. Нулевое время — реальное измерение; время Unknown не учитывается.</p>
        <p class="availability-response-counts">Измерений: <strong data-availability-count="response">{{ summary['response_count'] }}</strong>. Известных результатов без времени: <strong data-availability-count="without-time">{{ summary['known_without_time'] }}</strong>.</p>
        {% if summary['response_count'] > 0 %}
        <div class="availability-chart" data-availability-chart>
            <svg class="availability-plot" viewBox="0 0 {{ chart['width'] }} {{ chart['height'] }}" width="{{ chart['width'] }}" height="{{ chart['height'] }}" role="img" aria-labelledby="availability-chart-title availability-chart-description">
                <title id="availability-chart-title">Среднее время ответа по интервалам, мс</title>
                <desc id="availability-chart-description">Время по горизонтали — UTC. Каждый круг — независимое среднее измерений известных Online и Offline за интервал. Крестики отмечают отсутствие проверок, Unknown или известные результаты без времени. Точки не соединены; точные границы, количество, минимум и максимум доступны в таблице ниже.</desc>
                <text class="availability-axis-label" x="12" y="16">мс</text>
                {% for tick in chart['y_ticks'] %}<line class="availability-grid-line" x1="{{ chart['left'] }}" x2="{{ chart['right'] }}" y1="{{ tick['y'] }}" y2="{{ tick['y'] }}"></line><text class="availability-y-label" x="54" y="{{ tick['y'] }}">{{ tick['label'] }}</text>{% endfor %}
                <line class="availability-axis" x1="{{ chart['left'] }}" x2="{{ chart['left'] }}" y1="{{ chart['top'] }}" y2="{{ chart['bottom'] }}"></line>
                <g class="availability-x-ticks">{% for tick in chart['x_ticks'] %}<text class="availability-x-label" x="{{ tick['x'] }}" y="297">{{ tick['label'] }}</text>{% endfor %}</g>
                {% for bucket in buckets %}
                    {% if bucket['mean_y'] is not null %}<circle class="availability-mean" data-availability-mean="{{ bucket['index'] }}" cx="{{ bucket['x'] }}" cy="{{ bucket['mean_y'] }}" r="4"><title>{{ bucket['label'] }} · Среднее: {{ bucket['mean_label'] }} мс · Измерений: {{ bucket['response_count'] }}</title></circle>{% endif %}
                    {% if bucket['has_gap'] %}<text class="availability-gap" data-availability-gap="{{ bucket['index'] }}" x="{{ bucket['x'] }}" y="274">×<title>{{ bucket['label'] }} · {{ bucket['gap_label'] }}</title></text>{% endif %}
                {% endfor %}
            </svg>
        </div>
        <p class="availability-legend"><span><i class="availability-legend-point" aria-hidden="true"></i>Среднее измеренных ответов</span><span><b aria-hidden="true">×</b>Пробелы в наблюдениях или времени</span></p>
        {% else %}<p class="availability-no-timing" data-availability-no-timing>Нет измеренного времени для известных результатов. На графике нечего показывать.</p>{% endif %}
        <details class="availability-details"><summary>Таблица всех 120 интервалов</summary><p class="availability-note" id="availability-table-help">Таблицу можно прокручивать по горизонтали, в том числе с клавиатуры. Начало каждого интервала включено, конец не включён.</p>
            <div class="availability-table-region" role="region" aria-label="Интервалы истории проверок" aria-describedby="availability-table-help" tabindex="0">
                <table class="availability-table" data-availability-table><caption>Принятые проверки за {{ period['label'] }}. Границы интервалов — UTC, время ответа — мс.</caption><thead><tr><th scope="col">Интервал UTC</th><th scope="col">Все</th><th scope="col">Online</th><th scope="col">Offline</th><th scope="col">Unknown</th><th scope="col">Известные без времени</th><th scope="col">Измерения</th><th scope="col">Среднее, мс</th><th scope="col">Мин. — макс., мс</th><th scope="col">Пробелы</th></tr></thead><tbody>
                {% for bucket in buckets %}<tr data-availability-bucket="{{ bucket['index'] }}"><th scope="row"><time datetime="{{ bucket['from'] }}">{{ bucket['from'] }}</time><span class="availability-interval-end">до <time datetime="{{ bucket['to'] }}">{{ bucket['to'] }}</time></span></th><td data-bucket-count="total">{{ bucket['total'] }}</td><td data-bucket-count="online">{{ bucket['online'] }}</td><td data-bucket-count="offline">{{ bucket['offline'] }}</td><td data-bucket-count="unknown">{{ bucket['unknown'] }}</td><td data-bucket-count="without-time">{{ bucket['known_without_time'] }}</td><td data-bucket-count="response">{{ bucket['response_count'] }}</td><td data-bucket-mean>{{ bucket['mean_label'] }}</td><td data-bucket-range>{{ bucket['range_label'] }}</td><td data-bucket-gap>{{ bucket['gap_label'] }}</td></tr>{% endfor %}
                </tbody></table>
            </div>
        </details>
    </section>
    {% endif %}
    <p class="availability-note availability-method">Ревизий настроек за период: <strong data-availability-count="revisions">{{ summary['revision_count'] }}</strong>. Результаты могут относиться к прежним настройкам; название сайта — текущее. Показана только сохранённая история. Она не устанавливает частоту проверок, длительность пауз или время начала наблюдения.</p>
    {% endif %}
</section>
{% endblock %}
