<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Communication;

use App\Domain\Communication\Channel;
use PHPUnit\Framework\TestCase;

class ChannelTest extends TestCase
{
    public function test_it_creates_channel_with_labels(): void
    {
        $channel = new Channel('email', 'Email (SMTP/IMAP/Graph)', 'E-mail');

        $this->assertSame('email', $channel->getCode());
        $this->assertSame('Email (SMTP/IMAP/Graph)', $channel->getLabelEn());
        $this->assertSame('E-mail', $channel->getLabelFr());
    }
} 