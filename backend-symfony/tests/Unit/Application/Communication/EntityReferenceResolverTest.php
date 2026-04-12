<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Communication;

use App\Application\Communication\EntityReferenceResolver;
use App\Domain\Communication\Channel;
use App\Domain\Communication\Direction;
use App\Domain\Communication\MailAccount;
use App\Domain\Communication\Repository\ChannelRepositoryInterface;
use App\Domain\Communication\Repository\DirectionRepositoryInterface;
use App\Domain\Communication\Repository\MailAccountRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Spec 065h / 066d — EntityReferenceResolver unit tests.
 * Now uses Domain repository interfaces instead of EntityManager mocks.
 */
final class EntityReferenceResolverTest extends TestCase
{
    private function makeResolver(
        ?MailAccount $account = null,
        ?Channel $channel = null,
        ?Direction $direction = null,
    ): EntityReferenceResolver {
        $mailAccountRepo = $this->createMock(MailAccountRepositoryInterface::class);
        $mailAccountRepo->method('findById')->willReturn($account);

        $channelRepo = $this->createMock(ChannelRepositoryInterface::class);
        $channelRepo->method('findByCode')->willReturn($channel);

        $directionRepo = $this->createMock(DirectionRepositoryInterface::class);
        $directionRepo->method('findByCode')->willReturn($direction);

        return new EntityReferenceResolver($mailAccountRepo, $channelRepo, $directionRepo, new NullLogger());
    }

    public function test_resolves_existing_account_channel_direction(): void
    {
        $account = $this->createMock(MailAccount::class);
        $channel = $this->createMock(Channel::class);
        $direction = $this->createMock(Direction::class);

        $result = $this->makeResolver($account, $channel, $direction)->resolve('some-account-id', 'email');

        $this->assertSame($account, $result->account);
        $this->assertSame($channel, $result->channel);
        $this->assertSame($direction, $result->direction);
    }

    public function test_throws_on_missing_account(): void
    {
        $channel = $this->createMock(Channel::class);
        $direction = $this->createMock(Direction::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown account_id');
        $this->makeResolver(null, $channel, $direction)->resolve('nonexistent', 'email');
    }

    public function test_throws_on_missing_channel(): void
    {
        $account = $this->createMock(MailAccount::class);
        $direction = $this->createMock(Direction::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown channel');
        $this->makeResolver($account, null, $direction)->resolve('some-id', 'email');
    }

    public function test_throws_on_missing_direction(): void
    {
        $account = $this->createMock(MailAccount::class);
        $channel = $this->createMock(Channel::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown direction');
        $this->makeResolver($account, $channel, null)->resolve('some-id', 'email');
    }

    public function test_default_channel_is_email(): void
    {
        $account = $this->createMock(MailAccount::class);
        $channel = $this->createMock(Channel::class);
        $direction = $this->createMock(Direction::class);

        $result = $this->makeResolver($account, $channel, $direction)->resolve('some-id');
        $this->assertSame($channel, $result->channel);
    }
}
