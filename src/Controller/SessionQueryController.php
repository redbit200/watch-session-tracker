<?php

namespace App\Controller;

use App\Entity\Event;
use App\Entity\Session;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SessionQueryController extends AbstractController
{
    /**
     * A session is "active" if it hasn't ended and we've heard from it within
     * the last 90 seconds (3× the 30s heartbeat interval — tolerates one missed beat).
     *
     * This threshold is computed at query time rather than by a background sweeper,
     * which keeps the service simple. The trade-off is that if the service is under
     * load and heartbeats are delayed, a session could briefly appear inactive.
     * For v1 with a 10-15s dashboard refresh rate, this is acceptable.
     */
    private const ACTIVE_WINDOW_SECONDS = 90;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * GET /events/{eventId}/active-count
     *
     * Returns the number of sessions currently watching the given stream event.
     * {eventId} here is the stream/match identifier (e.g. "event-2026-wrestling-finals"),
     * not an SDK event ID.
     */
    #[Route('/events/{eventId}/active-count', methods: ['GET'])]
    public function activeCount(string $eventId): JsonResponse
    {
        $cutoff = new \DateTimeImmutable(sprintf('-%d seconds', self::ACTIVE_WINDOW_SECONDS));

        $count = $this->em->createQueryBuilder()
            ->select('COUNT(s.sessionId)')
            ->from(Session::class, 's')
            ->where('s.streamEventId = :eventId')
            ->andWhere('s.state != :ended')
            ->andWhere('s.lastEventAt >= :cutoff')
            ->setParameter('eventId', $eventId)
            ->setParameter('ended', 'ended')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getSingleScalarResult();

        return $this->json([
            'eventId'     => $eventId,
            'activeCount' => (int) $count,
            'asOf'        => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);
    }

    /**
     * GET /sessions/{sessionId}
     *
     * Returns session details: current state, duration so far, and event history.
     */
    #[Route('/sessions/{sessionId}', methods: ['GET'])]
    public function sessionDetail(string $sessionId): JsonResponse
    {
        $session = $this->em->find(Session::class, $sessionId);

        if ($session === null) {
            return $this->json(['error' => 'Session not found'], Response::HTTP_NOT_FOUND);
        }

        $now = new \DateTimeImmutable();
        $durationSeconds = $now->getTimestamp() - $session->getStartedAt()->getTimestamp();

        $events = array_map(
            fn (Event $e) => [
                'eventId'        => $e->getEventId(),
                'eventType'      => $e->getEventType(),
                'eventTimestamp' => $e->getEventTimestamp()->format(\DateTimeInterface::ATOM),
                'receivedAt'     => $e->getReceivedAt()->format(\DateTimeInterface::ATOM),
                'payload'        => $e->getPayload(),
            ],
            $session->getEvents()->toArray(),
        );

        return $this->json([
            'sessionId'       => $session->getSessionId(),
            'userId'          => $session->getUserId(),
            'streamEventId'   => $session->getStreamEventId(),
            'state'           => $session->getState(),
            'startedAt'       => $session->getStartedAt()->format(\DateTimeInterface::ATOM),
            'lastEventAt'     => $session->getLastEventAt()->format(\DateTimeInterface::ATOM),
            'durationSeconds' => $durationSeconds,
            'lastPosition'    => $session->getLastPosition(),
            'quality'         => $session->getQuality(),
            'events'          => $events,
        ]);
    }
}
