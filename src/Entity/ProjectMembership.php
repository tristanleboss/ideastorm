<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ProjectRole;
use App\Repository\ProjectMembershipRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

/**
 * A person assigned to a project, together with the role they hold there.
 */
#[ORM\Entity(repositoryClass: ProjectMembershipRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_membership_project_user', columns: ['project_id', 'user_id'])]
#[UniqueEntity(fields: ['project', 'user'], message: 'This person is already assigned to the project.')]
class ProjectMembership
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'memberships')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Project $project = null;

    #[ORM\ManyToOne(inversedBy: 'memberships')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(length: 20, enumType: ProjectRole::class)]
    private ProjectRole $role = ProjectRole::Contributor;

    /** Free-text note such as "owns the onboarding flow". */
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $focus = null;

    #[ORM\Column]
    private \DateTimeImmutable $joinedAt;

    public function __construct(?Project $project = null, ?User $user = null, ProjectRole $role = ProjectRole::Contributor)
    {
        $this->project = $project;
        $this->user = $user;
        $this->role = $role;
        $this->joinedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProject(): ?Project
    {
        return $this->project;
    }

    public function setProject(?Project $project): static
    {
        $this->project = $project;

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

    public function getRole(): ProjectRole
    {
        return $this->role;
    }

    public function setRole(ProjectRole $role): static
    {
        $this->role = $role;

        return $this;
    }

    public function getFocus(): ?string
    {
        return $this->focus;
    }

    public function setFocus(?string $focus): static
    {
        $this->focus = $focus;

        return $this;
    }

    public function getJoinedAt(): \DateTimeImmutable
    {
        return $this->joinedAt;
    }
}
