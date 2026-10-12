<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Testo\Assert;
use Testo\Test;

final class CheckCommandKeyAdmissionTest extends CheckCommandFixtureCase
{




    #[Test]
    public function keyRefusalsAndOneDamagedCredentialKeepTheirExistingBoundaries(): void
    {
        foreach (['missing', 'corrupt', 'unverified', 'individual'] as $failure) {
            try {
                $this->start(); $id = $this->row(['github_token' => $this->marker]);
                if (in_array($failure, ['unverified', 'individual'], strict: true)) {
                    $this->db->exec("UPDATE sites SET github_token = 'damaged-ciphertext' WHERE id = " . $id);
                }
                if ($failure === 'individual') { $this->row(['github_token' => $this->marker]); }
                $changes = [];
                if ($failure === 'missing') { unlink($this->directory->path . '/github-token.key'); }
                if ($failure === 'corrupt') { file_put_contents($this->directory->path . '/github-token.key', data: 'short'); }
                if ($failure === 'unverified') { rename($this->directory->path . '/github-token.key', $this->directory->path . '/external'); $changes['TABLO_TOKEN_KEY_FILE'] = $this->directory->path . '/external'; }
                $document = $this->document($this->run(changes: $changes), $failure === 'individual' ? 'partial' : 'fatal', 1);
                switch (true) {
case ($failure === 'individual'):
 Assert::same($document['sites'][0]['reason'], 'site-validation-failed'); Assert::same($document['sites'][1]['reason'], 'ok');
    break;
default:
 Assert::same($this->requests(), []); Assert::same($document['sites'], []);
    break;
}
                if ($failure === 'missing') { Assert::false(is_file($this->directory->path . '/github-token.key')); }
                if ($failure === 'corrupt') { Assert::same(file_get_contents($this->directory->path . '/github-token.key'), 'short'); }
                if ($failure === 'unverified') { Assert::false(is_file($this->directory->path . '/github-token.key')); }
            } catch (\Throwable $error) { $this->directory?->retainFailure($error); throw $error; }
            finally { $this->close(); }
        }
    }
}
