<?php

declare(strict_types=1);

namespace App\Tests\Fake;

use App\Application\LLM\Port\LLMClientInterface;

final class FakeLLMClient implements LLMClientInterface
{
    public function chat(array $messages, array $options = []): string
    {
        $lastMessage = end($messages);
        $content = $lastMessage['content'] ?? '';

        // Check if this is a campaign profiling prompt
        if (str_contains($content, 'Profile cette campagne') || str_contains($content, 'e-mails suspects')) {
            return <<<'YAML'
campaign:
  summary: "Test phishing campaign targeting bank customers"
  tactics: ["lookalike domain", "urgency tactics", "credential harvesting"]
  target_audience: "bank customers"
  cta: "verify account information"
  risk: 4

variants:
  subjects: ["Urgent: Account Verification Required", "Security Alert - Action Needed"]
  display_names: ["Bank Support", "Security Team"]
  url_shapes: ["bank-lookalike.com", "secure-verify-*.com"]

infra:
  domain_age_pattern: "< 7d"
  dkim_spf_pattern: "absent"
  mx_provider_pattern: "low-cost"
YAML;
        }

        // Check if this is a rule compilation prompt
        if (str_contains($content, 'règles DSL') || str_contains($content, 'MailGuard DSL')) {
            return <<<'DSL'
RULE scam.test_campaign {\n  WHERE subject.simhash≈"account verification" ±15%\n    AND body.containsAny ["verify account","urgent action"]\n    AND url.domain.age < 14d\n    AND dkim.pass ∈ {false, null}\n  ACTION tag="campaign:test", score+=35\n}
DSL;
        }

        // Check if this is a validator prompt (contains validation keywords)
        if (str_contains($content, 'Évalue') || str_contains($content, 'valider') || str_contains($content, 'Texte à valider')) {
            return json_encode([
                'approved' => true,
                'reasons' => [],
                'fix_suggestion' => null,
            ]);
        }

        // Otherwise return a valid reply text for generator prompts
        $validReply = str_repeat(
            'Merci pour votre message. Je suis intéressé par cette proposition. ',
            10
        );

        return trim($validReply);
    }
}
