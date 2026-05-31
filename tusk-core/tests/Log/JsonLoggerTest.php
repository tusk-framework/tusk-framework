<?php

namespace Tusk\Core\Tests\Log;

use PHPUnit\Framework\TestCase;
use Tusk\Core\Log\JsonLogger;
use Psr\Log\LogLevel;

class JsonLoggerTest extends TestCase
{
    private string $tempFile;

    protected function setUp(): void
    {
        $this->tempFile = tempnam(sys_get_temp_dir(), 'tusk_log_test');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempFile)) {
            unlink($this->tempFile);
        }
    }

    public function test_logs_are_formatted_as_json(): void
    {
        $logger = new JsonLogger('test_channel', LogLevel::DEBUG, $this->tempFile, $this->tempFile);
        
        $logger->info('Hello world', ['user' => 'admin']);
        
        $content = file_get_contents($this->tempFile);
        $this->assertNotEmpty($content);
        
        $lines = explode("\n", trim($content));
        $this->assertCount(1, $lines);
        
        $decoded = json_decode($lines[0], true);
        
        $this->assertIsArray($decoded);
        $this->assertEquals('INFO', $decoded['level']);
        $this->assertEquals('test_channel', $decoded['channel']);
        $this->assertEquals('Hello world', $decoded['message']);
        $this->assertArrayHasKey('context', $decoded);
        $this->assertEquals('admin', $decoded['context']['user']);
    }

    public function test_respects_log_level(): void
    {
        $logger = new JsonLogger('test_channel', LogLevel::ERROR, $this->tempFile, $this->tempFile);
        
        $logger->info('This should not be logged');
        $logger->error('This should be logged');
        
        $content = file_get_contents($this->tempFile);
        $lines = explode("\n", trim($content));
        
        $this->assertCount(1, $lines); // Only one log entry
        
        $decoded = json_decode($lines[0], true);
        $this->assertEquals('ERROR', $decoded['level']);
    }
}
