<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Testo\Assert;


final class HealthFormAssertions
{
    public function __construct(private readonly \Tablo\Tests\Support\WebFixture $web) {}

    public function endpointChange(array $input): void
    {
        $dashboard = $this->web->request('/');
        Assert::true(str_contains($dashboard['body'], 'data-health-error="http"') && str_contains($dashboard['body'], 'title="HTTP 503"'), 'stored machine reason and safe explanation displayed');
        $input['health_path'] = '/up';
        Assert::same($this->web->request('/sites/1/edit', $input)['status'], 422, 'JSON settings become active when path is set');
        $input['health_check_mode'] = 'http';
        Assert::same($this->web->request('/sites/1/edit', $input)['status'], 303);
        Assert::same($this->web->database()->query('SELECT health_error_code,health_http_status,checked_at FROM sites')->fetch(),
            ['health_error_code' => null, 'health_http_status' => null, 'checked_at' => null]);

    }
}
