<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Prompt;

use App\Application\Prompt\Exception\InvalidPromptOverrideException;
use App\Application\Prompt\Exception\UnknownPromptKeyException;
use App\Application\Prompt\PromptBodyValidator;
use PHPUnit\Framework\TestCase;

final class PromptBodyValidatorTest extends TestCase
{
    private function validator(): PromptBodyValidator
    {
        return new PromptBodyValidator();
    }

    public function testAcceptsAValidBody(): void
    {
        // reward_judge has no required placeholders.
        $this->validator()->validate('reward_judge', 'a perfectly fine rubric');
        $this->expectNotToPerformAssertions();
    }

    public function testRejectsUnknownKey(): void
    {
        $this->expectException(UnknownPromptKeyException::class);
        $this->validator()->validate('does_not_exist', 'x');
    }

    public function testRejectsEmptyBody(): void
    {
        $this->expectException(InvalidPromptOverrideException::class);
        $this->validator()->validate('reward_judge', '   ');
    }

    public function testRejectsOverlyLongBody(): void
    {
        $this->expectException(InvalidPromptOverrideException::class);
        $this->validator()->validate('reward_judge', str_repeat('a', PromptBodyValidator::MAX_BODY_LENGTH + 1));
    }

    public function testAcceptsBodyAtTheLengthLimit(): void
    {
        $this->validator()->validate('reward_judge', str_repeat('a', PromptBodyValidator::MAX_BODY_LENGTH));
        $this->expectNotToPerformAssertions();
    }

    public function testRejectsBodyMissingRequiredPlaceholder(): void
    {
        $this->expectException(InvalidPromptOverrideException::class);
        $this->expectExceptionMessageMatches('/\{\{SCAM_TYPE\}\}/');
        $this->validator()->validate('contextual_enrichment', 'no tokens here');
    }
}
