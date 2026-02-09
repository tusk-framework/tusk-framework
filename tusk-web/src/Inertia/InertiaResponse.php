<?php

namespace Tusk\Web\Inertia;

use Tusk\Web\Http\Request;
use Tusk\Web\Http\Response;
use Tusk\Web\Http\ResponsableInterface;

class InertiaResponse implements ResponsableInterface
{
    public function __construct(
        private string $component,
        private array $props,
        private string $rootView = 'app',
        private ?string $version = null
    ) {
    }

    public function toResponse(Request $request): Response
    {
        $props = array_merge(Inertia::getShared(), $this->props);
        $version = $this->version ?? Inertia::getVersion();

        $page = [
            'component' => $this->component,
            'props' => $props,
            'url' => $request->uri,
            'version' => $version
        ];

        if ($this->isInertiaRequest($request)) {
            return new Response(
                statusCode: 200,
                headers: [
                    'Vary' => 'Accept',
                    'X-Inertia' => 'true',
                    'Content-Type' => 'application/json'
                ],
                body: json_encode($page)
            );
        }

        return new Response(
            statusCode: 200,
            headers: ['Content-Type' => 'text/html; charset=UTF-8'],
            body: $this->renderRootView($page)
        );
    }

    private function isInertiaRequest(Request $request): bool
    {
        foreach ($request->headers as $name => $value) {
            if (strtolower($name) === 'x-inertia' && $value === 'true') {
                return true;
            }
        }
        return false;
    }

    private function renderRootView(array $page): string
    {
        $json = htmlspecialchars(json_encode($page), ENT_QUOTES, 'UTF-8');

        // This is a basic default view. 
        // In a real app, this should load a layout file (e.g. methods to set root view path).
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0" />
    <title>Tusk App</title>
    <script type="module" src="/main.js"></script>
</head>
<body>
    <div id="app" data-page='{$json}'></div>
</body>
</html>
HTML;
    }
}
