<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Which social providers are actually usable in this installation.
 *
 * The three providers are always wired in config/packages/knpu_oauth2_client.yaml, but a
 * provider with no client id would send visitors to a broken consent screen — so the login
 * page and the connect controller both ask this registry first, and a deployment enables a
 * provider simply by filling in its two env vars.
 */
class SocialLoginRegistry
{
    /** @var array<string, array{label: string, scopes: list<string>, icon: string}> */
    private const PROVIDERS = [
        'google' => ['label' => 'Google', 'scopes' => ['email', 'profile'], 'icon' => 'google'],
        'github' => ['label' => 'GitHub', 'scopes' => ['read:user', 'user:email'], 'icon' => 'github'],
        'facebook' => ['label' => 'Facebook', 'scopes' => ['email', 'public_profile'], 'icon' => 'facebook'],
    ];

    /** @var array<string, array{id: string, secret: string}> */
    private readonly array $credentials;

    public function __construct(
        #[Autowire(env: 'OAUTH_GOOGLE_ID')] string $googleId,
        #[Autowire(env: 'OAUTH_GOOGLE_SECRET')] string $googleSecret,
        #[Autowire(env: 'OAUTH_GITHUB_ID')] string $githubId,
        #[Autowire(env: 'OAUTH_GITHUB_SECRET')] string $githubSecret,
        #[Autowire(env: 'OAUTH_FACEBOOK_ID')] string $facebookId,
        #[Autowire(env: 'OAUTH_FACEBOOK_SECRET')] string $facebookSecret,
    ) {
        $this->credentials = [
            'google' => ['id' => trim($googleId), 'secret' => trim($googleSecret)],
            'github' => ['id' => trim($githubId), 'secret' => trim($githubSecret)],
            'facebook' => ['id' => trim($facebookId), 'secret' => trim($facebookSecret)],
        ];
    }

    public function isKnown(string $service): bool
    {
        return \array_key_exists($service, self::PROVIDERS);
    }

    public function isEnabled(string $service): bool
    {
        $credentials = $this->credentials[$service] ?? null;

        return null !== $credentials && '' !== $credentials['id'] && '' !== $credentials['secret'];
    }

    /** @return list<string> */
    public function scopesFor(string $service): array
    {
        return self::PROVIDERS[$service]['scopes'] ?? [];
    }

    public function labelFor(string $service): string
    {
        return self::PROVIDERS[$service]['label'] ?? ucfirst($service);
    }

    /**
     * Providers the login page should offer, in a shape the template can loop over.
     *
     * @return list<array{key: string, label: string, icon: string}>
     */
    public function enabledProviders(): array
    {
        $enabled = [];
        foreach (self::PROVIDERS as $key => $provider) {
            if ($this->isEnabled($key)) {
                $enabled[] = ['key' => $key, 'label' => $provider['label'], 'icon' => $provider['icon']];
            }
        }

        return $enabled;
    }

    public function hasAnyEnabled(): bool
    {
        return [] !== $this->enabledProviders();
    }
}
