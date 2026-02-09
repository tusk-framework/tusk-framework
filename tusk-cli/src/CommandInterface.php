<?php

namespace Tusk\Cli;

interface CommandInterface
{
    public function execute(array $args): int;
}
