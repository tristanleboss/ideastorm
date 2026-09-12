<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Comment;
use App\Entity\Idea;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Comment> */
class CommentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Comment::class);
    }

    /**
     * Every comment on an idea, oldest first, with authors preloaded. Replies come back in
     * the same flat list; the API nests them by parent id.
     *
     * @return list<Comment>
     */
    public function findForIdea(Idea $idea): array
    {
        return $this->createQueryBuilder('c')
            ->addSelect('author')
            ->innerJoin('c.author', 'author')
            ->andWhere('c.idea = :idea')
            ->setParameter('idea', $idea)
            ->orderBy('c.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Comment totals for a set of ideas, as [ideaId => count].
     *
     * @param list<int> $ideaIds
     *
     * @return array<int, int>
     */
    public function countForIdeas(array $ideaIds): array
    {
        if ([] === $ideaIds) {
            return [];
        }

        $rows = $this->createQueryBuilder('c')
            ->select('IDENTITY(c.idea) AS ideaId, COUNT(c.id) AS total')
            ->andWhere('c.idea IN (:ids)')
            ->setParameter('ids', $ideaIds)
            ->groupBy('c.idea')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['ideaId']] = (int) $row['total'];
        }

        return $counts;
    }
}
