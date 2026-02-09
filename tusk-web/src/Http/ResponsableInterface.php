<?php

namespace Tusk\Web\Http;

interface ResponsableInterface
{
    public function toResponse(Request $request): Response;
}
