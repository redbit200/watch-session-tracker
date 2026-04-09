<?php

namespace App\Tests\Unit;

use App\Dto\IncomingEvent;
use App\Entity\Event;
use App\Entity\Session;
use App\Service\SessionTracker;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
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

        // EM returns our pre-built session so we can inspect its state after handle()
        $this->em->method('find')->willReturn($session);
        $this->em->expects($this->once())->method('flush');

        $dto = $this->makeDto($eventType);
        $this->tracker->handle($dto);

        $this->assertSame($expectedState, $session->getState());
    }

    public static function stateTransitionProvider(): array
    {
        return [
            'start sets playing'         => ['start',        'playing'],
            'resume sets playing'        => ['resume',       'playing'],
            'pause sets paused'          => ['pause',        'paused'],
            'buffer_start sets buffering'=> ['buffer_start', 'buffering'],
            'buffer_end sets playing'    => ['buffer_end',   'playing'],
            'end sets ended'             => ['end',          'ended'],
        ];
    }

    public function testHeartbeatDoesNotChangeState(): void
    {
        $session = $this->makeSession();
        $session->setState('paused'); // already paused

        $this->em->method('find')->willReturn($session);
        $this->em->expects($this->once())->method('flush');

        $this->tracker->handle($this->makeDto('heartbeat'));

        // Heartbeat should NOT change state
        $this->assertSame('paused', $session->getState());
    }

    public function testHeartbeatUpdatesLastEventAt(): void
    {
        $session = $this->makeSession();
        $original = $session->getLastEventAt();

        $this->em->method('find')->willReturn($session);
        $this->em->expects($this->once())->method('flush');

        // Use a timestamp one minute in the future
        $dto = $this->makeDto('heartbeat', eventTimestamp: '2026-01-01T00:01:00Z');
        $this->tracker->handle($dto);

        $this->assertGreaterThan($original, $session->getLastEventAt());
    }

    public function testNewSessionCreatedWhenNotFound(): void
    {
        // EM returns null → no existing session
        $this->em->method('find')->willReturn(null);
        // persist is called twice: once for the new Session, once for the Event
        $this->em->expects($this->exactly(2))->method('persist');
        $this->em->expects($this->once())->method('flush');

        $result = $this->tracker->handle($this->makeDto('start'));

        $this->assertFalse($result); // not a duplicate
    }

    public function testDuplicateEventReturnsTrueAndDoesNotThrow(): void
    {
        $session = $this->makeSession();
        $this->em->method('find')->willReturn($session);

        // flush() throws on the UNIQUE constraint violation
        $this->em->method('flush')->willThrowException(
            $this->createMock(UniqueConstraintViolationException::class)
        );
        $this->em->expects($this->once())->method('clear');

        $result = $this->tracker->handle($this->makeDto('heartbeat'));

        $this->assertTrue($result); // recognized as duplicate
    }

    public function testPositionIsUpdatedOnTouch(): void
    {
        $session = $this->makeSession();
        $this->em->method('find')->willReturn($session);
        $this->em->expects($this->once())->method('flush');

        $this->tracker->handle($this->makeDto('heartbeat', position: 999.5));

        $this->assertSame(999.5, $session->getLastPosition());
    }

    // ─── helpers ────────────────────────────────────────────────────────────────

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
