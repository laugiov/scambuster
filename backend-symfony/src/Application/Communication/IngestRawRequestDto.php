<?php

declare(strict_types=1);

namespace App\Application\Communication;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

class IngestRawRequestDto
{
    #[Assert\NotBlank]
    public string $account_id = '';

    public ?string $raw_source = null;

    #[Assert\NotBlank]
    public string $ts_received = '';

    #[Assert\NotBlank]
    public string $channel = '';

    /**
     * @var array<string, mixed>|null
     */
    #[Assert\NotBlank]
    public ?array $rspamd = null;

    #[OA\Property(type: 'number', nullable: true)]
    #[Assert\NotBlank]
    public ?float $score_risk = null;

    #[OA\Property(type: 'string', nullable: true)]
    public ?string $raw_headers = null;

    #[OA\Property(type: 'string', nullable: true)]
    public ?string $raw_headers_b64 = null;

    /** @var array<string, mixed>|null */
    #[OA\Property(type: 'object', nullable: true)]
    public ?array $parsed = null;

    #[OA\Property(type: 'string', nullable: true)]
    public ?string $origin_ip = null;

    #[OA\Property(type: 'string', nullable: true)]
    public ?string $raw_source_rfc822_b64 = null;

    #[OA\Property(type: 'string', nullable: true)]
    public ?string $message_id = null;

    #[OA\Property(type: 'string', nullable: true)]
    public ?string $in_reply_to = null;

    /**
     * @var array<string>|string|null
     */
    #[OA\Property(type: 'array', items: new OA\Items(type: 'string'), nullable: true)]
    public $references = null;

    /**
     * @var array<array{
     *     filename: string,
     *     mime_type: string,
     *     size_bytes: int,
     *     sha256: string,
     *     strelka?: array<string, mixed>,
     *     sandbox?: array<string, mixed>
     * }>|null
     */
    #[OA\Property(type: 'array', nullable: true)]
    public ?array $attachments = null;

    /**
     * URL analysis data from URLScan and VirusTotal
     *
     * @var array<string, mixed>|null
     */
    #[OA\Property(type: 'object', nullable: true)]
    public ?array $url_analysis = null;

    /**
     * Provider-specific message ID (e.g., Gmail Message ID)
     * Used for threading replies via Gmail API
     */
    #[OA\Property(type: 'string', nullable: true)]
    public ?string $provider_msg_id = null;

    #[\Symfony\Component\Validator\Constraints\Callback]
    public function validateRawSource(\Symfony\Component\Validator\Context\ExecutionContextInterface $context): void
    {
        if (empty($this->raw_source) && empty($this->raw_source_rfc822_b64)) {
            $context->buildViolation('Either raw_source or raw_source_rfc822_b64 must be provided.')
                ->atPath('raw_source')
                ->addViolation();
        }
    }
}
