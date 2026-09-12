<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/** @extends ServiceEntityRepository<User> */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findOneByEmail(string $email): ?User
    {
        return $this->findOneBy(['email' => strtolower(trim($email))]);
    }

    /**
     * Keeps stored hashes current when the hashing algorithm's cost changes.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(\sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    /**
     * Candidates for the "assign a member" picker: everyone whose name or email matches.
     *
     * @return list<User>
     */
    public function search(string $term, int $limit = 20): array
    {
        $qb = $this->createQueryBuilder('u')
            ->orderBy('u.displayName', 'ASC')
            ->setMaxResults($limit);

        $term = trim($term);
        if ('' !== $term) {
            $qb->andWhere('LOWER(u.displayName) LIKE :term OR LOWER(u.email) LIKE :term')
                ->setParameter('term', '%'.strtolower($term).'%');
        }

        return $qb->getQuery()->getResult();
    }
}
