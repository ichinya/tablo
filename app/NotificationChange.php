<?php
declare(strict_types=1);

namespace Tablo;

/**
 * @phpstan-import-type Form from NotificationFormInput
 * @phpstan-import-type Record from SqlNotificationSettingsActivationIdBearerCipherBlockedRow as StoredSettings
 */
final class NotificationChange
{
    public function __construct(#[\SensitiveParameter] public readonly ?string $endpointCipher, #[\SensitiveParameter] public readonly ?string $bearerCipher, public readonly bool $reconfigure, public readonly bool $metadata) {}
    /**
     * @param StoredSettings $old
     * @param Form $form
     */
    public static function prepare(#[\SensitiveParameter] array $old, #[\SensitiveParameter] array $form, #[\SensitiveParameter] TokenVault $vault): self
    {
        $flags = $form['flags']; $endpoint = $form['endpoint']; $bearer = $form['bearer'];
                $endpointCipher = NotificationCipher::prepare($vault, $old['endpoint_cipher'], $endpoint, $flags['remove_endpoint']);
                $bearerCipher = NotificationCipher::prepare($vault, $old['bearer_cipher'], $bearer, $flags['remove_bearer']);
                if ($flags['enabled'] && $endpointCipher === null) { NotificationFormInput::invalid(); }
                $reconfigure = $endpointCipher !== $old['endpoint_cipher'] || $bearerCipher !== $old['bearer_cipher'];
                $reconfigure = $reconfigure || $flags['enabled'] !== $old['enabled']
                    || $flags['unavailable'] !== $old['unavailable'] || $flags['recovery'] !== $old['recovery']
                    || $flags['version_lag'] !== $old['version_lag'];
                $metadata = $flags['include_name'] !== $old['include_name'] || $flags['include_url'] !== $old['include_url'];
        return new self($endpointCipher, $bearerCipher, $reconfigure, $metadata);
    }

}
