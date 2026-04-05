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

        if (str_contains($content, 'Évalue') || str_contains($content, 'valider') || str_contains($content, 'Texte à valider')
            || str_contains($content, 'Text to validate') || str_contains($content, 'Score each dimension')) {
            return json_encode([
                'naturalness' => 4,
                'naturalness_reasoning' => 'The message reads naturally.',
                'persona_fit' => 4,
                'persona_fit_reasoning' => 'Tone matches the assigned persona.',
                'ti_value' => 3,
                'ti_value_reasoning' => 'The message advances the conversation.',
                'security_pass' => true,
                'security_reasoning' => 'No forbidden words or PII detected.',
                'feedback' => 'Good quality reply.',
                'fix_suggestion' => null,
            ], JSON_THROW_ON_ERROR);
        }

        if (str_contains($content, 'ioc_roles') || str_contains($content, 'stimulus_type') || str_contains($content, 'ioc_semantic_role')) {
            return $this->contextualEnrichment();
        }

        if (str_contains($content, 'classify') || str_contains($content, 'scam_type')) {
            return json_encode([
                'scam_type' => 'PHISHING',
                'confidence' => 0.85,
            ], JSON_THROW_ON_ERROR);
        }

        return 'Merci pour votre message. Je suis très intéressé par cette proposition. '
             . 'Pourriez-vous me fournir des informations complémentaires sur la procédure ? '
             . 'Je souhaite m\'assurer que tout est en ordre avant de poursuivre. '
             . 'Quels documents ou informations auriez-vous besoin de ma part ?';
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

    private function contextualEnrichment(): string
    {
        return json_encode([
            'stimulus_type' => 'DIRECT_REQUEST',
            'scammer_urgency_score' => 0.75,
            'language_switch_detected' => false,
            'hesitation_detected' => false,
            'context_excerpt' => 'Scammer provided IOC details after direct request from honeypot',
            'enrichment_confidence' => 0.85,
            'ioc_roles' => [
                ['type' => 'url', 'role' => 'PAYMENT_REDIRECT_URL'],
                ['type' => 'iban', 'role' => 'PAYMENT_DESTINATION'],
                ['type' => 'phone', 'role' => 'CONTACT_CHANNEL'],
                ['type' => 'email', 'role' => 'CONTACT_CHANNEL'],
                ['type' => 'domain', 'role' => 'INFRASTRUCTURE_DOMAIN'],
            ],
        ], JSON_THROW_ON_ERROR);
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
