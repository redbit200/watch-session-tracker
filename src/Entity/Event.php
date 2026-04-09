<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Immutable record of a single SDK event. Append-only — never updated.
 *
 * The UNIQUE constraint on eventId is the idempotency key: if the SDK retries
 * or the ingestion layer delivers a duplicate, the second write fails silently
 * and we return 200 instead of 202. No events are lost, no duplicates created.
 */
#[ORM\Entity]
#[ORM\Table(name: 'events')]
#[ORM\UniqueConstraint(name: 'uniq_event_id', columns: ['event_id'])]
class Event
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    /**
     * The SDK's own identifier for this event (used for idempotency).
     */
    #[ORM\Column(type: 'string', length: 255, unique: true)]
    private string $eventId;

    #[ORM\ManyToOne(targetEntity: Session::class, inversedBy: 'events')]
    #[ORM\JoinColumn(name: 'session_id', referencedColumnName: 'session_id', nullable: false)]
    private Session $session;

    #[ORM\Column(type: 'string', length: 50)]
    private string $eventType;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $eventTimestamp;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $receivedAt;

    /**
     * Full payload stored as JSON for future extensibility.
     * Avoids building columns for every possible field upfront.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $payload;

    public function __construct(
        string $eventId,
        Session $session,
        string $eventType,
        \DateTimeImmutable $eventTimestamp,
        \DateTimeImmutable $receivedAt,
        array $payload,
    ) {
        $this->eventId = $eventId;
        $this->session = $session;
        $this->eventType = $eventType;
        $this->eventTimestamp = $eventTimestamp;
        $this->receivedAt = $receivedAt;
        $this->payload = $payload;
    }

    public function getId(): int { return $this->id; }
    public function getEventId(): string { return $this->eventId; }
    public function getSession(): Session { return $this->session; }
    public function getEventType(): string { return $this->eventType; }
    public function getEventTimestamp(): \DateTimeImmutable { return $this->eventTimestamp; }
    public function getReceivedAt(): \DateTimeImmutable { return $this->receivedAt; }
    public function getPayload(): array { return $this->payload; }
}
