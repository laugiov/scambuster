<?php

declare(strict_types=1);

namespace App\Domain\Communication;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'message')]
class Message
{
    #[ORM\Id]
    #[ORM\Column(name: 'msg_id', type: 'uuid', unique: true)]
    private string $msgId;

    #[ORM\ManyToOne(targetEntity: Conversation::class)]
    #[ORM\JoinColumn(name: 'conv_id', referencedColumnName: 'conv_id', nullable: false, onDelete: 'CASCADE')]
    private Conversation $conversation;

    #[ORM\ManyToOne(targetEntity: Channel::class)]
    #[ORM\JoinColumn(name: 'channel_id', referencedColumnName: 'channel_id', nullable: false)]
    private Channel $channel;

    #[ORM\ManyToOne(targetEntity: Direction::class)]
    #[ORM\JoinColumn(name: 'direction', referencedColumnName: 'dir_id', nullable: false)]
    private Direction $direction;

    #[ORM\Column(name: 'lang_detect', type: 'string', length: 2)]
    private string $langDetect;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $subject = null;

    #[ORM\Column(name: 'body_text', type: 'text')]
    private string $bodyText;

    #[ORM\Column(name: 'body_html', type: 'text', nullable: true)]
    private ?string $bodyHtml = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $headers;

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'url_analysis', type: 'json', nullable: true)]
    private ?array $urlAnalysis = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'injection_analysis', type: 'json', nullable: true)]
    private ?array $injectionAnalysis = null;

    #[ORM\Column(name: 'composite_hash', type: 'string', length: 64, unique: true)]
    private string $compositeHash;

    #[ORM\Column(name: 'vector_id', type: 'uuid', nullable: true)]
    private ?string $vectorId = null;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'reply_to_msg_id', referencedColumnName: 'msg_id', nullable: true)]
    private ?Message $replyTo = null;

    #[ORM\Column(name: 'ts_msg', type: 'datetime_immutable')]
    private \DateTimeImmutable $tsMsg;

    #[ORM\Column(name: 'ts_ingest', type: 'datetime_immutable')]
    private \DateTimeImmutable $tsIngest;

    #[ORM\Column(name: 'deleted_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    #[ORM\Column(name: 'raw_source', type: 'text', nullable: true)]
    private ?string $rawSource = null;

    #[ORM\Column(name: 'external_message_id', type: 'string', length: 255, nullable: true)]
    private ?string $externalMessageId = null;

    /** @var Collection<int, Attachment> */
    #[ORM\OneToMany(targetEntity: Attachment::class, mappedBy: 'message', cascade: ['persist', 'remove'])]
    private Collection $attachments;

    /**
     * @param array<string, mixed> $headers
     */
    public function __construct(
        string $msgId,
        Conversation $conversation,
        Channel $channel,
        Direction $direction,
        string $langDetect,
        ?string $subject,
        string $bodyText,
        ?string $bodyHtml,
        array $headers,
        string $compositeHash,
        ?string $vectorId,
        ?Message $replyTo,
        \DateTimeImmutable $tsMsg,
        \DateTimeImmutable $tsIngest,
        ?\DateTimeImmutable $deletedAt = null
    ) {
        $this->msgId = $msgId;
        $this->conversation = $conversation;
        $this->channel = $channel;
        $this->direction = $direction;
        $this->langDetect = $langDetect;
        $this->subject = $subject;
        $this->bodyText = $bodyText;
        $this->bodyHtml = $bodyHtml;
        $this->headers = $headers;
        $this->compositeHash = $compositeHash;
        $this->vectorId = $vectorId;
        $this->replyTo = $replyTo;
        $this->tsMsg = $tsMsg;
        $this->tsIngest = $tsIngest;
        $this->deletedAt = $deletedAt;
        $this->attachments = new ArrayCollection();
    }

    public function getMsgId(): string
    {
        return $this->msgId;
    }

    public function getConversation(): Conversation
    {
        return $this->conversation;
    }

    public function getChannel(): Channel
    {
        return $this->channel;
    }

    public function getDirection(): Direction
    {
        return $this->direction;
    }

    public function getLangDetect(): string
    {
        return $this->langDetect;
    }

    public function setLangDetect(string $langDetect): void
    {
        $this->langDetect = $langDetect;
    }

    public function getSubject(): ?string
    {
        return $this->subject;
    }

    public function getBodyText(): string
    {
        return $this->bodyText;
    }

    public function getBodyHtml(): ?string
    {
        return $this->bodyHtml;
    }

    /** @return array<string, mixed> */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /** @return array<string, mixed>|null */
    public function getUrlAnalysis(): ?array
    {
        return $this->urlAnalysis;
    }

    public function getCompositeHash(): string
    {
        return $this->compositeHash;
    }

    public function getVectorId(): ?string
    {
        return $this->vectorId;
    }

    public function getReplyTo(): ?Message
    {
        return $this->replyTo;
    }

    public function getTsMsg(): \DateTimeImmutable
    {
        return $this->tsMsg;
    }

    public function getTsIngest(): \DateTimeImmutable
    {
        return $this->tsIngest;
    }

    public function getDeletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function getRawSource(): ?string
    {
        return $this->rawSource;
    }

    public function setRawSource(?string $rawSource): void
    {
        $this->rawSource = $rawSource;
    }

    public function getExternalMessageId(): ?string
    {
        return $this->externalMessageId;
    }

    public function setExternalMessageId(?string $externalMessageId): void
    {
        $this->externalMessageId = $externalMessageId;
    }

    public function setSubject(?string $subject): void
    {
        $this->subject = $subject;
    }

    public function setBodyText(string $bodyText): void
    {
        $this->bodyText = $bodyText;
    }

    public function setBodyHtml(?string $bodyHtml): void
    {
        $this->bodyHtml = $bodyHtml;
    }

    /** @param array<string, mixed> $headers */
    public function setHeaders(array $headers): void
    {
        $this->headers = $headers;
    }

    /** @param array<string, mixed>|null $urlAnalysis */
    public function setUrlAnalysis(?array $urlAnalysis): void
    {
        $this->urlAnalysis = $urlAnalysis;
    }

    /** @return array<string, mixed>|null */
    public function getInjectionAnalysis(): ?array
    {
        return $this->injectionAnalysis;
    }

    /** @param array<string, mixed>|null $injectionAnalysis */
    public function setInjectionAnalysis(?array $injectionAnalysis): void
    {
        $this->injectionAnalysis = $injectionAnalysis;
    }

    public function setTsMsg(\DateTimeImmutable $tsMsg): void
    {
        $this->tsMsg = $tsMsg;
    }

    public function setDirection(Direction $direction): void
    {
        $this->direction = $direction;
    }

    /** @return Collection<int, Attachment> */
    public function getAttachments(): Collection
    {
        return $this->attachments;
    }

    public function addAttachment(Attachment $attachment): void
    {
        if (!$this->attachments->contains($attachment)) {
            $this->attachments->add($attachment);
            $attachment->setMessage($this);
        }
    }

    public function removeAttachment(Attachment $attachment): void
    {
        if ($this->attachments->removeElement($attachment)) {
            // set the owning side to null (unless already changed)
            if ($attachment->getMessage() === $this) {
                $attachment->setMessage(null);
            }
        }
    }

    /**
     * Get send status from headers JSON (for outgoing messages)
     */
    public function getSendStatus(): ?string
    {
        return $this->headers['send_status'] ?? null;
    }

    /**
     * Set send status in headers JSON (for outgoing messages)
     * Valid values: 'draft', 'sent', 'failed'
     */
    public function setSendStatus(string $status): void
    {
        $this->headers['send_status'] = $status;
    }

    /**
     * Get provider message ID from headers JSON (e.g., Gmail message ID)
     */
    public function getProviderMsgId(): ?string
    {
        return $this->headers['provider_msg_id'] ?? null;
    }

    /**
     * Set provider message ID in headers JSON
     */
    public function setProviderMsgId(string $providerMsgId): void
    {
        $this->headers['provider_msg_id'] = $providerMsgId;
    }

    /**
     * Get timestamp when message was sent
     */
    public function getTsSent(): ?\DateTimeImmutable
    {
        if (isset($this->headers['ts_sent'])) {
            return new \DateTimeImmutable($this->headers['ts_sent']);
        }

        return null;
    }

    /**
     * Set timestamp when message was sent
     */
    public function setTsSent(\DateTimeImmutable $tsSent): void
    {
        $this->headers['ts_sent'] = $tsSent->format(DATE_ATOM);
    }
}
