<?php

declare(strict_types=1);

namespace App\Tests\Unit\UI\Http;

use App\UI\Http\HealthController;
use PHPUnit\Framework\TestCase;

class HealthControllerTest extends TestCase
{
    public function test_returns_200_with_ok_status(): void
    {
        $controller = new HealthController();
        $response = $controller->__invoke();

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('ok', $data['status']);
    }
}
