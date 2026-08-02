<?php

declare(strict_types=1);

namespace App\Domain\CampaignRadar;

enum CampaignStatus: string
{
    case Shadow = 'shadow';
    case Promoted = 'promoted';
    case Archived = 'archived';
}
