<?php

namespace Tusk\Web\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Tusk\Web\Http\MiddlewarePipeline;

class MiddlewarePipelineTest extends TestCase
{
    public function test_fallback_handler_is_called_when_no_middlewares(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        
        $fallback = $this->createMock(RequestHandlerInterface::class);
        $fallback->expects($this->once())
                 ->method('handle')
                 ->with($request)
                 ->willReturn($response);

        $pipeline = new MiddlewarePipeline($fallback);
        
        $result = $pipeline->handle($request);
        $this->assertSame($response, $result);
    }

    public function test_middlewares_are_executed_in_order(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        
        $fallback = $this->createMock(RequestHandlerInterface::class);
        $fallback->expects($this->once())
                 ->method('handle')
                 ->willReturn($response);

        $middleware1 = $this->createMock(MiddlewareInterface::class);
        $middleware1->expects($this->once())
                    ->method('process')
                    ->willReturnCallback(function($req, $handler) {
                        return $handler->handle($req);
                    });

        $middleware2 = $this->createMock(MiddlewareInterface::class);
        $middleware2->expects($this->once())
                    ->method('process')
                    ->willReturnCallback(function($req, $handler) {
                        return $handler->handle($req);
                    });

        $pipeline = new MiddlewarePipeline($fallback);
        $pipeline->pipe($middleware1);
        $pipeline->pipe($middleware2);

        $result = $pipeline->handle($request);
        $this->assertSame($response, $result);
    }
}
