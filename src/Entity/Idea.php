<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\IdeaStatus;
use App\Repository\IdeaRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * An idea submitted to a project.
 *
 * An idea can also be a *version* of another idea: sub-ideas point at a parent through
 * {@see self::$parent} and keep the same feature while proposing a different take on it.
 * Only one level is exposed in the UI — a sub-idea cannot itself be branched — which
 * keeps the discussion attached to the feature rather than scattered down a tree.
 */
#[ORM\Entity(repositoryClass: IdeaRepository::class)]
#[ORM\Index(name: 'idx_idea_project_created', columns: ['project_id', 'created_at'])]
#[ORM\HasLifecycleCallbacks]
class Idea
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'ideas')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Project $project = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $author = null;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank(message: 'Give your idea a title.')]
    #[Assert\Length(min: 3, max: 180)]
    private string $title = '';

    #[ORM\Column(type: 'text', nullable: true)]
    #[Assert\Length(max: 20000)]
    private ?string $body = null;

    #[ORM\Column(length: 20, enumType: IdeaStatus::class)]
    private IdeaStatus $status = IdeaStatus::Proposed;

    /** The idea this one is a version of, or null for a top-level idea. */
    #[ORM\ManyToOne(inversedBy: 'children')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Idea $parent = null;

    /** Short label distinguishing versions of the same feature, e.g. "Mobile-first take". */
    #[ORM\Column(length: 120, nullable: true)]
    #[Assert\Length(max: 120)]
    private ?string $versionLabel = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, Idea> */
    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'parent', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    private Collection $children;

    /** @var Collection<int, Attachment> */
    #[ORM\OneToMany(targetEntity: Attachment::class, mappedBy: 'idea', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    private Collection $attachments;

    /** @var Collection<int, Comment> */
    #[ORM\OneToMany(targetEntity: Comment::class, mappedBy: 'idea', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    private Collection $comments;

    /** @var Collection<int, Vote> */
    #[ORM\OneToMany(targetEntity: Vote::class, mappedBy: 'idea', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $votes;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->children = new ArrayCollection();
        $this->attachments = new ArrayCollection();
        $this->comments = new ArrayCollection();
        $this->votes = new ArrayCollection();
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
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

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function setAuthor(?User $author): static
    {
        $this->author = $author;

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = trim($title);

        return $this;
    }

    public function getBody(): ?string
    {
        return $this->body;
    }

    public function setBody(?string $body): static
    {
        $body = null !== $body ? trim($body) : null;
        $this->body = '' !== $body ? $body : null;

        return $this;
    }

    public function getStatus(): IdeaStatus
    {
        return $this->status;
    }

    public function setStatus(IdeaStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getParent(): ?Idea
    {
        return $this->parent;
    }

    public function setParent(?Idea $parent): static
    {
        $this->parent = $parent;

        return $this;
    }

    public function isVersion(): bool
    {
        return null !== $this->parent;
    }

    public function getVersionLabel(): ?string
    {
        return $this->versionLabel;
    }

    public function setVersionLabel(?string $versionLabel): static
    {
        $versionLabel = null !== $versionLabel ? trim($versionLabel) : null;
        $this->versionLabel = '' !== $versionLabel ? $versionLabel : null;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** @return Collection<int, Idea> */
    public function getChildren(): Collection
    {
        return $this->children;
    }

    public function addChild(self $child): static
    {
        if (!$this->children->contains($child)) {
            $this->children->add($child);
            $child->setParent($this);
        }

        return $this;
    }

    /** @return Collection<int, Attachment> */
    public function getAttachments(): Collection
    {
        return $this->attachments;
    }

    public function addAttachment(Attachment $attachment): static
    {
        if (!$this->attachments->contains($attachment)) {
            $this->attachments->add($attachment);
            $attachment->setIdea($this);
        }

        return $this;
    }

    public function removeAttachment(Attachment $attachment): static
    {
        $this->attachments->removeElement($attachment);

        return $this;
    }

    /** @return Collection<int, Comment> */
    public function getComments(): Collection
    {
        return $this->comments;
    }

    public function addComment(Comment $comment): static
    {
        if (!$this->comments->contains($comment)) {
            $this->comments->add($comment);
            $comment->setIdea($this);
        }

        return $this;
    }

    /** @return Collection<int, Vote> */
    public function getVotes(): Collection
    {
        return $this->votes;
    }

    public function addVote(Vote $vote): static
    {
        if (!$this->votes->contains($vote)) {
            $this->votes->add($vote);
            $vote->setIdea($this);
        }

        return $this;
    }

    public function getVoteBy(?User $user): ?Vote
    {
        if (null === $user) {
            return null;
        }

        foreach ($this->votes as $vote) {
            if ($vote->getUser() === $user) {
                return $vote;
            }
        }

        return null;
    }
}
