<div id="dashboard-table" class="dashboard-table-region" data-dashboard-view="table" role="region" aria-label="Таблица сайтов: прокрутите для просмотра всех столбцов" tabindex="0" hidden>
    <table class="dashboard-table">
        <caption>Сайты · результаты последней проверки</caption>
        <thead><tr><th scope="col">Сайт / URL</th><th scope="col">Репозиторий / ветка</th><th scope="col">Статус / ошибки</th><th scope="col">На сайте</th><th scope="col">Релиз / HEAD / сравнение</th><th scope="col">Issues</th><th scope="col">PR</th><th scope="col">Ответ, мс</th><th scope="col">Проверка, UTC</th><th scope="col">Действия</th></tr></thead>
        <tbody>
        {% for site in sites %}
            <tr data-site data-site-id="{{ site['id'] }}" data-search="{{ site['name'] }} {{ site['host'] }} {{ site['repository'] }}" data-paused="{% if not site['enabled'] %}1{% else %}0{% endif %}" data-attention="{% if site['attention'] %}1{% else %}0{% endif %}">
                <th scope="row"><a class="table-site-name" href="{{ site['url'] }}" target="_blank" rel="noopener noreferrer">{{ site['name'] }}</a><a class="table-secondary" href="{{ site['url'] }}" target="_blank" rel="noopener noreferrer">{{ site['url'] }}</a></th>
                <td><a href="https://github.com/{{ site['repository'] }}" target="_blank" rel="noopener noreferrer">{{ site['repository'] }}</a><span class="table-secondary">{{ site['branch'] }}</span></td>
                <td>{% include "dashboard-status.volt" %}{% if site['health_reason_label'] %}<span class="table-secondary">{{ site['health_reason_label'] }}</span>{% endif %}{% if site['last_error'] %}<details class="check-error"><summary>Есть ошибки проверки</summary><p>{{ site['last_error'] }}</p></details>{% endif %}</td>
                <td><code>{{ site['deployed_label'] }}</code>{% if site['tracks_version'] and site['comparison_mode'] == 'release' and site['deployed_commit'] %}<code class="table-secondary">{{ site['deployed_commit'] }}</code>{% endif %}</td>
                <td><code>{{ site['latest_label'] }}</code>{% if site['comparison_mode'] == 'release' and site['latest_commit'] %}<code class="table-secondary">{{ site['latest_commit'] }}</code>{% endif %}{% include "dashboard-comparison.volt" %}</td>
                <td class="table-metric"><a href="https://github.com/{{ site['repository'] }}/issues" target="_blank" rel="noopener noreferrer">{% if site['open_issues'] is defined and site['open_issues'] !== null %}{{ site['open_issues'] }}{% else %}—{% endif %}</a></td>
                <td class="table-metric"><a href="https://github.com/{{ site['repository'] }}/pulls" target="_blank" rel="noopener noreferrer">{% if site['open_prs'] is defined and site['open_prs'] !== null %}{{ site['open_prs'] }}{% else %}—{% endif %}</a></td>
                <td class="table-metric">{% if site['response_time_ms'] is defined and site['response_time_ms'] !== null %}{{ site['response_time_ms'] }}{% else %}—{% endif %}</td>
                <td><span>{{ site['checked_label'] }}</span>{% if site['stale'] %}<b class="stale-label table-secondary">Устарело</b>{% endif %}</td>
                <td>{% include "dashboard-actions.volt" %}</td>
            </tr>
        {% endfor %}
        </tbody>
    </table>
</div>
