<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\LLM\Prompt;

use App\Application\LLM\Prompt\BasePromptRules;
use PHPUnit\Framework\TestCase;

class BasePromptRulesTest extends TestCase
{
    public function testRulesContainSecurityConstraint(): void
    {
        $rules = BasePromptRules::getRules('en');

        $this->assertStringContainsString('honeypot', $rules);
        $this->assertStringContainsString('no knowledge', $rules);
    }

    public function testRulesContainFormatConstraint(): void
    {
        $rules = BasePromptRules::getRules('en');

        $this->assertStringContainsString('greeting', $rules);
        $this->assertStringContainsString('subject line', $rules);
    }

    public function testRulesContainLanguagePlaceholder(): void
    {
        $rules = BasePromptRules::getRules('fr');

        $this->assertStringContainsString('fr', $rules);
        $this->assertStringContainsString('Every single word', $rules);
    }

    public function testRulesAreUnder120Words(): void
    {
        $rules = BasePromptRules::getRules('en');
        $wordCount = str_word_count($rules);

        $this->assertLessThan(120, $wordCount, "BasePromptRules should be under 120 words, got {$wordCount}");
    }

    public function testRulesUsePositiveDescriptions(): void
    {
        $rules = BasePromptRules::getRules('en');

        // No negative instructions
        $this->assertStringNotContainsString('NEVER', $rules);
        $this->assertStringNotContainsString('DO NOT', $rules);
        $this->assertStringNotContainsString('FORBIDDEN', $rules);
        $this->assertStringNotContainsString('INTERDIT', $rules);
        $this->assertStringNotContainsString('NE JAMAIS', $rules);
    }

    public function testRulesWithDifferentLanguages(): void
    {
        $rulesEn = BasePromptRules::getRules('en');
        $rulesFr = BasePromptRules::getRules('fr');

        $this->assertStringContainsString('en', $rulesEn);
        $this->assertStringContainsString('fr', $rulesFr);
    }
}
