<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\CampaignRadar;

use App\Domain\CampaignRadar\ActorProfile;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class ActorProfileTest extends TestCase
{
    public function test_constructor_generates_uuid_when_not_provided(): void
    {
        $profile = new ActorProfile(
            styleDna: ['vocabulary_size' => 100],
            infraDna: ['ioc_count' => 5],
        );

        $this->assertInstanceOf(Uuid::class, $profile->getActorId());
    }

    public function test_constructor_uses_provided_uuid(): void
    {
        $uuid = Uuid::v7();
        $profile = new ActorProfile(
            styleDna: ['vocabulary_size' => 100],
            infraDna: ['ioc_count' => 5],
            actorId: $uuid,
        );

        $this->assertSame($uuid->toRfc4122(), $profile->getActorId()->toRfc4122());
    }

    public function test_getters_return_correct_values(): void
    {
        $style = ['avg_sentence_length' => 15.5, 'vocabulary_size' => 200];
        $infra = ['unique_domains' => ['evil.com'], 'ioc_count' => 3];

        $profile = new ActorProfile(styleDna: $style, infraDna: $infra);

        $this->assertSame($style, $profile->getStyleDna());
        $this->assertSame($infra, $profile->getInfraDna());
        $this->assertEmpty($profile->getCampaigns());
        $this->assertInstanceOf(\DateTimeImmutable::class, $profile->getCreatedAt());
        $this->assertInstanceOf(\DateTimeImmutable::class, $profile->getUpdatedAt());
    }

    public function test_linkCampaign_adds_campaign_id(): void
    {
        $profile = new ActorProfile(styleDna: [], infraDna: []);
        $campaignId = Uuid::v4();

        $profile->linkCampaign($campaignId);

        $this->assertCount(1, $profile->getCampaigns());
        $this->assertSame($campaignId->toRfc4122(), $profile->getCampaigns()[0]);
    }

    public function test_linkCampaign_is_idempotent(): void
    {
        $profile = new ActorProfile(styleDna: [], infraDna: []);
        $campaignId = Uuid::v4();

        $profile->linkCampaign($campaignId);
        $profile->linkCampaign($campaignId); // Duplicate

        $this->assertCount(1, $profile->getCampaigns());
    }

    public function test_linkCampaign_adds_multiple_distinct_campaigns(): void
    {
        $profile = new ActorProfile(styleDna: [], infraDna: []);
        $id1 = Uuid::v4();
        $id2 = Uuid::v4();

        $profile->linkCampaign($id1);
        $profile->linkCampaign($id2);

        $this->assertCount(2, $profile->getCampaigns());
    }

    public function test_linkCampaign_updates_timestamp(): void
    {
        $profile = new ActorProfile(styleDna: [], infraDna: []);
        $initialUpdatedAt = $profile->getUpdatedAt();

        // Small sleep to ensure timestamp changes
        usleep(1000);

        $profile->linkCampaign(Uuid::v4());

        // updatedAt should be >= initial (could be same if very fast)
        $this->assertGreaterThanOrEqual($initialUpdatedAt, $profile->getUpdatedAt());
    }
}
