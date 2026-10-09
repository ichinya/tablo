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
            default => 'GitHub: сервис недоступен или вернул некорректный ответ; повторите проверку позже.',
        });
    }
}
