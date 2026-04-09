<?php

namespace App\Tests\Unit;

use App\Dto\IncomingEvent;
use App\Entity\Event;
use App\Entity\Session;
use App\Service\SessionTracker;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SessionTracker's state machine logic.
 *
 * We mock the EntityManager so these run without a database. We care about:
 *   - Correct state transitions for each event type
 *   - No state change for event types that don't trigger transitions
 *   - Graceful handling of duplicate events (idempotency)
 *   - Session creation when the first event arrives
 */
class SessionTrackerTest extends TestCase
{
    private EntityManagerInterface $em;
    private SessionTracker $tracker;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->tracker = new SessionTracker($this->em);
    }

    /** @dataProvider stateTransitionProvider */
    public function testStateTransitions(string $eventType, string $expectedState): void
    {
        $session = $this->makeSession();
        $this->em->method('find')->willReturn($session);
        $this->em->expects($this->once())->method('flush');
        // findOneBy returns null by default (PHPUnit mock default for nullable)
        $this->mockEventRepo(existingEvent: null);

        $this->tracker->handle($this->makeDto($eventType));

        $this->assertSame($expectedState, $session->getState());
    }

    public static function stateTransitionProvider(): array
    {
        return [
            'start sets playing'          => ['start',        'playing'],
            'resume sets playing'         => ['resume',       'playing'],
            'pause sets paused'           => ['pause',        'paused'],
            'buffer_start sets buffering' => ['buffer_start', 'buffering'],
            'buffer_end sets playing'     => ['buffer_end',   'playing'],
            'end sets ended'              => ['end',          'ended'],
        ];
    }

    public function testHeartbeatDoesNotChangeState(): void
    {
        $session = $this->makeSession();
        $session->setState('paused'); // already paused

        $this->em->method('find')->willReturn($session);
        $this->em->expects($this->once())->method('flush');
        $this->mockEventRepo(existingEvent: null);

        $this->tracker->handle($this->makeDto('heartbeat'));

        $this->assertSame('paused', $session->getState()); // heartbeat must not change state
    }

    public function testHeartbeatUpdatesLastEventAt(): void
    {
        $session = $this->makeSession();
        $original = $session->getLastEventAt();

        $this->em->method('find')->willReturn($session);
        $this->em->expects($this->once())->method('flush');
        $this->mockEventRepo(existingEvent: null);

        $dto = $this->makeDto('heartbeat', eventTimestamp: '2026-01-01T00:01:00Z');
        $this->tracker->handle($dto);

        $this->assertGreaterThan($original, $session->getLastEventAt());
    }

    public function testNewSessionCreatedWhenNotFound(): void
    {
        $this->em->method('find')->willReturn(null); // no existing session
        $this->em->expects($this->exactly(2))->method('persist'); // Session + Event
        $this->em->expects($this->once())->method('flush');
        $this->mockEventRepo(existingEvent: null);

        $result = $this->tracker->handle($this->makeDto('start'));

        $this->assertFalse($result); // not a duplicate
    }

    public function testDuplicateEventReturnsTrueAndDoesNotFlush(): void
    {
        $session = $this->makeSession();
        $this->em->method('find')->willReturn($session);

        // The repo says this eventId is already stored → duplicate
        $existingEvent = $this->createMock(Event::class);
        $this->mockEventRepo(existingEvent: $existingEvent);

        $this->em->expects($this->never())->method('flush');

        $result = $this->tracker->handle($this->makeDto('heartbeat'));

        $this->assertTrue($result);
    }

    public function testPositionIsUpdatedOnTouch(): void
    {
        $session = $this->makeSession();
        $this->em->method('find')->willReturn($session);
        $this->em->expects($this->once())->method('flush');
        $this->mockEventRepo(existingEvent: null);

        $this->tracker->handle($this->makeDto('heartbeat', position: 999.5));

        $this->assertSame(999.5, $session->getLastPosition());
    }

    // ─── helpers ────────────────────────────────────────────────────────────────

    private function mockEventRepo(mixed $existingEvent): void
    {
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findOneBy')->willReturn($existingEvent);
        $this->em->method('getRepository')->willReturn($repo);
    }

    private function makeSession(): Session
    {
        return new Session(
            sessionId: 'sess-1',
            userId: 'user-1',
            streamEventId: 'event-wrestling',
            startedAt: new \DateTimeImmutable('2026-01-01T00:00:00Z'),
        );
    }

    private function makeDto(
        string $eventType,
        string $eventTimestamp = '2026-01-01T00:00:30Z',
        ?float $position = null,
    ): IncomingEvent {
        return new IncomingEvent(
            sessionId: 'sess-1',
            userId: 'user-1',
            eventType: $eventType,
            eventId: 'evt-' . uniqid(),
            eventTimestamp: $eventTimestamp,
            receivedAt: $eventTimestamp,
            streamEventId: 'event-wrestling',
            position: $position,
        );
    }
}
