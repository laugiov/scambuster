<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Communication;

use App\Application\Communication\IocValidator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for tracking_number IOC type (spec 075g).
 *
 * @covers \App\Application\Communication\IocValidator
 */
final class TrackingNumberExtractionTest extends TestCase
{
    private IocValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new IocValidator();
    }

    public function testDhlTrackingNumberMatches(): void
    {
        $this->assertTrue(
            $this->validator->validate('tracking_number', 'DHL-5336154-US'),
            'DHL-5336154-US should be a valid tracking number',
        );
    }

    public function testUpsTrackingNumberMatches(): void
    {
        $this->assertTrue(
            $this->validator->validate('tracking_number', 'UPS-12345678'),
            'UPS-12345678 should be a valid tracking number',
        );
    }

    public function testFedExTrackingNumberMatches(): void
    {
        $this->assertTrue(
            $this->validator->validate('tracking_number', 'FedEx-9876543210'),
            'FedEx-9876543210 should be a valid tracking number',
        );
    }

    public function testUspsTrackingNumberMatches(): void
    {
        $this->assertTrue(
            $this->validator->validate('tracking_number', 'USPS-7654321'),
            'USPS-7654321 should be a valid tracking number',
        );
    }

    public function testTntTrackingNumberMatches(): void
    {
        $this->assertTrue(
            $this->validator->validate('tracking_number', 'TNT-123456'),
            'TNT-123456 should be a valid tracking number',
        );
    }

    public function testRoyalMailTrackingNumberMatches(): void
    {
        $this->assertTrue(
            $this->validator->validate('tracking_number', 'Royal Mail-12345678'),
            'Royal Mail-12345678 should be a valid tracking number',
        );
    }

    public function testInvoiceNumberDoesNotMatch(): void
    {
        $this->assertFalse(
            $this->validator->validate('tracking_number', 'invoice-123456'),
            'invoice-123456 should NOT match tracking_number (no carrier prefix)',
        );
    }

    public function testRandomNumberDoesNotMatch(): void
    {
        $this->assertFalse(
            $this->validator->validate('tracking_number', '12345678'),
            'Random number 12345678 should NOT match tracking_number',
        );
    }

    public function testTrackingNumberTypeIsSupported(): void
    {
        $this->assertTrue(
            $this->validator->isSupportedType('tracking_number'),
            'tracking_number should be a supported IOC type',
        );
    }

    public function testTrackingNumberWithSpaceSeparator(): void
    {
        $this->assertTrue(
            $this->validator->validate('tracking_number', 'DHL 5336154'),
            'DHL 5336154 (space separator) should be valid',
        );
    }

    public function testEmsTrackingNumberMatches(): void
    {
        $this->assertTrue(
            $this->validator->validate('tracking_number', 'EMS-987654321'),
            'EMS-987654321 should be a valid tracking number',
        );
    }

    public function testColissimoTrackingNumberMatches(): void
    {
        $this->assertTrue(
            $this->validator->validate('tracking_number', 'Colissimo-123456789'),
            'Colissimo-123456789 should be a valid tracking number',
        );
    }
}
