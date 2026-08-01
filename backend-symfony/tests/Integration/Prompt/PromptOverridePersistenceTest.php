<?php

declare(strict_types=1);

namespace App\Tests\Integration\Prompt;

use App\Application\LLM\Prompt\PromptProvider;
use App\Domain\Prompt\PromptOverride;
use App\Domain\Prompt\PromptOverrideRepositoryInterface;
use App\Infrastructure\Prompt\CachedDbPromptOverrideSource;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * End-to-end DB path for prompt overrides: entity mapping + Doctrine repository +
 * cached source + the wired PromptProvider, against a real database. Proves the
 * migration/entity are consistent and that a DB override wins over the default.
 */
final class PromptOverridePersistenceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private PromptOverrideRepositoryInterface $repository;

    /** @var list<int> */
    private array $createdIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get('doctrine')->getManager();
        $this->repository = static::getContainer()->get(PromptOverrideRepositoryInterface::class);
    }

    protected function tearDown(): void
    {
        // Clean up only the rows this test created.
        foreach ($this->createdIds as $id) {
            $row = $this->em->getRepository(PromptOverride::class)->find($id);

            if ($row !== null) {
                $this->em->remove($row);
            }
        }
        $this->em->flush();

        parent::tearDown();
    }

    private function persist(PromptOverride $override): PromptOverride
    {
        $this->repository->save($override);
        $id = $override->getId();
        self::assertGreaterThan(0, $id, 'Doctrine must assign an id on persist');
        $this->createdIds[] = $id;

        return $override;
    }

    public function testSaveAndFindByKeyRoundTrips(): void
    {
        $this->persist(new PromptOverride('reward_judge', 'DB RUBRIC BODY', true, 'tester@example.com'));

        $found = $this->repository->findByKey('reward_judge');

        self::assertNotNull($found);
        self::assertSame('DB RUBRIC BODY', $found->getBody());
        self::assertTrue($found->isEnabled());
        self::assertSame('tester@example.com', $found->getUpdatedBy());
    }

    public function testFindAllEnabledExcludesDisabledRows(): void
    {
        $this->persist(new PromptOverride('reward_judge', 'ENABLED', true));
        $this->persist(new PromptOverride('contextual_enrichment', 'DISABLED', false));

        $keys = array_map(
            static fn (PromptOverride $o): string => $o->getPromptKey(),
            $this->repository->findAllEnabled(),
        );

        self::assertContains('reward_judge', $keys);
        self::assertNotContains('contextual_enrichment', $keys);
    }

    public function testCachedSourceReturnsEnabledBody(): void
    {
        $this->persist(new PromptOverride('reward_judge', 'FROM DB', true));

        $source = static::getContainer()->get(CachedDbPromptOverrideSource::class);

        self::assertSame('FROM DB', $source->get('reward_judge'));
    }

    public function testWiredPromptProviderResolvesDbOverrideOverDefault(): void
    {
        $this->persist(new PromptOverride('reward_judge', 'DB OVERRIDE WINS', true));

        $provider = static::getContainer()->get(PromptProvider::class);

        // reward_judge has no required placeholders; the enabled DB body wins over default.
        self::assertSame('DB OVERRIDE WINS', $provider->resolve('reward_judge', [], 'SHIPPED DEFAULT'));
    }
}
