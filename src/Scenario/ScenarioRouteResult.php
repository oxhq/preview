<?php

declare(strict_types=1);

namespace Oxhq\Preview\Scenario;

use Oxhq\Preview\Route\RoutePreview;
use Symfony\Component\HttpFoundation\Response;

final class ScenarioRouteResult
{
    public function __construct(
        public readonly RoutePreview $preview,
        public readonly Response $response,
    ) {
    }

    public function successful(): bool
    {
        return $this->response->getStatusCode() >= 200 && $this->response->getStatusCode() < 300;
    }
}
