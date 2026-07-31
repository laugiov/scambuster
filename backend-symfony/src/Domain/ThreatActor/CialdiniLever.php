<?php

declare(strict_types=1);

namespace App\Domain\ThreatActor;

/**
 * The influence principles a scammer uses to manipulate a target.
 *
 * Mirrors the closed vocabulary of the ConversationAnalyzer RULE #7
 * "Cialdini mirror" so a persisted per-actor psychological profile speaks the
 * same language as the live per-turn analysis. Backed by the exact labels the
 * analyzer emits so LLM output maps straight onto the enum.
 */
enum CialdiniLever: string
{
    case Authority = 'Authority';
    case Urgency = 'Urgency';
    case Scarcity = 'Scarcity';
    case Secrecy = 'Secrecy';
    case Reciprocity = 'Reciprocity';
    case Liking = 'Liking';
    case SocialProof = 'SocialProof';
    case None = 'None';

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(static fn (self $lever): string => $lever->value, self::cases());
    }

    /**
     * Lenient parse: accepts the canonical label case-insensitively, returns
     * null on anything unknown (so generator output can be validated, not trusted).
     */
    public static function tryFromLabel(string $label): ?self
    {
        $normalized = strtolower(trim($label));

        foreach (self::cases() as $lever) {
            if (strtolower($lever->value) === $normalized) {
                return $lever;
            }
        }

        return null;
    }
}
