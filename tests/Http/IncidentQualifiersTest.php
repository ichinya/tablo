<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Tablo\SiteRepository;
use Tablo\Tests\Support\IncidentFixtures as F;
use Tablo\Tests\Support\WebFixture;
use Testo\Assert;
use Testo\Test;

final class IncidentQualifiersTest
{
    #[Test]
    public function actualVoltShowsPausedUnknownInterruptedClockAndPrecisionQualifiers(): void
    {
        $web = new WebFixture();
        try {
            $web->authenticate();
            $db = $web->database();
            $sites = new SiteRepository($db);
            $paused = $sites->save(F::site());
            F::accept($sites, $paused, 0, '2026-01-01T00:00:00Z');
            F::accept($sites, $paused, null, '2026-01-01T00:00:01Z');
            $sites->save(array_replace(F::site(), ['enabled' => 0]), $paused);
            $interrupted = $sites->save(F::site());
            F::accept($sites, $interrupted, 0, '2026-01-01T00:00:10Z');
            F::accept($sites, $interrupted, 1, '2026-01-01T00:00:09Z');
            $sites->save(F::site(), $interrupted);
            F::accept($sites, $interrupted, 1, '2026-01-01T00:00:11Z');
            $equal = $sites->save(F::site());
            F::accept($sites, $equal, 0, '2026-01-01T00:00:00Z');
            F::accept($sites, $equal, 1, '2026-01-01T00:00:00Z');
            $positive = $sites->save(F::site());
            F::accept($sites, $positive, 0, '2026-01-01T00:00:00Z');
            F::accept($sites, $positive, 1, '2026-01-01T00:10:00Z');
            $response = $web->request('/incidents');
            Assert::same($response['status'], 200);
            foreach (['На паузе', 'Были неопределённые наблюдения', 'Конфигурация изменена; ожидается новая проверка',
                'Прерван: конфигурация изменена', 'Нарушен порядок времени наблюдений', 'Точность времени — одна секунда',
                '600 с', 'Интервал между наблюдениями не доказывает непрерывную недоступность'] as $label) {
                Assert::true(str_contains($response['body'], $label), $label);
            }
        } finally { unset($sites, $db); $web->close(); }
    }

}
