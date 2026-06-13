<?php

declare(strict_types=1);

namespace App\Application\Communication;

use App\Domain\Communication\Conversation;
use App\Domain\Communication\IocCategory;
use App\Domain\Communication\Message;
use App\Domain\Communication\ObservedIoc;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Spec 097 — Composes the Live Bait Theater response in ONE round-trip.
 *
 * Slice 1 deliverable: meta + messages + iocs (deduplicated by value_norm,
 * first-message wins). Slice 2 will add revelation_context (LEFT JOIN on
 * `ioc_context`) and `human_factor` aggregates.
 *
 * Hard rules:
 * - The IOC set returned MUST equal the set returned by `IocHandler::
 *   getConversationIocs` after dedup by `value_norm`. The Theater MUST NOT
 *   re-implement IOC attribution.
 * - Soft-deleted messages are excluded. IOCs whose parent message is
 *   soft-deleted (or otherwise absent from the messages list) are
 *   excluded as well (orphan IOC rule, spec §Behavior rule #8).
 * - Conversations with > 100 messages are TRUNCATED to the first 100
 *   (long_conversation_truncated = true). IOCs whose parent message is
 *   outside the truncated window are also excluded.
 */
final readonly class TheaterAssemblyService
{
    public const int LONG_CONVERSATION_THRESHOLD = 100;

    public function __construct(
        private EntityManagerInterface $em,
        private IocHandler $iocHandler,
    ) {
    }

    /**
     * Assemble the Theater payload for a conversation.
     *
     * @return array{
     *   meta: array<string, mixed>,
     *   messages: list<array<string, mixed>>,
     *   iocs_by_msg: list<array<string, mixed>>
     * }
     */
    public function assemble(Conversation $conv): array
    {
        $allMessages = $this->loadOrderedMessages($conv->getConvId());

        $truncated = false;

        if (\count($allMessages) > self::LONG_CONVERSATION_THRESHOLD) {
            $allMessages = \array_slice($allMessages, 0, self::LONG_CONVERSATION_THRESHOLD);
            $truncated = true;
        }

        $messageIdSet = $this->buildMessageIdSet($allMessages);
        $messages = $this->serializeMessages($allMessages);

        $iocs = $this->iocHandler->getConversationIocs($conv->getConvId());
        $iocsByMsg = $this->serializeAndDedupIocs(array_values($iocs), $messageIdSet);

        $persona = $conv->getPersona();

        $meta = [
            'conv_id' => $conv->getConvId(),
            'scam_type' => $conv->getScamType()->getCode(),
            'scammer_address' => $this->extractScammerAddress($allMessages),
            'persona_address' => $this->extractPersonaAddress($allMessages),
            'persona_code' => $persona?->getPersonaCode(),
            'status' => $conv->getStatus()->value,
            'ts_first' => $conv->getTsFirst()->format(DATE_ATOM),
            'ts_last' => $conv->getTsLast()->format(DATE_ATOM),
            'messages_count' => \count($messages),
            'iocs_count' => \count($iocsByMsg),
            'long_conversation_truncated' => $truncated,
        ];

        return [
            'meta' => $meta,
            'messages' => $messages,
            'iocs_by_msg' => $iocsByMsg,
        ];
    }

    /**
     * @return list<Message>
     */
    private function loadOrderedMessages(string $convId): array
    {
        $qb = $this->em->createQueryBuilder();
        $qb->select('m')
            ->from(Message::class, 'm')
            ->where('m.conversation = :convId')
            ->andWhere('m.deletedAt IS NULL')
            ->setParameter('convId', $convId)
            ->orderBy('m.tsMsg', 'ASC')
            ->addOrderBy('m.msgId', 'ASC');

        /** @var list<Message> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    /**
     * @param list<Message> $messages
     *
     * @return array<string, true>
     */
    private function buildMessageIdSet(array $messages): array
    {
        $set = [];

        foreach ($messages as $m) {
            $set[$m->getMsgId()] = true;
        }

        return $set;
    }

    /**
     * @param list<Message> $messages
     *
     * @return list<array<string, mixed>>
     */
    private function serializeMessages(array $messages): array
    {
        $out = [];

        foreach ($messages as $idx => $m) {
            $headers = $m->getHeaders();
            $from = \is_string($headers['from'] ?? null) ? (string) $headers['from'] : '';

            $out[] = [
                'idx' => $idx + 1,
                'msg_id' => $m->getMsgId(),
                'direction' => 'in' === $m->getDirection()->getCode() ? 'in' : 'out',
                'ts_msg' => $m->getTsMsg()->format(DATE_ATOM),
                'sender' => $from,
                'subject' => $m->getSubject(),
                'body_text' => $m->getBodyText(),
            ];
        }

        return $out;
    }

    /**
     * @param list<ObservedIoc>   $iocs
     * @param array<string, true> $messageIdSet
     *
     * @return list<array<string, mixed>>
     */
    private function serializeAndDedupIocs(array $iocs, array $messageIdSet): array
    {
        // Sort by message ts_msg ASC then ts_observed ASC so the first
        // occurrence in conversation-time order wins on dedup.
        usort($iocs, static function (ObservedIoc $a, ObservedIoc $b): int {
            $tsA = $a->getMessage()->getTsMsg();
            $tsB = $b->getMessage()->getTsMsg();
            $tsCmp = $tsA <=> $tsB;

            if (0 !== $tsCmp) {
                return $tsCmp;
            }

            return $a->getTsObserved() <=> $b->getTsObserved();
        });

        $seen = [];
        $out = [];

        foreach ($iocs as $ioc) {
            $msg = $ioc->getMessage();
            $msgId = $msg->getMsgId();

            // Orphan IOC: parent message not in the (possibly truncated)
            // messages list. Skip.
            if (!isset($messageIdSet[$msgId])) {
                continue;
            }

            $context = $ioc->getContext();
            $valueNorm = \is_string($context['value_norm'] ?? null) ? (string) $context['value_norm'] : '';

            if ('' === $valueNorm) {
                continue;
            }

            if (isset($seen[$valueNorm])) {
                continue;
            }
            $seen[$valueNorm] = true;

            $type = \is_string($context['type'] ?? null) ? (string) $context['type'] : '';
            $value = \is_string($context['value'] ?? null) ? (string) $context['value'] : '';

            $out[] = [
                'msg_id' => $msgId,
                'obs_id' => $ioc->getObsId(),
                'indicator_id' => $ioc->getIndicatorId(),
                'type' => $type,
                'value' => $value,
                'value_norm' => $valueNorm,
                'category' => IocCategory::classify($type),
                'ts_observed' => $ioc->getTsObserved()->format(DATE_ATOM),
                // Slice 2 will populate this from a JOIN on ioc_context.
                'revelation_context' => null,
            ];
        }

        return $out;
    }

    /**
     * @param list<Message> $messages
     */
    private function extractScammerAddress(array $messages): ?string
    {
        foreach ($messages as $m) {
            if ('in' === $m->getDirection()->getCode()) {
                $headers = $m->getHeaders();

                return \is_string($headers['from'] ?? null) ? (string) $headers['from'] : null;
            }
        }

        return null;
    }

    /**
     * @param list<Message> $messages
     */
    private function extractPersonaAddress(array $messages): ?string
    {
        foreach ($messages as $m) {
            if ('out' === $m->getDirection()->getCode()) {
                $headers = $m->getHeaders();

                return \is_string($headers['from'] ?? null) ? (string) $headers['from'] : null;
            }
        }

        return null;
    }
}
