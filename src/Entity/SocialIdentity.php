<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SocialIdentityRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Links a local account to an account at an OAuth provider. A user can collect several
 * of these, so signing in with Google and later with GitHub lands on the same account
 * as long as the provider reports the same verified email address.
 */
#[ORM\Entity(repositoryClass: SocialIdentityRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_social_provider_user', columns: ['provider', 'provider_user_id'])]
class SocialIdentity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'socialIdentities')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(length: 40)]
    private string $provider;

    #[ORM\Column(length: 191)]
    private string $providerUserId;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $provider, string $providerUserId)
    {
        $this->provider = $provider;
        $this->providerUserId = $providerUserId;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getProviderUserId(): string
    {
        return $this->providerUserId;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
