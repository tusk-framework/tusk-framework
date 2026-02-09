<?php

use Tusk\Web\Inertia\InertiaResponse;

if (!function_exists('inertia')) {
    function inertia(string $component, array $props = []): InertiaResponse
    {
        return new InertiaResponse($component, $props);
    }
}
