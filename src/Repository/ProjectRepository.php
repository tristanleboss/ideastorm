<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Project> */
class ProjectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Project::class);
    }

    public function findOneBySlug(string $slug): ?Project
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    /**
     * Project list for the landing page, optionally narrowed by a free-text search.
     *
     * @return list<Project>
     */
    public function findForListing(?string $search = null): array
    {
        $qb = $this->createQueryBuilder('p')
            ->orderBy('p.updatedAt', 'DESC');

        $search = null !== $search ? trim($search) : '';
        if ('' !== $search) {
            $qb->andWhere('LOWER(p.name) LIKE :q OR LOWER(p.summary) LIKE :q OR LOWER(p.description) LIKE :q')
                ->setParameter('q', '%'.strtolower($search).'%');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Idea and member counts for every project, in two grouped queries rather than one
     * per project. Shaped as [projectId => ['ideas' => int, 'members' => int]].
     *
     * Versions are not counted as separate ideas: a project with three ideas, one of which
     * has two alternative takes, reads as "3 ideas" everywhere in the UI.
     *
     * @return array<int, array{ideas: int, members: int}>
     */
    public function countsByProject(): array
    {
        $counts = [];

        $ideaRows = $this->getEntityManager()->createQuery(
            'SELECT p.id AS pid, COUNT(i.id) AS total FROM App\Entity\Idea i JOIN i.project p WHERE i.parent IS NULL GROUP BY p.id'
        )->getArrayResult();
        foreach ($ideaRows as $row) {
            $counts[(int) $row['pid']]['ideas'] = (int) $row['total'];
        }

        $memberRows = $this->getEntityManager()->createQuery(
            'SELECT p.id AS pid, COUNT(m.id) AS total FROM App\Entity\ProjectMembership m JOIN m.project p GROUP BY p.id'
        )->getArrayResult();
        foreach ($memberRows as $row) {
            $counts[(int) $row['pid']]['members'] = (int) $row['total'];
        }

        foreach ($counts as $id => $row) {
            $counts[$id] = ['ideas' => $row['ideas'] ?? 0, 'members' => $row['members'] ?? 0];
        }

        return $counts;
    }
}
