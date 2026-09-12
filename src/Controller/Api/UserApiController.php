<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\ApiPresenter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Account lookup for the "assign somebody to this project" picker. Behind ROLE_USER (see
 * config/packages/security.yaml) so the member directory is not a public mailing list.
 */
#[Route('/api/users', name: 'api_user_')]
class UserApiController extends AbstractApiController
{
    #[Route('', name: 'search', methods: ['GET'])]
    public function search(Request $request, UserRepository $users, ApiPresenter $presenter): JsonResponse
    {
        $this->requireUser();

        $results = $users->search($request->query->getString('q'), 15);

        return $this->json([
            'users' => array_map(
                static fn (User $user): array => [
                    'id' => $user->getId(),
                    'displayName' => $user->getDisplayName(),
                    'email' => $user->getEmail(),
                    'initials' => $user->getInitials(),
                    'avatarUrl' => $user->getAvatarUrl(),
                ],
                $results,
            ),
        ]);
    }
}
