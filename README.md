# Watch Session Tracker

A v1 real-time watch-session tracking service for FloSports. Accepts player SDK events via REST, tracks active viewer sessions, and exposes query endpoints for live viewer counts and session detail.

---

## Quick Start

```bash
# Start the service
docker compose up --build

# Service is available at http://localhost:8080
```

### Run Tests

```bash
# Inside the container
docker compose exec app php bin/phpunit

# Or locally (requires PHP 8.2+ and SQLite)
composer install
php bin/phpunit
```

---

## API

### Ingest an Event

```
POST /events
Content-Type: application/json

{
  "sessionId": "abc-123",
  "userId": "user-456",
  "eventType": "heartbeat",
  "eventId": "evt-789",
  "eventTimestamp": "2026-02-10T19:32:15.123Z",
  "receivedAt": "2026-02-10T19:32:15.450Z",
  "payload": {
    "eventId": "event-2026-wrestling-finals",
    "position": 1832.5,
    "quality": "1080p"
  }
}
```

Valid `eventType` values: `start`, `heartbeat`, `pause`, `resume`, `seek`, `quality_change`, `buffer_start`, `buffer_end`, `end`.

**Responses:** `202 Accepted` (new event), `200 OK` (recognized duplicate), `422` (validation error).

### Current Viewer Count

```
GET /events/{eventId}/active-count
```

Returns the number of sessions actively watching the given stream event. A session is "active" if its state is not `ended` and it has received an event within the last 90 seconds (3× the 30-second heartbeat interval — tolerates one missed beat).

```json
{
  "eventId": "event-2026-wrestling-finals",
  "activeCount": 1247,
  "asOf": "2026-02-10T19:32:16+00:00"
}
```

### Session Detail

```
GET /sessions/{sessionId}
```

Returns current state, duration since start, and full event history for the session.

```json
{
  "sessionId": "abc-123",
  "userId": "user-456",
  "streamEventId": "event-2026-wrestling-finals",
  "state": "playing",
  "startedAt": "2026-02-10T19:30:00+00:00",
  "lastEventAt": "2026-02-10T19:32:15+00:00",
  "durationSeconds": 135,
  "lastPosition": 1832.5,
  "quality": "1080p",
  "events": [...]
}
```

---

## Architecture

### Storage: SQLite via Doctrine ORM

PHP/Symfony is request-scoped (PHP-FPM), so in-process state doesn't persist across requests. SQLite was chosen over Redis because:

- Zero extra services — one container, single file, ships with PHP.
- Plenty fast for a PoC dashboard refresh cadence.
- Doctrine ORM is already first-class in Symfony.

**In production** I'd swap this for PostgreSQL (or Aurora Serverless) behind the ingest path, and a Redis `INCR`/`EXPIRE` key for the real-time viewer count. The `GET /events/{id}/active-count` response time is dominated by the COUNT query — acceptable for v1, but a Redis counter would drop it from ~1ms to ~100µs and scale horizontally.

### Data Model

Two tables:

| Table | Purpose |
|-------|---------|
| `sessions` | Materialized rollup — updated on every event. Cheap to query. |
| `events` | Append-only event history. UNIQUE index on `event_id` provides idempotency. |

### Session State Machine

```
start / resume  →  playing
pause           →  paused
buffer_start    →  buffering
buffer_end      →  playing
end             →  ended
heartbeat / seek / quality_change  →  (no state change, updates last_event_at)
```

### "Active" Definition

A session counts as active if:
1. Its state is not `ended`, AND
2. Its `last_event_at` is within the last **90 seconds**.

This is computed at query time with a SQL filter — no background sweeper process needed. The 90-second window is 3× the heartbeat interval, so a single missed heartbeat won't cause a session to disappear from the count. The trade-off is that a session that actually went dark (e.g., network drop without an `end` event) will linger for up to 90 seconds. For a dashboard that refreshes every 10-15 seconds, this is acceptable.

### Idempotency

The `event_id` field has a UNIQUE index. Before inserting a new event row, we check for an existing row with the same `event_id`. Duplicates (retries, double-delivery) return `200` without persisting anything. The UNIQUE index remains as a safety net for true concurrent races.

---

## Assumptions

