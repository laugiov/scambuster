<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\CampaignRadar;

use App\Domain\CampaignRadar\Campaign;
use App\Domain\CampaignRadar\CampaignStatus;
use App\Domain\Exception\DomainException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class CampaignTest extends TestCase
{
    public function testConstructorCreatesValidCampaign(): void
    {
        $campaign = new Campaign('llm-compiler@gpt-4o-mini');

        $this->assertInstanceOf(Uuid::class, $campaign->getCampaignId());
        $this->assertSame(CampaignStatus::Shadow, $campaign->getStatus());
        $this->assertInstanceOf(\DateTimeImmutable::class, $campaign->getFirstSeen());
        $this->assertSame('llm-compiler@gpt-4o-mini', $campaign->getCreatedBy());
    }

    public function testConstructorThrowsOnEmptyCreatedBy(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('createdBy cannot be empty');

        new Campaign('   ');
    }

    public function testPromoteSetsStatusToPromoted(): void
    {
        $campaign = new Campaign('test');
        $campaign->promote();

        $this->assertSame(CampaignStatus::Promoted, $campaign->getStatus());
    }

    public function testPromoteThrowsWhenNotInShadowStatus(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Cannot promote campaign with status');

        $campaign = new Campaign('test', status: CampaignStatus::Archived);
        $campaign->promote();
    }

    public function testArchiveSetsStatusToArchived(): void
    {
        $campaign = new Campaign('test');
        $campaign->archive();

        $this->assertSame(CampaignStatus::Archived, $campaign->getStatus());
    }

    public function testArchiveThrowsWhenAlreadyArchived(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Campaign is already archived');

        $campaign = new Campaign('test');
        $campaign->archive();
        $campaign->archive(); // Second call should throw
    }

    public function testSetDslHashStoresHash(): void
    {
        $campaign = new Campaign('test');
        $hash = '1234567890abcdef';

        $campaign->setDslHash($hash);

        $this->assertSame($hash, $campaign->getDslHash());
    }

    public function testSetDslHashThrowsOnEmptyString(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('dslHash cannot be empty');

        $campaign = new Campaign('test');
        $campaign->setDslHash('');
    }

    public function testSetSeverityAcceptsValidRange(): void
    {
        $campaign = new Campaign('test');

        for ($i = 1; $i <= 5; $i++) {
            $campaign->setSeverity($i);
            $this->assertSame($i, $campaign->getSeverity());
        }
    }

    public function testSetSeverityThrowsOnInvalidValue(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Severity must be between 1 and 5');

        $campaign = new Campaign('test');
        $campaign->setSeverity(10);
    }

    public function testSetTlpAcceptsValidTlps(): void
    {
        $campaign = new Campaign('test');
        $validTlps = ['TLP:RED', 'TLP:AMBER', 'TLP:GREEN', 'TLP:WHITE'];

        foreach ($validTlps as $tlp) {
            $campaign->setTlp($tlp);
            $this->assertSame($tlp, $campaign->getTlp());
        }
    }

    public function testSetTlpThrowsOnInvalidTlp(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Invalid TLP');

        $campaign = new Campaign('test');
        $campaign->setTlp('TLP:INVALID');
    }

    public function testAddNoteAppendsText(): void
    {
        $campaign = new Campaign('test');
        $campaign->addNote('First note');
        $campaign->addNote('Second note');

        $expected = "First note\n---\nSecond note";
        $this->assertSame($expected, $campaign->getNotes());
    }

    public function testSetActorGuessStoresValue(): void
    {
        $campaign = new Campaign('test');
        $campaign->setActorGuess('Scammer Group X');

        $this->assertSame('Scammer Group X', $campaign->getActorGuess());
    }

    public function testSetProfileYamlStoresYaml(): void
    {
        $campaign = new Campaign('test');
        $yaml = "campaign:\n  summary: Test campaign\n  risk: high";

        $campaign->setProfileYaml($yaml);

        $this->assertSame($yaml, $campaign->getProfileYaml());
    }

    public function testSetCentroidSimhashStoresHash(): void
    {
        $campaign = new Campaign('test');
        $hash = '12345678901234567890123456789012'; // 32 caractères

        $campaign->setCentroidSimhash($hash);

        $this->assertSame($hash, $campaign->getCentroidSimhash());
    }

    public function testSetCentroidSimhashThrowsOnInvalidLength(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Centroid simhash must be 32 characters');

        $campaign = new Campaign('test');
        $campaign->setCentroidSimhash('tooshort');
    }
}
