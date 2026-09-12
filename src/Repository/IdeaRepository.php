<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Idea;
use App\Entity\Project;
use App\Enum\IdeaStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Idea> */
class IdeaRepository extends ServiceEntityRepository
{
    public const SORT_RECENT = 'recent';
    public const SORT_TOP = 'top';
    public const SORT_DISCUSSED = 'discussed';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Idea::class);
    }

    /**
     * Top-level ideas of a project (versions are loaded with their parent, not listed
     * alongside it), with authors, attachments and versions preloaded so that rendering
     * the board does not fan out into per-idea queries.
     *
     * @return list<Idea>
     */
    public function findBoardForProject(Project $project, ?IdeaStatus $status = null, ?string $search = null): array
    {
        $qb = $this->createQueryBuilder('i')
            ->addSelect('author', 'attachments', 'children', 'childAuthor', 'childAttachments')
            ->innerJoin('i.author', 'author')
            ->leftJoin('i.attachments', 'attachments')
            ->leftJoin('i.children', 'children')
            ->leftJoin('children.author', 'childAuthor')
            ->leftJoin('children.attachments', 'childAttachments')
            ->andWhere('i.project = :project')
            ->andWhere('i.parent IS NULL')
            ->setParameter('project', $project)
            ->orderBy('i.createdAt', 'DESC');

        if (null !== $status) {
            $qb->andWhere('i.status = :status')->setParameter('status', $status);
        }

        $search = null !== $search ? trim($search) : '';
        if ('' !== $search) {
            $qb->andWhere('LOWER(i.title) LIKE :q OR LOWER(i.body) LIKE :q')
                ->setParameter('q', '%'.strtolower($search).'%');
        }

        return $qb->getQuery()->getResult();
    }

    /** Single idea with everything the detail view needs. */
    public function findOneWithDetails(int $id): ?Idea
    {
        return $this->createQueryBuilder('i')
            ->addSelect('author', 'project', 'attachments', 'children', 'childAuthor')
            ->innerJoin('i.author', 'author')
            ->innerJoin('i.project', 'project')
            ->leftJoin('i.attachments', 'attachments')
            ->leftJoin('i.children', 'children')
            ->leftJoin('children.author', 'childAuthor')
            ->andWhere('i.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * How many ideas a project holds per status, for the board's filter chips.
     *
     * @return array<string, int>
     */
    public function countByStatus(Project $project): array
    {
        $rows = $this->createQueryBuilder('i')
            ->select('i.status AS status, COUNT(i.id) AS total')
            ->andWhere('i.project = :project')
            ->andWhere('i.parent IS NULL')
            ->setParameter('project', $project)
            ->groupBy('i.status')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $status = $row['status'];
            $counts[$status instanceof IdeaStatus ? $status->value : (string) $status] = (int) $row['total'];
        }

        return $counts;
    }
}
