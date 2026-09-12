<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Shared plumbing for the AJAX endpoints: reading a JSON or multipart body, and answering
 * errors in the one shape the frontend knows how to display.
 */
abstract class AbstractApiController extends AbstractController
{
    /**
     * Request body as an array, whether it arrived as JSON or as form fields. Idea
     * submission posts multipart/form-data (it carries files), everything else posts JSON.
     *
     * @return array<string, mixed>
     */
    protected function payload(Request $request): array
    {
        if (str_contains((string) $request->headers->get('Content-Type'), 'application/json')) {
            $content = $request->getContent();
            if ('' === $content) {
                return [];
            }

            try {
                $decoded = json_decode($content, true, 32, \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return [];
            }

            return \is_array($decoded) ? $decoded : [];
        }

        return $request->request->all();
    }

    protected function stringField(array $payload, string $key, ?string $default = null): ?string
    {
        $value = $payload[$key] ?? null;
        if (!\is_string($value)) {
            return \is_int($value) || \is_float($value) ? (string) $value : $default;
        }

        $value = trim($value);

        return '' !== $value ? $value : $default;
    }

    protected function intField(array $payload, string $key): ?int
    {
        $value = $payload[$key] ?? null;
        if (\is_int($value)) {
            return $value;
        }

        return \is_string($value) && '' !== $value && is_numeric($value) ? (int) $value : null;
    }

    protected function currentUser(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }

    /** The signed-in user, or a 401-style exception if the firewall somehow let a guest in. */
    protected function requireUser(): User
    {
        $user = $this->currentUser();
        if (null === $user) {
            throw $this->createAccessDeniedException('You need an account to do that.');
        }

        return $user;
    }

    /** @param array<string, mixed> $extra */
    protected function error(string $message, int $status = Response::HTTP_BAD_REQUEST, string $code = 'bad_request', array $extra = []): JsonResponse
    {
        return $this->json(array_merge(['error' => $code, 'message' => $message], $extra), $status);
    }

    /** Field-level validation failures, so the UI can put each message next to its input. */
    protected function validationError(ConstraintViolationListInterface $violations): JsonResponse
    {
        $fields = [];
        foreach ($violations as $violation) {
            $fields[$violation->getPropertyPath() ?: '_'][] = $violation->getMessage();
        }

        return $this->error(
            'Please fix the highlighted fields.',
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'validation_failed',
            ['fields' => $fields],
        );
    }
}
