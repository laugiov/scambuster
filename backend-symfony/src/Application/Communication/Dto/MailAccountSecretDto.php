<?php

declare(strict_types=1);

namespace App\Application\Communication\Dto;

class MailAccountSecretDto
{
    /**
     * @param array<int, string> $oauthScopes
     */
    public function __construct(
        public string $login,
        public string $secret,
        public string $protocol,
        public string $endpoint,
        public array $oauthScopes,
        public ?int $port = null,
        public ?bool $secure = null
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'login' => $this->login,
            'secret' => $this->secret,
            'protocol' => $this->protocol,
            'endpoint' => $this->endpoint,
            'oauth_scopes' => $this->oauthScopes,
            'port' => $this->port,
            'secure' => $this->secure,
        ];
    }
}
