<?php

namespace App\Controller;

use App\Dto\IncomingEvent;
use App\Service\SessionTracker;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class EventIngestController extends AbstractController
{
    public function __construct(
        private readonly SessionTracker $tracker,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('/events', methods: ['POST'])]
    public function ingest(Request $request): JsonResponse
    {
        // Expect application/json body
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $dto = IncomingEvent::fromArray($data);

        $violations = $this->validator->validate($dto);
        if (count($violations) > 0) {
            $errors = [];
            foreach ($violations as $v) {
                $errors[$v->getPropertyPath()] = $v->getMessage();
            }
            return $this->json(['errors' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $duplicate = $this->tracker->handle($dto);

        // 200 for idempotent re-delivery, 202 for new events (accepted, not yet "processed")
        return $this->json(
            ['status' => $duplicate ? 'duplicate' : 'accepted'],
            $duplicate ? Response::HTTP_OK : Response::HTTP_ACCEPTED,
        );
    }
}
