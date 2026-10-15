<div class="card-actions">
    <button type="submit" form="check-site-{{ site['id'] }}" class="icon-button" data-check-button title="Проверить сайт" aria-label="Проверить {{ site['name'] }}" {% if not site['enabled'] %}disabled{% endif %}><svg><use href="/assets/icons.svg#refresh"></use></svg></button>
    <a class="icon-button" href="/sites/{{ site['id'] }}/edit" title="Настройки сайта" aria-label="Настройки {{ site['name'] }}"><svg><use href="/assets/icons.svg#settings"></use></svg></a>
</div>
