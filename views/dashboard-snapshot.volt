{% include "self-update.volt" %}
{% for site in sites %}<form id="check-site-{{ site['id'] }}" action="/sites/{{ site['id'] }}/check" method="post" data-check data-paused="{% if not site['enabled'] %}1{% else %}0{% endif %}"><input type="hidden" name="_csrf" value="{{ csrf }}"></form>{% endfor %}
{% include "dashboard-tiles.volt" %}
{% include "dashboard-table.volt" %}
{% if stats['total'] == 0 %}<p class="first-site-hint">Начните с первого сайта. Укажите адрес, репозиторий и endpoints для проверки.</p>{% endif %}
