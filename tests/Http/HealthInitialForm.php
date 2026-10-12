<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Testo\Assert;


final class HealthInitialForm
{
    public function __construct(private readonly \Tablo\Tests\Support\WebFixture $web) {}

    public function input(string $base, string $csrf): array
    {
        $form = $this->web->request('/sites/new');
        Assert::true(preg_match('/id="health_path"[^>]*required/', $form['body']) === 0, 'health field is optional');
        Assert::true(str_contains($form['body'], 'Если оставить пустым, проверяется главная страница. Только HTTP 200 означает Online.'));
        $input = ['_csrf' => $csrf, 'name' => 'Homepage fixture', 'url' => $base . '/homepage/200',
            'repository' => 'fixture/public', 'branch' => 'main', 'health_path' => '  ', 'version_path' => '',
            'health_check_mode' => 'json', 'health_json_path' => 'inactive path', 'health_json_operator' => '>',
            'health_json_expected_value' => 'not a number', 'comparison_mode' => 'release', 'enabled' => '1', 'sort_order' => '0'];
        Assert::same($this->web->request('/sites/new', $input)['status'], 303, 'server accepts empty endpoint without JavaScript');
        Assert::same(\Tablo\SqlStatement::checked($this->web->database()->query('SELECT health_path FROM sites'))->fetchColumn(), '');
        $edit = $this->web->request('/sites/1/edit');
        Assert::true(preg_match('/id="health_path"[^>]*value=""/', $edit['body']) === 1, 'empty endpoint round-trips');
        Assert::true(str_contains($edit['body'], 'value="inactive path"'), 'inactive JSON settings retained');
        return $input;
    }
}
