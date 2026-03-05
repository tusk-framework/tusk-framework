<?php

namespace Tusk\Contracts\Web;

use Tusk\Web\Http\Request;
use Tusk\Web\Http\Response;

interface MiddlewareInterface
{
    public function process(Request $request, RequestHandlerInterface $handler): Response;
}
