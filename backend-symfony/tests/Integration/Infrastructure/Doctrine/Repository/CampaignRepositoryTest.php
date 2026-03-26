<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Doctrine\Repository;

use App\Infrastructure\Campaign\Doctrine\CampaignRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class CampaignRepositoryTest extends KernelTestCase
{
    private CampaignRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->repository = self::getContainer()->get(CampaignRepository::class);
    }

    public function testFindMessagesByCampaignWithNonExistentCampaignReturnsEmpty(): void
    {
        $fakeId = Uuid::v4();

        $result = $this->repository->findMessagesByCampaign($fakeId);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testFindMessagesByCampaignLimitTooLowThrowsException(): void
    {
        $fakeId = Uuid::v4();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Limit must be between 1 and 100/');

        $this->repository->findMessagesByCampaign($fakeId, 0);
    }

    public function testFindMessagesByCampaignLimitTooHighThrowsException(): void
    {
        $fakeId = Uuid::v4();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Limit must be between 1 and 100/');

        $this->repository->findMessagesByCampaign($fakeId, 101);
    }

    public function testFindMessagesByCampaignAcceptsValidLimit(): void
    {
        $fakeId = Uuid::v4();

        // Limit 1 and 100 should NOT throw
        $result1 = $this->repository->findMessagesByCampaign($fakeId, 1);
        $this->assertIsArray($result1);

        $result100 = $this->repository->findMessagesByCampaign($fakeId, 100);
        $this->assertIsArray($result100);
    }

    public function testFindActiveReturnsArray(): void
    {
        $result = $this->repository->findActive();

        $this->assertIsArray($result);
    }

    public function testFindByIdWithNonExistentIdReturnsNull(): void
    {
        $fakeId = Uuid::v4();

        $result = $this->repository->findById($fakeId);

        $this->assertNull($result);
    }
}
