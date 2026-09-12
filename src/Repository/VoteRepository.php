<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Idea;
use App\Entity\User;
use App\Entity\Vote;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Vote> */
class VoteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Vote::class);
    }

    public function findOneForIdeaAndUser(Idea $idea, User $user): ?Vote
    {
        return $this->findOneBy(['idea' => $idea, 'user' => $user]);
    }

    /**
     * Vote tallies for a set of ideas in one query, as
     * [ideaId => ['up' => int, 'down' => int, 'score' => int]].
     *
     * @param list<int> $ideaIds
     *
     * @return array<int, array{up: int, down: int, score: int}>
     */
    public function tallyForIdeas(array $ideaIds): array
    {
        if ([] === $ideaIds) {
            return [];
        }

        $rows = $this->createQueryBuilder('v')
            ->select('IDENTITY(v.idea) AS ideaId, v.value AS value, COUNT(v.id) AS total')
            ->andWhere('v.idea IN (:ids)')
            ->setParameter('ids', $ideaIds)
            ->groupBy('v.idea', 'v.value')
            ->getQuery()
            ->getArrayResult();

        $tallies = [];
        foreach ($rows as $row) {
            $ideaId = (int) $row['ideaId'];
            $tallies[$ideaId] ??= ['up' => 0, 'down' => 0, 'score' => 0];
            $bucket = (int) $row['value'] > 0 ? 'up' : 'down';
            $tallies[$ideaId][$bucket] += (int) $row['total'];
        }

        foreach ($tallies as $ideaId => $tally) {
            $tallies[$ideaId]['score'] = $tally['up'] - $tally['down'];
        }

        return $tallies;
    }

    /**
     * Which way a given user voted on each of the given ideas, as [ideaId => value].
     *
     * @param list<int> $ideaIds
     *
     * @return array<int, int>
     */
    public function votesByUser(User $user, array $ideaIds): array
    {
        if ([] === $ideaIds) {
            return [];
        }

        $rows = $this->createQueryBuilder('v')
            ->select('IDENTITY(v.idea) AS ideaId, v.value AS value')
            ->andWhere('v.user = :user')
            ->andWhere('v.idea IN (:ids)')
            ->setParameter('user', $user)
            ->setParameter('ids', $ideaIds)
            ->getQuery()
            ->getArrayResult();

        $votes = [];
        foreach ($rows as $row) {
            $votes[(int) $row['ideaId']] = (int) $row['value'];
        }

        return $votes;
    }
}
