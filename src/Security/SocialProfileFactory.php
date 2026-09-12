<?php

declare(strict_types=1);

namespace App\Security;

use League\OAuth2\Client\Provider\FacebookUser;
use League\OAuth2\Client\Provider\GithubResourceOwner;
use League\OAuth2\Client\Provider\GoogleUser;
use League\OAuth2\Client\Provider\ResourceOwnerInterface;
use League\OAuth2\Client\Token\AccessToken;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Turns a provider-specific resource owner into a {@see SocialProfile}.
 */
class SocialProfileFactory
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function fromResourceOwner(string $provider, ResourceOwnerInterface $owner, AccessToken $token): SocialProfile
    {
        return match ($provider) {
            'google' => $this->fromGoogle($owner),
            'github' => $this->fromGithub($owner, $token),
            'facebook' => $this->fromFacebook($owner),
            default => $this->fromGenericOwner($provider, $owner),
        };
    }

    private function fromGoogle(ResourceOwnerInterface $owner): SocialProfile
    {
        if (!$owner instanceof GoogleUser) {
            return $this->fromGenericOwner('google', $owner);
        }

        return new SocialProfile(
            provider: 'google',
            providerUserId: (string) $owner->getId(),
            email: $owner->getEmail(),
            displayName: $this->firstNonEmpty($owner->getName(), $owner->getEmail(), 'Google user'),
            avatarUrl: $owner->getAvatar(),
        );
    }

    /**
     * GitHub only exposes an email in the profile payload when the member made it public,
     * so fall back to the dedicated emails endpoint (the `user:email` scope covers it) and
     * take the primary verified address.
     */
    private function fromGithub(ResourceOwnerInterface $owner, AccessToken $token): SocialProfile
    {
        if (!$owner instanceof GithubResourceOwner) {
            return $this->fromGenericOwner('github', $owner);
        }

        $email = $owner->getEmail();
        if (null === $email || '' === $email) {
            $email = $this->fetchGithubPrimaryEmail($token);
        }

        return new SocialProfile(
            provider: 'github',
            providerUserId: (string) $owner->getId(),
            email: $email,
            displayName: $this->firstNonEmpty($owner->getName(), $owner->getNickname(), $email, 'GitHub user'),
            avatarUrl: \is_string($owner->toArray()['avatar_url'] ?? null) ? $owner->toArray()['avatar_url'] : null,
        );
    }

    private function fromFacebook(ResourceOwnerInterface $owner): SocialProfile
    {
        if (!$owner instanceof FacebookUser) {
            return $this->fromGenericOwner('facebook', $owner);
        }

        return new SocialProfile(
            provider: 'facebook',
            providerUserId: (string) $owner->getId(),
            email: $owner->getEmail(),
            displayName: $this->firstNonEmpty($owner->getName(), $owner->getEmail(), 'Facebook user'),
            avatarUrl: $owner->isDefaultPicture() ? null : $owner->getPictureUrl(),
        );
    }

    /** Best-effort reading of an unknown provider's payload, using the usual key names. */
    private function fromGenericOwner(string $provider, ResourceOwnerInterface $owner): SocialProfile
    {
        $data = $owner->toArray();
        $email = \is_string($data['email'] ?? null) ? $data['email'] : null;

        return new SocialProfile(
            provider: $provider,
            providerUserId: (string) $owner->getId(),
            email: $email,
            displayName: $this->firstNonEmpty(
                \is_string($data['name'] ?? null) ? $data['name'] : null,
                $email,
                ucfirst($provider).' user',
            ),
            avatarUrl: \is_string($data['picture'] ?? null) ? $data['picture'] : null,
        );
    }

    private function fetchGithubPrimaryEmail(AccessToken $token): ?string
    {
        try {
            $rows = $this->httpClient->request('GET', 'https://api.github.com/user/emails', [
                'headers' => [
                    'Authorization' => 'Bearer '.$token->getToken(),
                    'Accept' => 'application/vnd.github+json',
                    'User-Agent' => 'IdeaStorm',
                ],
            ])->toArray(false);
        } catch (HttpExceptionInterface $e) {
            $this->logger->warning('Could not read the GitHub email list: {message}', ['message' => $e->getMessage()]);

            return null;
        }

        $fallback = null;
        foreach ($rows as $row) {
            if (!\is_array($row) || !\is_string($row['email'] ?? null)) {
                continue;
            }
            if (true === ($row['primary'] ?? false) && true === ($row['verified'] ?? false)) {
                return $row['email'];
            }
            if (true === ($row['verified'] ?? false)) {
                $fallback ??= $row['email'];
            }
        }

        return $fallback;
    }

    private function firstNonEmpty(?string ...$candidates): string
    {
        foreach ($candidates as $candidate) {
            if (null !== $candidate && '' !== trim($candidate)) {
                return trim($candidate);
            }
        }

        return 'Anonymous';
    }
}
