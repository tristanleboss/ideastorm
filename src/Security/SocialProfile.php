<?php

declare(strict_types=1);

namespace App\Security;

/**
 * The handful of fields IdeaStorm needs from a social provider, normalised so that the rest
 * of the app never has to care whether the person signed in with Google, GitHub or Facebook.
 */
final readonly class SocialProfile
{
    public function __construct(
        public string $provider,
        public string $providerUserId,
        public ?string $email,
        public string $displayName,
        public ?string $avatarUrl = null,
    ) {
    }
}
