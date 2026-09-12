<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AttachmentKind;
use App\Repository\AttachmentRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A file hung off an idea: a voice note, a screen recording, a photo, a Word document…
 *
 * The bytes live outside the web root (see App\Service\AttachmentStorage) and are served
 * back through a controller, so an upload can never be executed by the web server.
 */
#[ORM\Entity(repositoryClass: AttachmentRepository::class)]
class Attachment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'attachments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Idea $idea = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $uploader = null;

    #[ORM\Column(length: 20, enumType: AttachmentKind::class)]
    private AttachmentKind $kind = AttachmentKind::Other;

    #[ORM\Column(length: 255)]
    private string $originalFilename = '';

    /** Name on disk, relative to the upload directory. Never echoed back to the browser. */
    #[ORM\Column(length: 255)]
    private string $storedFilename = '';

    #[ORM\Column(length: 180)]
    private string $mimeType = 'application/octet-stream';

    #[ORM\Column]
    private int $size = 0;

    /** Length of an audio or video recording in seconds, when the browser reported one. */
    #[ORM\Column(nullable: true)]
    private ?int $durationSeconds = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
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

    public function getUploader(): ?User
    {
        return $this->uploader;
    }

    public function setUploader(?User $uploader): static
    {
        $this->uploader = $uploader;

        return $this;
    }

    public function getKind(): AttachmentKind
    {
        return $this->kind;
    }

    public function setKind(AttachmentKind $kind): static
    {
        $this->kind = $kind;

        return $this;
    }

    public function getOriginalFilename(): string
    {
        return $this->originalFilename;
    }

    public function setOriginalFilename(string $originalFilename): static
    {
        $this->originalFilename = $originalFilename;

        return $this;
    }

    public function getStoredFilename(): string
    {
        return $this->storedFilename;
    }

    public function setStoredFilename(string $storedFilename): static
    {
        $this->storedFilename = $storedFilename;

        return $this;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function setMimeType(string $mimeType): static
    {
        $this->mimeType = $mimeType;

        return $this;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function setSize(int $size): static
    {
        $this->size = $size;

        return $this;
    }

    public function getDurationSeconds(): ?int
    {
        return $this->durationSeconds;
    }

    public function setDurationSeconds(?int $durationSeconds): static
    {
        $this->durationSeconds = $durationSeconds;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** Whether the browser can play or show this file inline rather than downloading it. */
    public function isInlineRenderable(): bool
    {
        return \in_array($this->kind, [AttachmentKind::Audio, AttachmentKind::Video, AttachmentKind::Image], true);
    }
}
