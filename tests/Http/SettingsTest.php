<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Tablo\Tests\Support\WebFixture;
use Testo\Assert;
use Testo\Test;

final class SettingsTest
{
    #[Test]
    public function authenticatesRejectsCsrfEscapes422AndPersistsWith303(): void
    {
        $web = new WebFixture();
        try {
            $csrf = $web->authenticate();
            Assert::same($web->request('/settings', null, false)['status'], 303);
            Assert::same($web->request('/settings', ['check_interval_minutes' => '1'], false)['status'], 303);
            $page = $web->request('/settings');
            Assert::same($page['status'], 200);
            Assert::true(str_contains($page['body'], 'name="check_interval_minutes" type="text" inputmode="numeric" value="10"'));
            foreach ([null, '', 'wrong', ['array']] as $token) {
                Assert::same($web->request('/settings', ['_csrf' => $token, 'check_interval_minutes' => '1'])['status'], 419);
            }
            foreach (['0', '-1', '1.5', '1e2', str_repeat('9', 100), ['bad'], '<script>secret</script>'] as $value) {
                $response = $web->request('/settings', ['_csrf' => $csrf, 'check_interval_minutes' => $value]);
                Assert::same($response['status'], 422);
                Assert::false(str_contains($response['body'], '<script>secret</script>'));
                Assert::same((int) $web->database()->query('SELECT check_interval_minutes FROM installation_settings')->fetchColumn(), 10);
            }
            Assert::true(str_contains($response['body'], '&lt;script&gt;secret&lt;/script&gt;'));
            $saved = $web->request('/settings', ['_csrf' => $csrf, 'check_interval_minutes' => '0002']);
            Assert::same($saved['status'], 303);
            Assert::true(str_contains($saved['headers'], 'Location: /settings'));
            Assert::true(str_contains($web->request('/settings')['body'], 'inputmode="numeric" value="2"'));
            Assert::same((int) $web->database()->query('SELECT check_interval_minutes FROM installation_settings')->fetchColumn(), 2);
        } finally { $web->close(); }
    }
}
