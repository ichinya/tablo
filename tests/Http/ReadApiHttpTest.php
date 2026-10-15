<?php
declare(strict_types=1);

namespace Tablo\Tests\Http;

use Tablo\Tests\Support\ReadApiAssertions as A;
use Tablo\Tests\Support\ReadApiFixture;
use Testo\Assert;
use Testo\Test;

final class ReadApiHttpTest
{
    #[Test]
    public function actualPublicIngressPaginatesAndReturnsExactSameDetailWithoutAnySelectedDatabaseEffects(): void
    {
        $fixture = new ReadApiFixture(['TABLO_TEST_READ_API_KEY_FREE' => '1']); $web = $fixture->web; $db = null;
        try {
            $secret = $fixture->rotate($web->authenticate()); $db = $web->database();
            foreach ([1,3,8] as $id) { A::insert($db, $id); } $before = A::snapshot($db);
            $response = $fixture->api('/api/v1/sites?limit=2&after_id=0', $secret); ReadApiFixture::headers($response); Assert::same($response['status'], 200);
            $page = A::page($response['body']);
            Assert::same(array_keys($page), ['api_version','generated_at','data','page']); Assert::same($page['api_version'], 1);
            Assert::same(array_column($page['data'], 'id'), ['1','3']); Assert::same($page['page'], ['next_after_id' => '3']);
            foreach ($page['data'] as $site) { A::exactDto($site); }
            $detail = $fixture->api('/api/v1/sites/3', $secret); Assert::same($detail['status'], 200);
            $detailBody = A::detail($detail['body']);
            Assert::same($detailBody['data'], $page['data'][1] ?? null);
            Assert::same(A::snapshot($db), $before);
            $db->exec('DELETE FROM sites WHERE id=8'); A::insert($db, 9);
            $next = $fixture->api('/api/v1/sites?limit=2&after_id=3', $secret);
            $nextBody = A::page($next['body']);
            Assert::same(array_column($nextBody['data'], 'id'), ['9']); Assert::same($nextBody['page'], ['next_after_id' => null]);
            $beforeMissing = A::snapshot($db);
            ReadApiFixture::error($fixture->api('/api/v1/sites/2', $secret), 404, 'not_found');
            Assert::same(A::snapshot($db), $beforeMissing);
            Assert::false(is_file($web->directory->path . '/github-calls.log'));
        } catch (\Throwable $error) { $web->directory->retainFailure($error); throw $error; }
        finally { unset($db); $fixture->close(); }
    }
}
