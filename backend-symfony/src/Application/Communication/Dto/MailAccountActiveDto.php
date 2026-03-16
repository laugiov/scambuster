<?php

declare(strict_types=1);

namespace App\Application\Communication\Dto;

final class MailAccountActiveDto
{
    /**
     * @param array<int, string> $oauth_scopes
     */
    public function __construct(
        public string $account_id,
        public string $protocol,
        public string $endpoint,
        public string $login_hash,
        public array $oauth_scopes,
        public ?int $port = null,
        public ?bool $secure = null
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'account_id' => $this->account_id,
            'protocol' => $this->protocol,
            'endpoint' => $this->endpoint,
            'login_hash' => $this->login_hash,
            'oauth_scopes' => $this->oauth_scopes,
            'port' => $this->port,
            'secure' => $this->secure,
        ];
    }
}
