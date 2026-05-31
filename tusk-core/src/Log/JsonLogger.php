<?php

namespace Tusk\Core\Log;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Tusk\Contracts\Attributes\Service;

/**
 * A simple JSON logger that outputs to stdout/stderr.
 * Ideal for containerized environments (Docker, Kubernetes).
 */
#[Service(scope: 'singleton')]
class JsonLogger extends AbstractLogger
{
    public function __construct(
        private string $channel = 'app',
        private string $level = LogLevel::DEBUG,
        private string $outputStream = 'php://stdout',
        private string $errorStream = 'php://stderr'
    ) {}

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        if (! $this->shouldLog($level)) {
            return;
        }

        $logData = [
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'level'     => strtoupper($level),
            'channel'   => $this->channel,
            'message'   => (string) $message,
        ];

        if (! empty($context)) {
            $logData['context'] = $this->formatContext($context);
        }

        $json = json_encode($logData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

        $stream = $this->isErrorLevel($level) ? $this->errorStream : $this->outputStream;
        
        file_put_contents($stream, $json, FILE_APPEND);
    }

    private function shouldLog(string $level): bool
    {
        $levels = [
            LogLevel::DEBUG     => 100,
            LogLevel::INFO      => 200,
            LogLevel::NOTICE    => 250,
            LogLevel::WARNING   => 300,
            LogLevel::ERROR     => 400,
            LogLevel::CRITICAL  => 500,
            LogLevel::ALERT     => 550,
            LogLevel::EMERGENCY => 600,
        ];

        $currentLevelInt = $levels[$this->level] ?? 100;
        $messageLevelInt = $levels[$level] ?? 200;

        return $messageLevelInt >= $currentLevelInt;
    }

    private function isErrorLevel(string $level): bool
    {
        return in_array($level, [
            LogLevel::ERROR,
            LogLevel::CRITICAL,
            LogLevel::ALERT,
            LogLevel::EMERGENCY,
        ]);
    }

    private function formatContext(array $context): array
    {
        $formatted = [];
        foreach ($context as $key => $value) {
            if ($value instanceof \Throwable) {
                $formatted[$key] = [
                    'class'   => get_class($value),
                    'message' => $value->getMessage(),
                    'code'    => $value->getCode(),
                    'file'    => $value->getFile() . ':' . $value->getLine(),
                    'trace'   => explode("\n", $value->getTraceAsString()),
                ];
            } else {
                $formatted[$key] = $value;
            }
        }
        return $formatted;
    }
}
