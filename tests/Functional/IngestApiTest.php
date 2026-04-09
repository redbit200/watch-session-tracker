<?php

namespace App\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * End-to-end smoke test: ingest events via HTTP, then verify the query endpoints.
 *
 * Uses Symfony's WebTestCase which boots the full kernel against the test
 * environment. doctrine.yaml in config/packages/test/ points to :memory: SQLite
 * so each test run starts with a clean database.
 *
 * We test the full HTTP flow rather than unit-testing controllers because the
 * interesting edge cases span multiple components: validation → tracker →
 * persistence → query. If any layer is misconfigured, these tests catch it.
 */
class IngestApiTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        // createClient() boots the kernel — must be called once per test.
        $this->client = static::createClient();

        $em = static::getContainer()->get(EntityManagerInterface::class);

        // Drop and recreate the schema for each test so they're fully isolated.
        // We use a file-based SQLite rather than :memory: because Doctrine resets
        // (re-opens) the connection between HTTP requests in WebTestCase, which
        // wipes an in-memory database.
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schemaTool = new SchemaTool($em);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testStartEventReturns202(): void
    {
        $this->post('/events', $this->startEvent());
        $this->assertResponseStatusCodeSame(202);
        $this->assertJsonContains('accepted');
    }

    public function testDuplicateEventReturns200(): void
    {
        $event = $this->startEvent();
        $this->post('/events', $event);
        $this->assertResponseStatusCodeSame(202);

        // Same eventId again — should be recognized as a duplicate
        $this->post('/events', $event);
        $this->assertResponseStatusCodeSame(200);
        $this->assertJsonContains('duplicate');
    }

    public function testActiveCountAfterStartEvent(): void
    {
        $this->post('/events', $this->startEvent());
        $this->assertResponseStatusCodeSame(202);

        $this->client->request('GET', '/events/event-2026-wrestling-finals/active-count');
        $this->assertResponseIsSuccessful();

        $data = $this->json();
        $this->assertSame(1, $data['activeCount']);
    }

    public function testActiveCountExcludesEndedSessions(): void
    {
        $this->post('/events', $this->startEvent());
        $this->post('/events', $this->makeEvent('end', 'evt-end-1'));

        $this->client->request('GET', '/events/event-2026-wrestling-finals/active-count');
        $data = $this->json();

        $this->assertSame(0, $data['activeCount']);
    }

    public function testSessionDetailReflectsStateTransitions(): void
    {
        $this->post('/events', $this->startEvent());
        $this->post('/events', $this->makeEvent('pause', 'evt-pause-1'));

        $this->client->request('GET', '/sessions/sess-abc-123');
        $this->assertResponseIsSuccessful();

        $data = $this->json();
        $this->assertSame('paused', $data['state']);
        $this->assertSame('sess-abc-123', $data['sessionId']);
        $this->assertCount(2, $data['events']);
    }

    public function testSessionDetailNotFound(): void
    {
        $this->client->request('GET', '/sessions/does-not-exist');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testIngestRejectsInvalidEventType(): void
    {
        $bad = $this->startEvent();
        $bad['eventType'] = 'explode';

        $this->post('/events', $bad);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testIngestRejectsMissingSessionId(): void
    {
        $bad = $this->startEvent();
        unset($bad['sessionId']);

        $this->post('/events', $bad);
        $this->assertResponseStatusCodeSame(422);
    }

    // ─── helpers ────────────────────────────────────────────────────────────────

    private function post(string $uri, array $body): void
    {
        $this->client->request(
            'POST', $uri,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($body),
        );
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true);
    }

    private function assertJsonContains(string $needle): void
    {
        $this->assertStringContainsString($needle, $this->client->getResponse()->getContent());
    }

    private function startEvent(): array
    {
        // Use current time so the session falls within the 90-second active window
        $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

        return [
            'sessionId'      => 'sess-abc-123',
            'userId'         => 'user-456',
            'eventType'      => 'start',
            'eventId'        => 'evt-start-001',
            'eventTimestamp' => $now,
            'receivedAt'     => $now,
            'payload'        => [
                'eventId'  => 'event-2026-wrestling-finals',
                'position' => 0.0,
                'quality'  => '1080p',
            ],
        ];
    }

    private function makeEvent(string $type, string $eventId): array
    {
        $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

        return [
            'sessionId'      => 'sess-abc-123',
            'userId'         => 'user-456',
            'eventType'      => $type,
            'eventId'        => $eventId,
            'eventTimestamp' => $now,
            'receivedAt'     => $now,
            'payload'        => [
                'eventId'  => 'event-2026-wrestling-finals',
                'position' => 60.0,
                'quality'  => '1080p',
            ],
        ];
    }
}
