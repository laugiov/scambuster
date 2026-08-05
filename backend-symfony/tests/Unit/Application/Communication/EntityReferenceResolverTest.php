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
 * EntityReferenceResolver unit tests.
 * Now uses Domain repository interfaces instead of EntityManager mocks.
 */
final class EntityReferenceResolverTest extends TestCase
{
    private function makeResolver(
        ?MailAccount $account = null,
        ?Channel $channel = null,
        ?Direction $direction = null,
        ?callable $findByEmail = null,
    ): EntityReferenceResolver {
        $mailAccountRepo = $this->createMock(MailAccountRepositoryInterface::class);
        $mailAccountRepo->method('findById')->willReturn($account);
        $mailAccountRepo->method('findByEmail')->willReturnCallback(
            $findByEmail ?? static fn (): ?MailAccount => null
        );

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

    public function test_resolves_by_recipient_when_account_id_empty(): void
    {
        $account = $this->createMock(MailAccount::class);
        $channel = $this->createMock(Channel::class);
        $direction = $this->createMock(Direction::class);

        $resolver = $this->makeResolver(
            null,
            $channel,
            $direction,
            static fn (string $email): ?MailAccount => 'honeypot@example.test' === $email ? $account : null,
        );

        $result = $resolver->resolve('', 'email', ['honeypot@example.test']);

        $this->assertSame($account, $result->account);
    }

    public function test_resolves_by_recipient_when_account_id_unknown(): void
    {
        $account = $this->createMock(MailAccount::class);
        $channel = $this->createMock(Channel::class);
        $direction = $this->createMock(Direction::class);

        // findById returns null (account param is null), recipient fallback wins.
        $resolver = $this->makeResolver(
            null,
            $channel,
            $direction,
            static fn (string $email): ?MailAccount => 'honeypot@example.test' === $email ? $account : null,
        );

        $result = $resolver->resolve('stale-id', 'email', ['honeypot@example.test']);

        $this->assertSame($account, $result->account);
    }

    public function test_account_id_takes_precedence_over_recipient(): void
    {
        $byId = $this->createMock(MailAccount::class);
        $byEmail = $this->createMock(MailAccount::class);
        $channel = $this->createMock(Channel::class);
        $direction = $this->createMock(Direction::class);

        $resolver = $this->makeResolver(
            $byId,
            $channel,
            $direction,
            static fn (): MailAccount => $byEmail,
        );

        $result = $resolver->resolve('valid-id', 'email', ['honeypot@example.test']);

        $this->assertSame($byId, $result->account);
    }

    public function test_tries_recipient_candidates_in_order(): void
    {
        $account = $this->createMock(MailAccount::class);
        $channel = $this->createMock(Channel::class);
        $direction = $this->createMock(Direction::class);

        // Only the second candidate maps to a known account.
        $resolver = $this->makeResolver(
            null,
            $channel,
            $direction,
            static fn (string $email): ?MailAccount => 'known@example.test' === $email ? $account : null,
        );

        $result = $resolver->resolve('', 'email', ['unknown@example.test', 'known@example.test']);

        $this->assertSame($account, $result->account);
    }

    public function test_throws_when_neither_account_id_nor_recipient_resolves(): void
    {
        $channel = $this->createMock(Channel::class);
        $direction = $this->createMock(Direction::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown account_id');
        $this->makeResolver(null, $channel, $direction)->resolve('', 'email', ['nobody@example.test']);
    }
}