- **Event ordering:** Events may arrive out of order (network jitter). The service handles this gracefully — if a non-`start` event arrives first, we create the session anyway rather than dropping it.
- **Session identity:** `sessionId` is stable per playback session (not per tab reload). Multiple tabs on the same `userId` would have separate `sessionId` values.
- **`eventId` vs `payload.eventId`:** The top-level `eventId` uniquely identifies the SDK event (idempotency key). The `payload.eventId` identifies the stream/match being watched. These are intentionally separate.
- **Clock skew:** We use `eventTimestamp` (SDK client time) as the canonical session timestamp, not `receivedAt`. If I could ask the product team: *"Is client clock skew a concern? Should we use server receive time for the active-window calculation instead?"*
- **Single-process deploy:** SQLite doesn't support multiple concurrent writers. This is fine for v1 but rules out horizontal scaling without swapping the storage layer.
- **No auth:** The API has no authentication or rate limiting. Trivial to add as Symfony middleware, but out of scope for v1.

---

## Trade-offs

| Decision | What I prioritized | What I left out |
|----------|-------------------|-----------------|
| SQLite over Redis | Simplicity (zero extra services) | Production scalability — Redis `INCR/EXPIRE` would give sub-millisecond viewer counts that survive pod restarts |
| Query-time active filter | No background job to maintain | Sessions that drop without an `end` event linger for 90s |
| Pre-check SELECT for idempotency | Stable EM state after duplicate | A narrow race window where two concurrent requests with the same `eventId` could both pass the SELECT and hit the UNIQUE constraint; the second would throw a 500 in that edge case |
| Single event ingestion endpoint | Simplicity | Batch ingest (array of events) would help during traffic spikes that Operations flagged |
| File-based SQLite in tests | No extra infrastructure, reliable isolation | In-memory SQLite would be faster but doesn't survive Doctrine's per-request connection reset in WebTestCase |

**On the stakeholder tension:** Product wants real-time; Ops wants no lost events; Engineering wants simple. My navigation:
- *Real-time:* The 90-second active window + query-time COUNT is fast enough for a 10-15s dashboard. Not sub-second like a Redis SUBSCRIBE, but close enough for v1.
- *No lost events:* Idempotent writes mean retries are safe. No queue means no buffer between the SDK and the DB — spikes will hit SQLite directly, which is the fragile point. I called this out explicitly rather than adding a queue (which would solve it but violate "keep it simple").
- *Simple:* Two tables, one service class, no background workers.

---

## What I Would Do Differently in Production

1. **Replace SQLite with PostgreSQL** for horizontal write scaling.
2. **Add a Redis layer for the viewer count**: `INCR event:{id}:count` on `start`, `DECR` on `end` or timeout. This gives sub-millisecond reads and naturally handles the "stale session" problem via `EXPIRE`.
3. **Front the ingest endpoint with a queue** (SQS, Kafka, or even RabbitMQ): accept the event into the queue synchronously (durable write), process asynchronously. This directly addresses Operations' "no lost events during spikes" concern.
4. **Add structured logging and metrics** (e.g., Prometheus `watch_events_total` counter, `active_sessions` gauge). The dashboard team would want to alert on ingestion lag.
5. **Expire old sessions** via a scheduled job (Symfony Scheduler or a cron) rather than relying purely on the query-time window.

---

## Tools and Resources Used

- **Claude Code (claude-sonnet-4-6 / claude-opus-4-6 in plan mode)** — Used extensively for:
  - Initial architecture planning (storage choice, data model, state machine design)
  - Generating boilerplate (Symfony skeleton, Doctrine entities, migration)
  - Scaffolding tests (unit and functional)
  - Debugging Symfony WebTestCase / SQLite connection reset behavior
  - Drafting README sections

  I reviewed and corrected every generated file. Notably: the AI's first idempotency approach (catching `UniqueConstraintViolationException`) left the Doctrine EntityManager in a broken state after a failed transaction — a real-world bug that required understanding why in-memory SQLite connections reset between WebTestCase requests. The pre-check SELECT approach came from diagnosing that failure.

- **Symfony 7 Documentation** — routing, WebTestCase setup, Flex recipe behavior.
- **Doctrine ORM Documentation** — SchemaTool usage, entity mapping with SQLite.
- **PHP 8.3 built-in web server** — chosen over Nginx+PHP-FPM to keep the Dockerfile minimal for a PoC.
