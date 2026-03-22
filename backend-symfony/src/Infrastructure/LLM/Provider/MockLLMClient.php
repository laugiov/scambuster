<?php

declare(strict_types=1);

namespace App\Infrastructure\LLM\Provider;

use App\Application\LLM\Port\LLMClientInterface;

/**
 * Mock LLM client for demo mode.
 *
 * Returns realistic but static responses without any external API call.
 * Used when DEMO_MODE=true to allow full platform exploration
 * without an OpenAI API key.
 */
final class MockLLMClient implements LLMClientInterface
{
    public function chat(array $messages, array $options = []): string
    {
        $lastMessage = end($messages);
        $content = $lastMessage['content'] ?? '';

        if (str_contains($content, 'Profile cette campagne') || str_contains($content, 'e-mails suspects')) {
            return $this->campaignProfile();
        }

        if (str_contains($content, 'règles DSL') || str_contains($content, 'MailGuard DSL')) {
            return $this->compiledRule();
        }

        if (str_contains($content, 'Évalue') || str_contains($content, 'valider') || str_contains($content, 'Texte à valider')) {
            return json_encode([
                'approved' => true,
                'reasons' => [],
                'fix_suggestion' => null,
            ], JSON_THROW_ON_ERROR);
        }

        if (str_contains($content, 'classify') || str_contains($content, 'scam_type')) {
            return json_encode([
                'scam_type' => 'PHISHING',
                'confidence' => 0.85,
            ], JSON_THROW_ON_ERROR);
        }

        return 'Thank you for your message. I am very interested in learning more about this opportunity. '
             . 'Could you please provide additional details about the process? '
             . 'I want to make sure everything is legitimate before proceeding. '
             . 'What documents or information would you need from me?';
    }

    private function campaignProfile(): string
    {
        return <<<'YAML'
campaign:
  summary: "Phishing campaign targeting bank customers with urgency tactics"
  tactics: ["lookalike domain", "urgency", "credential harvesting"]
  target_audience: "bank customers"
  cta: "verify account information"
  risk: 4

variants:
  subjects: ["Urgent: Account Verification Required", "Security Alert"]
  display_names: ["Bank Support", "Security Team"]
  url_shapes: ["bank-lookalike.com", "secure-verify-*.com"]

infra:
  domain_age_pattern: "< 7d"
  dkim_spf_pattern: "absent"
  mx_provider_pattern: "low-cost"
YAML;
    }

    private function compiledRule(): string
    {
        return <<<'DSL'
RULE scam.demo_campaign {
  WHERE subject.simhash≈"account verification" ±15%
    AND body.containsAny ["verify account","urgent action"]
    AND url.domain.age < 14d
    AND dkim.pass ∈ {false, null}
  ACTION tag="campaign:demo", score+=35
}
DSL;
    }
}
