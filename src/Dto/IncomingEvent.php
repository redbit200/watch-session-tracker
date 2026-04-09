<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Typed representation of the SDK event payload.
 *
 * Keeping this as a plain object (not a Symfony Form) so it's easy to
 * hydrate from JSON and unit-test without a container.
 */
final class IncomingEvent
{
    public const VALID_TYPES = [
        'start', 'heartbeat', 'pause', 'resume',
        'seek', 'quality_change', 'buffer_start', 'buffer_end', 'end',
    ];

    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public readonly string $sessionId,

        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public readonly string $userId,

        #[Assert\NotBlank]
        #[Assert\Choice(choices: self::VALID_TYPES)]
        public readonly string $eventType,

        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public readonly string $eventId,

        #[Assert\NotBlank]
        public readonly string $eventTimestamp,

        #[Assert\NotBlank]
        public readonly string $receivedAt,

        /**
         * The "payload.eventId" from the SDK — this is the stream/match identifier,
         * distinct from the top-level eventId which identifies the individual SDK event.
         */
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public readonly string $streamEventId,

        public readonly ?float $position = null,
        public readonly ?string $quality = null,
    ) {
    }

    /**
     * Hydrate from raw decoded JSON. Returns null if required keys are missing.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $payload = $data['payload'] ?? [];

        return new self(
            sessionId: $data['sessionId'] ?? '',
            userId: $data['userId'] ?? '',
            eventType: $data['eventType'] ?? '',
            eventId: $data['eventId'] ?? '',
            eventTimestamp: $data['eventTimestamp'] ?? '',
            receivedAt: $data['receivedAt'] ?? '',
            streamEventId: $payload['eventId'] ?? '',
            position: isset($payload['position']) ? (float) $payload['position'] : null,
            quality: $payload['quality'] ?? null,
        );
    }
}
