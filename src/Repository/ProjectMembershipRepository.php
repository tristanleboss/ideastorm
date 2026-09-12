<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Project;
use App\Entity\ProjectMembership;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ProjectMembership> */
class ProjectMembershipRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProjectMembership::class);
    }

    public function findOneForProjectAndUser(Project $project, User $user): ?ProjectMembership
    {
        return $this->findOneBy(['project' => $project, 'user' => $user]);
    }

    /**
     * All members of a project with their user preloaded, ordered so that owners and leads
     * come first and everyone else is alphabetical.
     *
     * @return list<ProjectMembership>
     */
    public function findForProject(Project $project): array
    {
        /** @var list<ProjectMembership> $memberships */
        $memberships = $this->createQueryBuilder('m')
            ->addSelect('u')
            ->innerJoin('m.user', 'u')
            ->andWhere('m.project = :project')
            ->setParameter('project', $project)
            ->getQuery()
            ->getResult();

        usort($memberships, static function (ProjectMembership $a, ProjectMembership $b): int {
            $order = ['owner' => 0, 'lead' => 1, 'contributor' => 2, 'reviewer' => 3, 'observer' => 4];
            $byRole = ($order[$a->getRole()->value] ?? 9) <=> ($order[$b->getRole()->value] ?? 9);

            return 0 !== $byRole
                ? $byRole
                : strcasecmp($a->getUser()?->getDisplayName() ?? '', $b->getUser()?->getDisplayName() ?? '');
        });

        return $memberships;
    }
}
