<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Communication;

use App\Application\Communication\EntityReferenceResolver;
use App\Domain\Communication\Channel;
use App\Domain\Communication\Direction;
use App\Domain\Communication\MailAccount;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Spec 065h — Phase 3 — EntityReferenceResolver unit tests.
 */
final class EntityReferenceResolverTest extends TestCase
{
    private function makeEm(
        ?MailAccount $account = null,
        ?Channel $channel = null,
        ?Direction $direction = null,
    ): EntityManagerInterface {
        $accountRepo = $this->createMock(EntityRepository::class);
        $accountRepo->method('find')->willReturn($account);

        $channelRepo = $this->createMock(EntityRepository::class);
        $channelRepo->method('findOneBy')->willReturn($channel);

        $directionRepo = $this->createMock(EntityRepository::class);
        $directionRepo->method('findOneBy')->willReturn($direction);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnCallback(
            fn (string $class) => match ($class) {
                MailAccount::class => $accountRepo,
                Channel::class => $channelRepo,
                Direction::class => $directionRepo,
                default => throw new \LogicException("Unexpected repo: $class"),
            }
        );

        return $em;
    }

    public function test_resolves_existing_account_channel_direction(): void
    {
        $account = $this->createMock(MailAccount::class);
        $channel = $this->createMock(Channel::class);
        $direction = $this->createMock(Direction::class);

        $resolver = new EntityReferenceResolver($this->makeEm($account, $channel, $direction), new NullLogger());
        $result = $resolver->resolve('some-account-id', 'email');

        $this->assertSame($account, $result->account);
        $this->assertSame($channel, $result->channel);
        $this->assertSame($direction, $result->direction);
    }

    public function test_throws_on_missing_account(): void
    {
        $channel = $this->createMock(Channel::class);
        $direction = $this->createMock(Direction::class);

        $resolver = new EntityReferenceResolver($this->makeEm(null, $channel, $direction), new NullLogger());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown account_id');
        $resolver->resolve('nonexistent', 'email');
    }

    public function test_throws_on_missing_channel(): void
    {
        $account = $this->createMock(MailAccount::class);
        $direction = $this->createMock(Direction::class);

        $resolver = new EntityReferenceResolver($this->makeEm($account, null, $direction), new NullLogger());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown channel');
        $resolver->resolve('some-id', 'email');
    }

    public function test_throws_on_missing_direction(): void
    {
        $account = $this->createMock(MailAccount::class);
        $channel = $this->createMock(Channel::class);

        $resolver = new EntityReferenceResolver($this->makeEm($account, $channel, null), new NullLogger());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown direction');
        $resolver->resolve('some-id', 'email');
    }

    public function test_default_channel_is_email(): void
    {
        $account = $this->createMock(MailAccount::class);
        $channel = $this->createMock(Channel::class);
        $direction = $this->createMock(Direction::class);

        $em = $this->makeEm($account, $channel, $direction);
        $resolver = new EntityReferenceResolver($em, new NullLogger());

        // When no channel code is given, default to 'email'
        $result = $resolver->resolve('some-id');
        $this->assertSame($channel, $result->channel);
    }
}
