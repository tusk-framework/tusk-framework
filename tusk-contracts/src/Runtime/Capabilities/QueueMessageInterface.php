<?php

namespace Tusk\Contracts\Runtime\Capabilities;

interface QueueMessageInterface
{
    public function id(): string;

    public function queue(): string;

    public function name(): string;

    public function payload(): string;

    /**
     * @return array<string, string>
     */
    public function headers(): array;
}
