<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventListener;

use App\Infrastructure\EventListener\Auth\JWTNotFoundListener;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTNotFoundEvent;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

class JWTNotFoundListenerTest extends TestCase
{
    public function testSets401Response(): void
    {
        $listener = new JWTNotFoundListener();
        $event = new JWTNotFoundEvent(new AuthenticationException('No JWT'));

        $listener->onJWTNotFound($event);

        $response = $event->getResponse();
        $this->assertNotNull($response);
        $this->assertSame(401, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('Full authentication is required', $data['message']);
    }
}
