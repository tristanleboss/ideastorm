<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\SocialIdentity;
use App\Entity\User;
use App\Repository\SocialIdentityRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Finds — or creates — the local account behind a social profile.
 *
 * Resolution happens in three steps:
 *   1. an existing link for this provider + provider user id wins outright;
 *   2. otherwise an account with the same email address adopts the new link, so signing in
 *      with GitHub after registering by email lands on the same account rather than a twin;
 *   3. failing both, a fresh passwordless account is created.
 */
class SocialAccountLinker
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $users,
        private readonly SocialIdentityRepository $identities,
    ) {
    }

    public function resolve(SocialProfile $profile): User
    {
        $identity = $this->identities->findOneByProvider($profile->provider, $profile->providerUserId);
        if (null !== $identity && null !== $identity->getUser()) {
            return $this->refresh($identity->getUser(), $profile);
        }

        $user = null !== $profile->email ? $this->users->findOneByEmail($profile->email) : null;
        if (null === $user) {
            $user = new User();
            $user->setEmail($profile->email ?? $this->placeholderEmail($profile));
            $user->setDisplayName($profile->displayName);
            $user->setAvatarUrl($profile->avatarUrl);
            $this->entityManager->persist($user);
        }

        $user->addSocialIdentity(new SocialIdentity($profile->provider, $profile->providerUserId));
        $this->refresh($user, $profile);

        $this->entityManager->flush();

        return $user;
    }

    /** Keeps the avatar in sync, and fills in a display name if the account never had one. */
    private function refresh(User $user, SocialProfile $profile): User
    {
        if (null !== $profile->avatarUrl && $profile->avatarUrl !== $user->getAvatarUrl()) {
            $user->setAvatarUrl($profile->avatarUrl);
        }

        if ('' === $user->getDisplayName()) {
            $user->setDisplayName($profile->displayName);
        }

        $this->entityManager->flush();

        return $user;
    }

    /**
     * Some providers can complete a sign-in without handing over an email address (a GitHub
     * member with no verified address, for instance). Rather than reject the sign-in, park
     * the account on a non-routable address the person can replace later.
     */
    private function placeholderEmail(SocialProfile $profile): string
    {
        return \sprintf('%s-%s@users.noreply.ideastorm.local', $profile->provider, $profile->providerUserId);
    }
}
