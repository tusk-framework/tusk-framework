<?php

namespace Tusk\Contracts\Web;

use Tusk\Web\Http\Request;
use Tusk\Web\Http\Response;

interface RequestHandlerInterface
{
    public function handle(Request $request): Response;
}
