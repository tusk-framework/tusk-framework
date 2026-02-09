<?php

namespace Tusk\Web\Http;

class Response
{
    public function __construct(
        public int $statusCode = 200,
        public array $headers = [],
        public string $body = ''
    ) {
    }

    public function send(): void
    {
        http_response_code($this->statusCode);

        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }

        echo $this->body;
    }
}
