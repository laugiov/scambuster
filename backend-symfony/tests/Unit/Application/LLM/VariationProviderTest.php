<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\LLM;

use App\Application\LLM\VariationProvider;
use PHPUnit\Framework\TestCase;

class VariationProviderTest extends TestCase
{
    private VariationProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new VariationProvider();
    }

    public function testReturnsEmptyStringWhenLessThanTwoMessages(): void
    {
        $messages = [
            ['direction' => 'out', 'body_text' => 'Hello'],
        ];

        $this->assertSame('', $this->provider->generateInstructions($messages));
    }

    public function testReturnsEmptyStringWhenNoMessages(): void
    {
        $this->assertSame('', $this->provider->generateInstructions([]));
    }

    public function testReturnsEmptyStringWhenOnlyInboundMessages(): void
    {
        $messages = [
            ['direction' => 'in', 'body_text' => 'Hello scammer'],
            ['direction' => 'in', 'body_text' => 'Another scammer message'],
        ];

        $this->assertSame('', $this->provider->generateInstructions($messages));
    }

    public function testReturnsInstructionsWhenTwoOrMoreVictimMessages(): void
    {
        $messages = [
            ['direction' => 'out', 'body_text' => 'First reply'],
            ['direction' => 'in', 'body_text' => 'Scammer response'],
            ['direction' => 'out', 'body_text' => 'Second reply'],
        ];

        $result = $this->provider->generateInstructions($messages);

        $this->assertNotEmpty($result);
        $this->assertStringContainsString('ANTI-R', $result);
        $this->assertStringContainsString('First reply', $result);
        $this->assertStringContainsString('Second reply', $result);
    }

    public function testShowsOnlyLastThreeVictimMessages(): void
    {
        $messages = [
            ['direction' => 'out', 'body_text' => 'Very old reply'],
            ['direction' => 'out', 'body_text' => 'Old reply'],
            ['direction' => 'out', 'body_text' => 'Recent reply 1'],
            ['direction' => 'out', 'body_text' => 'Recent reply 2'],
            ['direction' => 'out', 'body_text' => 'Recent reply 3'],
        ];

        $result = $this->provider->generateInstructions($messages);

        // Should contain the last 3
        $this->assertStringContainsString('Recent reply 1', $result);
        $this->assertStringContainsString('Recent reply 2', $result);
        $this->assertStringContainsString('Recent reply 3', $result);
    }

    public function testContainsCriticalRules(): void
    {
        $messages = [
            ['direction' => 'out', 'body_text' => 'Reply one'],
            ['direction' => 'out', 'body_text' => 'Reply two'],
        ];

        $result = $this->provider->generateInstructions($messages);

        $this->assertStringContainsString('VARIE', $result);
        $this->assertStringContainsString('Message #', $result);
    }

    public function testFiltersOutInboundMessagesFromVictimList(): void
    {
        $messages = [
            ['direction' => 'in', 'body_text' => 'Scammer msg 1'],
            ['direction' => 'out', 'body_text' => 'Victim msg 1'],
            ['direction' => 'in', 'body_text' => 'Scammer msg 2'],
            ['direction' => 'out', 'body_text' => 'Victim msg 2'],
        ];

        $result = $this->provider->generateInstructions($messages);

        $this->assertStringContainsString('Victim msg 1', $result);
        $this->assertStringContainsString('Victim msg 2', $result);
        $this->assertStringNotContainsString('Scammer msg 1', $result);
        $this->assertStringNotContainsString('Scammer msg 2', $result);
    }
}
