<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Communication;

use App\Domain\Communication\ObservedIoc;
use App\Domain\Communication\Message;
use PHPUnit\Framework\TestCase;

class ObservedIocTest extends TestCase
{
    public function testObservedIocProperties(): void
    {
        $obsId = '00000000-0000-0000-0000-000000000002';
        $message = $this->createMock(Message::class);
        $indicatorId = '11111111-1111-1111-1111-111111111111';
        $context = ['context' => 'found in body'];
        $tsObserved = new \DateTimeImmutable('-2 hours');

        $ioc = new ObservedIoc($obsId, $message, $indicatorId, $context, $tsObserved);

        $this->assertSame($obsId, $ioc->getObsId());
        $this->assertSame($message, $ioc->getMessage());
        $this->assertSame($indicatorId, $ioc->getIndicatorId());
        $this->assertSame($context, $ioc->getContext());
        $this->assertEquals($tsObserved, $ioc->getTsObserved());
    }
} 