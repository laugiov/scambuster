<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Communication;

use App\Application\Communication\IocValidator;
use PHPUnit\Framework\TestCase;

/**
 * Additional coverage for IocValidator uncovered branches:
 * - ip type (line 96): validates as ipv4 or ipv6
 * - file_hash type (line 101): validates as md5, sha1, or sha256
 * - unknown type (line 111): returns false
 * - credit_card invalid format (line 129)
 */
class IocValidatorCoverageTest extends TestCase
{
    private IocValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new IocValidator();
    }

    public function testIpTypeValidatesAsIpv4(): void
    {
        $this->assertTrue($this->validator->validate('ip', '8.8.8.8'));
    }

    public function testIpTypeValidatesAsIpv6(): void
    {
        $this->assertTrue($this->validator->validate('ip', '2001:db8::1'));
    }

    public function testIpTypeRejectsInvalid(): void
    {
        $this->assertFalse($this->validator->validate('ip', 'not-an-ip'));
    }

    public function testFileHashValidatesAsMd5(): void
    {
        $this->assertTrue($this->validator->validate('file_hash', str_repeat('a', 32)));
    }

    public function testFileHashValidatesAsSha1(): void
    {
        $this->assertTrue($this->validator->validate('file_hash', str_repeat('a', 40)));
    }

    public function testFileHashValidatesAsSha256(): void
    {
        $this->assertTrue($this->validator->validate('file_hash', str_repeat('a', 64)));
    }

    public function testFileHashRejectsInvalid(): void
    {
        $this->assertFalse($this->validator->validate('file_hash', 'not-a-hash'));
    }

    public function testUnknownTypeReturnsFalse(): void
    {
        $this->assertFalse($this->validator->validate('totally_unknown_type', 'anything'));
    }

    public function testCreditCardRejectsInvalidFormat(): void
    {
        $this->assertFalse($this->validator->validate('credit_card', '123')); // too short
    }

    public function testCreditCardRejectsNonNumeric(): void
    {
        $this->assertFalse($this->validator->validate('credit_card', 'abcdefghijklm'));
    }
}
