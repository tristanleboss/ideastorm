<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * Keeps the AJAX API speaking JSON even when something goes wrong: without this, an
 * unauthenticated POST would answer with a redirect to the HTML login page and the frontend
 * would have no idea why its request "succeeded".
 */
class ApiExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly bool $debug = false,
    ) {
    }

    /** @return array<string, array{0: string, 1: int}> */
    public static function getSubscribedEvents(): array
    {
        // Ahead of the security listener (priority 1) so authentication failures on /api
        // become 401 JSON instead of a redirect.
        return [KernelEvents::EXCEPTION => ['onKernelException', 10]];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }

        $throwable = $event->getThrowable();

        [$status, $error, $message] = match (true) {
            // A guest hitting a members-only endpoint is a 401 ("sign in"), not a 403
            // ("you may not"): the UI shows a sign-in prompt for the former.
            $throwable instanceof AccessDeniedException && null === $this->security->getUser() => [
                Response::HTTP_UNAUTHORIZED,
                'unauthenticated',
                'Please sign in to continue.',
            ],
            // Deliberately generic: the access decision's own wording names roles and voters,
            // which is useful in a log and meaningless to the person reading the screen.
            $throwable instanceof AccessDeniedException => [
                Response::HTTP_FORBIDDEN,
                'forbidden',
                'You are not allowed to do that.',
            ],
            $throwable instanceof AuthenticationException => [
                Response::HTTP_UNAUTHORIZED,
                'unauthenticated',
                'Please sign in to continue.',
            ],
            $throwable instanceof HttpExceptionInterface => [
                $throwable->getStatusCode(),
                'http_error',
                $throwable->getMessage(),
            ],
            default => [
                Response::HTTP_INTERNAL_SERVER_ERROR,
                'server_error',
                'Something went wrong on our side.',
            ],
        };

        $payload = ['error' => $error, 'message' => $message];
        if ($this->debug && Response::HTTP_INTERNAL_SERVER_ERROR === $status) {
            $payload['exception'] = $throwable::class;
            $payload['detail'] = $throwable->getMessage();
        }

        $event->setResponse(new JsonResponse($payload, $status));
    }
}
