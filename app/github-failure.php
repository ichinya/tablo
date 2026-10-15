<?php
declare(strict_types=1);

namespace Tablo;

final class GitHubFailure extends \RuntimeException
{
    public function __construct(public readonly string $reason, public readonly ?int $eligibleAt = null, public readonly ?int $httpStatus = null)
    {
        $prefix = $httpStatus === null ? '' : 'GitHub HTTP ' . $httpStatus . '. ';
        parent::__construct($prefix . match ($reason) {
            'access' => $httpStatus === 401 ? 'Токен недействителен или истёк. Введите новый токен.'
                : 'GitHub: нет доступа к репозиторию или ветке; проверьте токен и Contents: read.',
            'rate-limit' => 'GitHub: лимит API; проверка отложена до ' . gmdate('Y-m-d H:i:s', $eligibleAt ?? 0) . ' UTC.',
            'budget' => 'GitHub: бюджет запросов или времени исчерпан; повторите проверку позже.',
            'credential-changed' => 'GitHub: токен изменён; повторите проверку с текущими настройками.',
            'renamed' => 'Репозиторий или ветка переименованы. Укажите актуальный адрес и ветку.',
            'branch-unconfirmed' => 'GitHub не подтвердил выбранную ветку. Обновите список веток.',
            'branches-too-large' => 'Список веток слишком большой. Укажите нужную ветку вручную — она будет проверена при сохранении.',
            'branches-empty' => 'В репозитории пока нет веток. Создайте первый коммит.',
            'branches-timeout' => 'Загрузка веток заняла слишком много времени. Повторите запрос или укажите ветку вручную.',
            'release-unconfirmed' => 'GitHub не вернул тег релиза. Повторите проверку; если ошибка сохраняется, проверьте релиз в репозитории.',
            'incomplete-search' => 'GitHub вернул неполный результат поиска. Повторите проверку позже.',
            'invalid-data' => 'GitHub вернул некорректные или неполные данные. Повторите проверку позже.',
            default => 'GitHub: сервис недоступен или вернул некорректный ответ; повторите проверку позже.',
        });
    }
}
