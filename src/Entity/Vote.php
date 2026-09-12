<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\VoteRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One person's vote on one idea: +1 or -1. The unique constraint on (idea, user) is what
 * stops an account from stuffing the ballot — casting again updates the existing row.
 */
#[ORM\Entity(repositoryClass: VoteRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_vote_idea_user', columns: ['idea_id', 'user_id'])]
#[UniqueEntity(fields: ['idea', 'user'], message: 'You have already voted on this idea.')]
class Vote
{
    public const UP = 1;
    public const DOWN = -1;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'votes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Idea $idea = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(type: 'smallint')]
    #[Assert\Choice(choices: [self::UP, self::DOWN], message: 'A vote is either +1 or -1.')]
    private int $value = self::UP;

    #[ORM\Column]
    private \DateTimeImmutable $castAt;

    public function __construct(?Idea $idea = null, ?User $user = null, int $value = self::UP)
    {
        $this->idea = $idea;
        $this->user = $user;
        $this->value = $value;
        $this->castAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIdea(): ?Idea
    {
        return $this->idea;
    }

    public function setIdea(?Idea $idea): static
    {
        $this->idea = $idea;

        return $this;
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

    public function getValue(): int
    {
        return $this->value;
    }

    public function setValue(int $value): static
    {
        $this->value = $value;
        $this->castAt = new \DateTimeImmutable();

        return $this;
    }

    public function getCastAt(): \DateTimeImmutable
    {
        return $this->castAt;
    }
}
