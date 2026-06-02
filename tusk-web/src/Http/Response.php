<?php

declare(strict_types=1);

namespace Tusk\Web\Http;

use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Message\ResponseInterface;

class Response extends Psr7Response
{
    public static function html(string $content, int $status = 200): ResponseInterface
    {
        return new self($status, ['Content-Type' => 'text/html; charset=utf-8'], $content);
    }

    public static function json(array|object $data, int $status = 200): ResponseInterface
    {
        return new self($status, ['Content-Type' => 'application/json'], (string) json_encode($data));
    }

    public static function redirect(string $url, int $status = 302): ResponseInterface
    {
        return new self($status, ['Location' => $url]);
    }
}
