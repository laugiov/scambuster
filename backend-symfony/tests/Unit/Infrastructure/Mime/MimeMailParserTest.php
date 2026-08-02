<?php

declare(strict_types=1);

namespace App\Tests\Integration\Communication;

use PHPUnit\Framework\TestCase;
use eXorus\PhpMimeMailParser\Parser;

class MimeMailParserTest extends TestCase
{
    public function test_parse_headers_and_body(): void
    {
        // Ignore deprecations and dynamic property warnings for legacy library
        set_error_handler(function ($errno, $errstr) {
            if (str_contains($errstr, 'is deprecated') || str_contains($errstr, 'Creation of dynamic property')) {
                return true;
            }
            return false;
        }, E_USER_DEPRECATED | E_DEPRECATED | E_WARNING | E_NOTICE);

        $raw = <<<MAIL
Subject: Test subject
From: "Foo Bar" <foo@bar.com>
To: bar@foo.com
Date: Thu, 23 May 2024 10:00:00 +0000
Message-ID: <abc@foo.com>
MIME-Version: 1.0
Content-Type: text/plain; charset=UTF-8

Hello world!

MAIL;
        $parser = new Parser();
        $parser->setText($raw);
        $this->assertSame('Test subject', $parser->getHeader('subject'));
        $this->assertSame('"Foo Bar" <foo@bar.com>', $parser->getHeader('from'));
        $this->assertSame('bar@foo.com', $parser->getHeader('to'));
        $this->assertSame('Thu, 23 May 2024 10:00:00 +0000', $parser->getHeader('date'));
        $this->assertSame('<abc@foo.com>', $parser->getHeader('message-id'));
        $this->assertSame('Hello world!', trim($parser->getMessageBody('text')));

        restore_error_handler();
    }
} 