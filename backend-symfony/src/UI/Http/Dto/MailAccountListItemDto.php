<?php

declare(strict_types=1);

namespace App\UI\Http\Dto;

final class MailAccountListItemDto
{
    public function __construct(
        public string $account_id,
        public ?string $label = null,
        public ?string $email = null,
    ) {
    }

    /** @return array{account_id: string, label: ?string, email: ?string} */
    public function toArray(): array
    {
        return [
            'account_id' => $this->account_id,
            'label' => $this->label,
            'email' => $this->email,
        ];
    }
}
