<?php

declare(strict_types=1);

namespace App\Domain\Communication;

enum ConversationStatus: string
{
    case OPEN = 'open';
    case CLOSED = 'closed';
    case ABANDONED = 'abandoned';
    case MISTAKE = 'mistake';
}
