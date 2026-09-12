<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * CSRF protection for the AJAX API.
 *
 * The frontend authenticates with a session cookie, so a form on another site could
 * otherwise make a visitor's browser POST here. Every state-changing /api request must
 * therefore carry the token the page was rendered with, in an X-CSRF-Token header (which a
 * cross-origin form cannot set). Safe methods are untouched, and so is the login form,
 * which has the firewall's own CSRF check.
 */
class ApiCsrfSubscriber implements EventSubscriberInterface
{
    public const TOKEN_ID = 'ideastorm_api';
    public const HEADER = 'X-CSRF-Token';

    public function __construct(private readonly CsrfTokenManagerInterface $csrfTokenManager)
    {
    }

    /** @return array<string, array{0: string, 1: int}> */
    public static function getSubscribedEvents(): array
    {
        // Runs after the firewall (priority 8) so that the user is already resolved, but
        // before the controller is called.
        return [KernelEvents::REQUEST => ['onKernelRequest', 7]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!$this->needsCheck($request)) {
            return;
        }

        $submitted = $request->headers->get(self::HEADER) ?? '';
        if ('' !== $submitted && $this->csrfTokenManager->isTokenValid(new CsrfToken(self::TOKEN_ID, $submitted))) {
            return;
        }

        $event->setResponse(new JsonResponse([
            'error' => 'invalid_csrf_token',
            'message' => 'Your session token has expired. Please reload the page and try again.',
        ], Response::HTTP_FORBIDDEN));
    }

    private function needsCheck(Request $request): bool
    {
        return str_starts_with($request->getPathInfo(), '/api/')
            && !\in_array($request->getMethod(), [Request::METHOD_GET, Request::METHOD_HEAD, Request::METHOD_OPTIONS], true);
    }
}
