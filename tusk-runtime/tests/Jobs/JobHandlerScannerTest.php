<?php

namespace Tusk\Runtime\Tests\Jobs;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tusk\Runtime\Jobs\JobHandlerRegistry;
use Tusk\Runtime\Jobs\JobHandlerScanner;
use Tusk\Runtime\Jobs\UnknownJobException;

final class JobHandlerScannerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/tusk-jobs-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*.php') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public function test_scans_named_handler_and_resolves_only_registered_name(): void
    {
        $class = $this->fixture('First', 'orders.send-invoice');
        $registry = (new JobHandlerScanner)->scan([$this->directory]);

        self::assertSame($class, $registry->handlerClass('orders.send-invoice'));
        $this->expectException(UnknownJobException::class);
        $registry->handlerClass('Private\\Task');
    }

    public function test_unknown_job_error_does_not_contain_payload(): void
    {
        $registry = new JobHandlerRegistry([]);
        try {
            $registry->handlerClass('secret-payload');
            self::fail('Expected unknown job');
        } catch (UnknownJobException $exception) {
            self::assertStringNotContainsString('secret-payload', $exception->getMessage());
        }
    }

    public function test_trims_stable_name(): void
    {
        $class = $this->fixture('Trimmed', '  orders.send-invoice  ');
        self::assertSame($class, (new JobHandlerScanner)->scan([$this->directory])->handlerClass('orders.send-invoice'));
    }

    public function test_rejects_invalid_names(): void
    {
        foreach (['   ', 'Bad Name', 'PHP\\Class'] as $name) {
            $class = 'Invalid'.bin2hex(random_bytes(4));
            $this->fixture($class, $name);
            try {
                (new JobHandlerScanner)->scan([$this->directory]);
                self::fail('Expected invalid name rejection');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('job name', strtolower($exception->getMessage()));
            }
            unlink($this->directory.'/'.$class.'.php');
        }
    }

    public function test_rejects_duplicate_names(): void
    {
        $this->fixture('First', 'duplicate.name');
        $this->fixture('Second', 'duplicate.name');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Duplicate');
        (new JobHandlerScanner)->scan([$this->directory]);
    }

    public function test_rejects_annotated_class_without_handler_contract(): void
    {
        $this->fixture('NonHandler', 'bad.handler', false);
        $this->expectException(InvalidArgumentException::class);
        (new JobHandlerScanner)->scan([$this->directory]);
    }

    private function fixture(string $class, string $name, bool $handler = true): string
    {
        $namespace = 'TuskJobFixture'.bin2hex(random_bytes(6));
        $interface = $handler ? ' implements \\Tusk\\Contracts\\Runtime\\Jobs\\JobHandlerInterface' : '';
        $method = $handler ? 'public function handle(\\Tusk\\Contracts\\Runtime\\Jobs\\JobContext $job): void {}' : '';
        $source = '<?php namespace '.$namespace.'; #[\\Tusk\\Contracts\\Attributes\\AsJob('.var_export($name, true).')] final class '.$class.$interface.' { '.$method.' }';
        file_put_contents($this->directory.'/'.$class.'.php', $source);

        return $namespace.'\\'.$class;
    }
}
