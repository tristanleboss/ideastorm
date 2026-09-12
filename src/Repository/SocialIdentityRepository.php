<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SocialIdentity;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<SocialIdentity> */
class SocialIdentityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SocialIdentity::class);
    }

    public function findOneByProvider(string $provider, string $providerUserId): ?SocialIdentity
    {
        return $this->findOneBy(['provider' => $provider, 'providerUserId' => $providerUserId]);
    }
}
