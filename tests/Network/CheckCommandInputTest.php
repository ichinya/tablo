<?php
declare(strict_types=1);

namespace Tablo\Tests\Network;

use Testo\Assert;
use Testo\Test;

final class CheckCommandInputTest extends CheckCommandFixtureCase
{


    #[Test]
    public function explicitArgumentsBootstrapUtf8ReflectionAndLegacyTextAreSafe(): void
    {
        try {
            $this->start();
            foreach ([['--json', '--json'], ['--json', '--site=1'], ['--json', '--unknown'], ['--json', '--help', '--help']] as $args) {
                $document = $this->document($this->run($args, ['TABLO_DB' => $this->directory->path . '/absent/no.sqlite']), 'fatal', 1);
                Assert::same($document['run']['reason'], 'invalid-arguments'); Assert::false(is_dir($this->directory->path . '/absent'));
            }
            $document = $this->document($this->run(['--help', '--json'], ['TABLO_DB' => $this->directory->path . '/absent/no.sqlite']), 'success', 0);
            Assert::same($document['run']['reason'], 'help'); Assert::false(is_dir($this->directory->path . '/absent'));
            $document = $this->document($this->run(options: ['-d', 'opcache.enable_cli=0', '-d', 'disable_functions=curl_version']), 'fatal', 1);
            Assert::same($document['run']['reason'], 'installation-failed');
            $id = $this->row(['endpoint_token' => $this->marker, 'version_path' => '/reflect']);
            $document = $this->document($this->run(), 'partial', 1);
            Assert::same($document['sites'][0]['observation']['version']['reason'], 'invalid-response');
            $text = $this->run(['--help', '--site=999', '--unknown']); Assert::same($text['stdout'], $id . ': attention' . PHP_EOL); Assert::same($text['exit_code'], 1);
            $this->sites->delete($id); $id = $this->row(['version_path' => '/utf8']);
            $document = $this->document($this->run(), 'partial', 1); Assert::same($document['sites'][0]['observation']['version']['status'], 'failed');
            $this->sites->delete($id); $id = $this->row(['version_path' => '']);
            $text = $this->run(); $this->document($text, 'success', 0);
            $legacy = $this->run([]); Assert::same($legacy['stdout'], $id . ': ok' . PHP_EOL); Assert::same($legacy['stderr'], ''); Assert::same($legacy['exit_code'], 0);
            Assert::true(str_contains('positive leak control: ' . $this->marker, $this->marker), 'scanner positive control must detect the same marker');
        } catch (\Throwable $error) { $this->directory?->retainFailure($error); throw $error; }
        finally { $this->close(); }
    }
}
