<?php

namespace Tusk\Cloud\Controller;

use Tusk\Web\Attribute\Route;
use Tusk\Web\Http\Request;
use Tusk\Web\Http\Response;
use Tusk\Cloud\Health\HealthCheckRegistry;
use Tusk\Contracts\Attributes\Service;

#[Service]
class HealthController
{
    public function __construct(
        private HealthCheckRegistry $registry
    ) {
    }

    #[Route('/health/live', methods: ['GET'])]
    public function live(Request $request): Response
    {
        // Liveness: "I am running"
        return new Response(200, ['Content-Type' => 'application/json'], json_encode(['status' => 'UP']));
    }

    #[Route('/health/ready', methods: ['GET'])]
    public function ready(Request $request): Response
    {
        // Readiness: "I can take traffic" (Check DB, etc)
        $report = $this->registry->runChecks();
        $statusCode = $report['status'] === 'UP' ? 200 : 503;

        return new Response($statusCode, ['Content-Type' => 'application/json'], json_encode($report));
    }
}
