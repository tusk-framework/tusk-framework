<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

class ConsoleIntegrationTest extends TestCase
{
    public function test_cli_list_command_executes_successfully(): void
    {
        // We will execute the actual bin/tusk list command to test
        // the real container compilation and CLI integration.
        
        $output = [];
        $returnCode = -1;
        
        // Ensure we build first
        exec('php bin/tusk build', $outputBuild, $returnCodeBuild);
        $this->assertEquals(0, $returnCodeBuild, "Failed to run tusk build: " . implode("\n", $outputBuild));
        
        exec('php bin/tusk list', $output, $returnCode);
        
        $outputStr = implode("\n", $output);
        
        $this->assertEquals(0, $returnCode, "Command failed with output: " . $outputStr);
        $this->assertStringContainsString('Tusk Framework CLI', $outputStr);
        $this->assertStringContainsString('make:controller', $outputStr);
        $this->assertStringContainsString('make:entity', $outputStr);
    }
}
