<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Scambaiting\Event;

use App\Domain\Scambaiting\Event\ConversationEndedEvent;
use PHPUnit\Framework\TestCase;

final class ConversationEndedEventTest extends TestCase
{
    public function testEventIsCreatedWithAllProperties(): void
    {
        $event = new ConversationEndedEvent(
            conversationId: '123',
            scamTypeCode: 'PHISHING',
            personaCode: 'elderly_person',
            durationSec: 7200,
            turnsCount: 15,
            iocsTotal: 10,
            iocsSensibles: 3,
            isCompleted: true
        );

        $this->assertEquals('123', $event->getConversationId());
        $this->assertEquals('PHISHING', $event->getScamTypeCode());
        $this->assertEquals('elderly_person', $event->getPersonaCode());
        $this->assertEquals(7200, $event->getDurationSec());
        $this->assertEquals(15, $event->getTurnsCount());
        $this->assertEquals(10, $event->getIocsTotal());
        $this->assertEquals(3, $event->getIocsSensibles());
        $this->assertTrue($event->isCompleted());
    }

    public function testEventCanBeCreatedWithNullPersona(): void
    {
        $event = new ConversationEndedEvent(
            conversationId: '456',
            scamTypeCode: 'ROMANCE',
            personaCode: null,
            durationSec: 3600,
            turnsCount: 8,
            iocsTotal: 5,
            iocsSensibles: 1,
            isCompleted: false
        );

        $this->assertNull($event->getPersonaCode());
        $this->assertFalse($event->hasPersona());
    }

    public function testHasPersonaReturnsTrueWhenPersonaExists(): void
    {
        $event = new ConversationEndedEvent(
            conversationId: '123',
            scamTypeCode: 'PHISHING',
            personaCode: 'generic_user',
            durationSec: 1800,
            turnsCount: 10,
            iocsTotal: 8,
            iocsSensibles: 2,
            isCompleted: true
        );

        $this->assertTrue($event->hasPersona());
    }

    public function testHasPersonaReturnsFalseWhenPersonaIsNull(): void
    {
        $event = new ConversationEndedEvent(
            conversationId: '123',
            scamTypeCode: 'PHISHING',
            personaCode: null,
            durationSec: 1800,
            turnsCount: 10,
            iocsTotal: 8,
            iocsSensibles: 2,
            isCompleted: true
        );

        $this->assertFalse($event->hasPersona());
    }

    public function testToStringContainsAllRelevantInfo(): void
    {
        $event = new ConversationEndedEvent(
            conversationId: '789',
            scamTypeCode: 'INVESTMENT',
            personaCode: 'elderly_person',
            durationSec: 14400,
            turnsCount: 20,
            iocsTotal: 15,
            iocsSensibles: 5,
            isCompleted: true
        );

        $string = (string) $event;
        $this->assertStringContainsString('ConversationEndedEvent', $string);
        $this->assertStringContainsString('conv=', $string);
        $this->assertStringContainsString('scamType=INVESTMENT', $string);
        $this->assertStringContainsString('persona=elderly_person', $string);
        $this->assertStringContainsString('duration=14400s', $string);
        $this->assertStringContainsString('turns=20', $string);
        $this->assertStringContainsString('iocs=5/15', $string);
        $this->assertStringContainsString('completed=yes', $string);
    }

    public function testToStringWithNullPersonaShowsNull(): void
    {
        $event = new ConversationEndedEvent(
            conversationId: '999',
            scamTypeCode: 'PHISHING',
            personaCode: null,
            durationSec: 1200,
            turnsCount: 5,
            iocsTotal: 3,
            iocsSensibles: 1,
            isCompleted: false
        );

        $string = (string) $event;
        $this->assertStringContainsString('persona=null', $string);
        $this->assertStringContainsString('completed=no', $string);
    }

    public function testEventIsImmutable(): void
    {
        $event = new ConversationEndedEvent(
            conversationId: '123',
            scamTypeCode: 'PHISHING',
            personaCode: 'ELDERLY',
            durationSec: 3600,
            turnsCount: 10,
            iocsTotal: 8,
            iocsSensibles: 2,
            isCompleted: true
        );

        // All properties should remain constant
        $this->assertEquals('123', $event->getConversationId());
        $this->assertEquals('PHISHING', $event->getScamTypeCode());
        $this->assertEquals('ELDERLY', $event->getPersonaCode());
    }
}
