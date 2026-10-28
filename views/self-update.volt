{% if self_update is defined and self_update['visible'] %}
<aside class="notice self-update" data-self-update role="status">
<div><strong>{{ self_update['message'] }}</strong>
{% if self_update['branch'] %}<p>Ветка {{ self_update['branch'] }} · установлено {{ self_update['installed'] }}{% if self_update['latest'] %} · доступно {{ self_update['latest'] }}{% endif %}{% if self_update['new_commits'] !== null %} · новых коммитов: {{ self_update['new_commits'] }}{% endif %}</p>{% endif %}
<p>Последняя успешная проверка: {% if self_update['successful_at'] %}{{ self_update['successful_at'] }}{% else %}нет данных{% endif %}.{% if self_update['stale'] %} Данные устарели.{% endif %}{% if not self_update['enabled'] %} Проверка отключена.{% endif %}</p>
{% if self_update['compare_url'] %}<a href="{{ self_update['compare_url'] }}" target="_blank" rel="noopener noreferrer">Сравнить изменения</a>{% endif %}
</div></aside>
{% endif %}
