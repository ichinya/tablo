<?php
declare(strict_types=1);

namespace Tablo;

use RuntimeException;

final class HttpFailure extends RuntimeException
{
    public const MESSAGES = [
        'timeout' => 'Истёк таймаут проверки.',
        'refused' => 'Сервер отказал в соединении.',
        'dns' => 'Не удалось разрешить DNS-имя.',
        'ssrf' => 'Проверка локального или зарезервированного адреса запрещена.',
        'tls' => 'Не удалось установить защищённое TLS-соединение.',
        'size' => 'Ответ превышает 1 МБ.',
        'invalid-response' => 'Получен некорректный или неполный ответ проверки.',
        'invalid-url' => 'Недопустимый адрес проверки.',
        'network' => 'Не удалось передать запрос или получить ответ.',
        'check-error' => 'Не удалось выполнить проверку.',
        'json-condition' => 'JSON-значение не прошло выбранное условие.',
    ];

    public readonly string $reason;

    public function __construct(string $reason)
    {
        $this->reason = array_key_exists($reason, self::MESSAGES) ? $reason : 'check-error';
        parent::__construct(self::MESSAGES[$this->reason]);
    }

    public static function fromCurl(int $error, int $osError = 0): self
    {
        $reason = match (true) {
            $error === CURLE_OPERATION_TIMEOUTED => 'timeout',
            in_array($error, [CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_RESOLVE_PROXY], true) => 'dns',
            $error === CURLE_COULDNT_CONNECT && in_array($osError, [61, 111, 10061], true) => 'refused',
            in_array($error, [CURLE_SSL_CONNECT_ERROR, CURLE_SSL_CERTPROBLEM, CURLE_SSL_CIPHER,
                CURLE_SSL_CACERT, CURLE_SSL_CACERT_BADFILE], true) => 'tls',
            $error === CURLE_FILESIZE_EXCEEDED => 'size',
            in_array($error, [CURLE_GOT_NOTHING, CURLE_PARTIAL_FILE, CURLE_BAD_CONTENT_ENCODING, CURLE_FTP_WEIRD_SERVER_REPLY, CURLE_UNSUPPORTED_PROTOCOL], true) => 'invalid-response',
            $error === CURLE_URL_MALFORMAT => 'invalid-url',
            default => 'network',
        };
        return new self($reason);
    }
}
