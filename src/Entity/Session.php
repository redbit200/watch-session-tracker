<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Represents the current state of a viewer's watch session.
 *
 * This is the materialized rollup — updated on every event so query time
 * is cheap. Immutable history lives in the Event table.
 */
#[ORM\Entity]
#[ORM\Table(name: 'sessions')]
class Session
{
    /**
     * Mirrors the sessionId from the SDK event — we use the SDK's identifier
     * as PK to keep things simple and idempotent.
     */
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 255)]
    private string $sessionId;

    #[ORM\Column(type: 'string', length: 255)]
    private string $userId;

    /**
     * The stream/event the viewer is watching (e.g. "event-2026-wrestling-finals").
     * Named $streamEventId to avoid confusion with SDK "eventId" on individual events.
     */
    #[ORM\Column(type: 'string', length: 255)]
    private string $streamEventId;

    /**
     * Current playback state: playing, paused, buffering, ended.
     */
    #[ORM\Column(type: 'string', length: 50)]
    private string $state;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $startedAt;

    /**
     * Timestamp of the most recent event received for this session.
     * Used to determine whether a session is "active" (within 90s window).
     */
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $lastEventAt;

    /**
     * Last known playback position in seconds.
     */
    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $lastPosition;

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $quality;

    #[ORM\OneToMany(targetEntity: Event::class, mappedBy: 'session')]
    #[ORM\OrderBy(['eventTimestamp' => 'ASC'])]
    private Collection $events;

    public function __construct(
        string $sessionId,
        string $userId,
        string $streamEventId,
        \DateTimeImmutable $startedAt,
        ?float $lastPosition = null,
        ?string $quality = null,
    ) {
        $this->sessionId = $sessionId;
        $this->userId = $userId;
        $this->streamEventId = $streamEventId;
        $this->state = 'playing';
        $this->startedAt = $startedAt;
        $this->lastEventAt = $startedAt;
        $this->lastPosition = $lastPosition;
        $this->quality = $quality;
        $this->events = new ArrayCollection();
    }

    public function getSessionId(): string { return $this->sessionId; }
    public function getUserId(): string { return $this->userId; }
    public function getStreamEventId(): string { return $this->streamEventId; }
    public function getState(): string { return $this->state; }
    public function getStartedAt(): \DateTimeImmutable { return $this->startedAt; }
    public function getLastEventAt(): \DateTimeImmutable { return $this->lastEventAt; }
    public function getLastPosition(): ?float { return $this->lastPosition; }
    public function getQuality(): ?string { return $this->quality; }

    /** @return Collection<int, Event> */
    public function getEvents(): Collection { return $this->events; }

    public function setState(string $state): void { $this->state = $state; }

    public function touch(\DateTimeImmutable $at, ?float $position = null, ?string $quality = null): void
    {
        $this->lastEventAt = $at;
        if ($position !== null) {
            $this->lastPosition = $position;
        }
        if ($quality !== null) {
            $this->quality = $quality;
        }
    }
}
