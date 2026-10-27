<?php

declare(strict_types=1);

namespace Maxx\Ai\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class ContractToolTest extends TestCase
{
    private string $directory;
    private array $files = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'maxx-contract-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
        $manifest = json_decode(file_get_contents(__DIR__.'/../contracts/manifest.json'), true, 64, JSON_THROW_ON_ERROR);
        foreach ($manifest['schemas'] as $name => $entry) {
            $path = $this->directory.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $entry['source']);
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0700, true);
            }
            $contents = file_get_contents(__DIR__.'/../contracts/'.$name.'.json');
            file_put_contents($path, $contents);
            $this->files[$name] = $path;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        foreach (['m1-dummy-v1', 'm2-dummy-v1'] as $version) {
            rmdir($this->directory.DIRECTORY_SEPARATOR.'contracts'.DIRECTORY_SEPARATOR.'schemas'.DIRECTORY_SEPARATOR.$version);
        }
        rmdir($this->directory.DIRECTORY_SEPARATOR.'contracts'.DIRECTORY_SEPARATOR.'schemas');
        rmdir($this->directory.DIRECTORY_SEPARATOR.'contracts');
        rmdir($this->directory);
    }

    private function check(): Process
    {
        $process = new Process([PHP_BINARY, __DIR__.'/../tools/sync-contracts.php', $this->directory, '--check']);
        $process->run();

        return $process;
    }

    public function testCheckAcceptsLfAndCrlfWithoutChangingSdkFiles(): void
    {
        $before = hash_file('sha256', __DIR__.'/../contracts/manifest.json');
        self::assertSame(0, $this->check()->getExitCode());
        foreach ($this->files as $path) {
            file_put_contents($path, str_replace("\n", "\r\n", file_get_contents($path)));
        }
        self::assertSame(0, $this->check()->getExitCode());
        self::assertSame($before, hash_file('sha256', __DIR__.'/../contracts/manifest.json'));
    }

    public function testCheckRejectsChangedUpstreamWithoutUpdatingSnapshot(): void
    {
        $before = hash_file('sha256', __DIR__.'/../contracts/AuthorizeInput.json');
        file_put_contents($this->files['AuthorizeInput'], "{}\n");
        $result = $this->check();
        self::assertNotSame(0, $result->getExitCode());
        self::assertStringContainsString('Contract parity/provenance mismatch: AuthorizeInput', $result->getErrorOutput());
        self::assertSame($before, hash_file('sha256', __DIR__.'/../contracts/AuthorizeInput.json'));
    }

    public function testCheckRejectsMissingUpstreamSchema(): void
    {
        unlink($this->files['ExecutionRequest']);
        $result = $this->check();
        self::assertNotSame(0, $result->getExitCode());
        self::assertStringContainsString('Missing expected exported contract schema', $result->getErrorOutput());
    }
}
