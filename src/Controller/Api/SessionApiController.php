<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\EventSubscriber\ApiCsrfSubscriber;
use App\Service\ApiPresenter;
use App\Service\AttachmentStorage;
use App\Service\SocialLoginRegistry;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Everything the frontend needs on boot: who is signed in, a fresh CSRF token, the enum
 * vocabulary used by pickers, and the upload limits the composer must enforce client-side.
 */
#[Route('/api/session', name: 'api_session_')]
class SessionApiController extends AbstractApiController
{
    #[Route('', name: 'show', methods: ['GET'])]
    public function show(
        ApiPresenter $presenter,
        CsrfTokenManagerInterface $csrfTokenManager,
        SocialLoginRegistry $socialLogins,
        AttachmentStorage $storage,
    ): JsonResponse {
        return $this->json([
            'user' => $presenter->viewer($this->currentUser()),
            'csrfToken' => $csrfTokenManager->getToken(ApiCsrfSubscriber::TOKEN_ID)->getValue(),
            'socialProviders' => $socialLogins->enabledProviders(),
            'vocabulary' => $presenter->vocabulary(),
            'uploads' => [
                'maxSize' => $storage->getMaxUploadSize(),
                'maxSizeLabel' => AttachmentStorage::formatBytes($storage->getMaxUploadSize()),
                'acceptedMimeTypes' => $storage->getAllowedMimeTypes(),
            ],
        ]);
    }
}
