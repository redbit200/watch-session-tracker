<?php

namespace App\Service;

use App\Dto\IncomingEvent;
use App\Entity\Event;
use App\Entity\Session;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Core business logic for ingesting SDK events.
 *
 * Responsibilities:
 *   1. Upsert the Session row (create on "start", update on everything else)
 *   2. Append an Event row (idempotent via the UNIQUE index on event_id)
 *   3. Advance the session state machine based on event type
 *
 * Intentionally kept as a simple service class rather than an event dispatcher
 * pattern — this is v1 and we want it easy to reason about.
 */
class SessionTracker
{
    /**
     * State machine: maps event type → new session state.
     *
     * Types that don't change state (seek, quality_change, heartbeat)
     * are absent — we still update last_event_at but leave state alone.
     */
    private const STATE_TRANSITIONS = [
        'start'        => 'playing',
        'resume'       => 'playing',
        'pause'        => 'paused',
        'buffer_start' => 'buffering',
        'buffer_end'   => 'playing',
        'end'          => 'ended',
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Process one SDK event. Returns true if the event was a duplicate (already seen).
     */
    public function handle(IncomingEvent $dto): bool
    {
        $eventTimestamp = new \DateTimeImmutable($dto->eventTimestamp);
        $receivedAt     = new \DateTimeImmutable($dto->receivedAt);

        $session = $this->upsertSession($dto, $eventTimestamp);

        return $this->appendEvent($dto, $session, $eventTimestamp, $receivedAt);
    }

    /**
     * Find or create the session, then update its state and position.
     */
    private function upsertSession(IncomingEvent $dto, \DateTimeImmutable $eventTimestamp): Session
    {
        $session = $this->em->find(Session::class, $dto->sessionId);

        if ($session === null) {
            // First event for this session — create it regardless of event type.
            // In practice this should always be "start", but the SDK isn't guaranteed
            // to deliver events in order (network reordering). We handle it gracefully.
            $session = new Session(
                sessionId: $dto->sessionId,
                userId: $dto->userId,
                streamEventId: $dto->streamEventId,
                startedAt: $eventTimestamp,
                lastPosition: $dto->position,
                quality: $dto->quality,
            );
            $this->em->persist($session);
        }

        // Advance state machine if this event type triggers a transition
        if (isset(self::STATE_TRANSITIONS[$dto->eventType])) {
            $session->setState(self::STATE_TRANSITIONS[$dto->eventType]);
        }

        // Always refresh the "last seen" timestamp and position
        $session->touch($eventTimestamp, $dto->position, $dto->quality);

        return $session;
    }

    /**
     * Persist the raw event. Returns true if this eventId was already stored (duplicate).
     */
    private function appendEvent(
        IncomingEvent $dto,
        Session $session,
        \DateTimeImmutable $eventTimestamp,
        \DateTimeImmutable $receivedAt,
    ): bool {
        $event = new Event(
            eventId: $dto->eventId,
            session: $session,
            eventType: $dto->eventType,
            eventTimestamp: $eventTimestamp,
            receivedAt: $receivedAt,
            payload: [
                'eventId'  => $dto->streamEventId,
                'position' => $dto->position,
                'quality'  => $dto->quality,
            ],
        );

        $this->em->persist($event);

        try {
            $this->em->flush();
            return false; // new event
        } catch (UniqueConstraintViolationException) {
            // Duplicate eventId — SDK retry or double-delivery. Safe to ignore.
            $this->em->clear(); // reset EM state after the failed transaction
            return true;
        }
    }
}
