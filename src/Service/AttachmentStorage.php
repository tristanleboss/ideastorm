<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Attachment;
use App\Entity\Idea;
use App\Entity\User;
use App\Enum\AttachmentKind;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Moves uploaded files onto disk and hands back the Attachment entity describing them.
 *
 * Two rules shape this class. Uploads are stored *outside* the document root under a
 * random name, so nothing a visitor uploads is ever reachable — let alone executable — as
 * a URL; and the MIME type is read from the bytes with fileinfo rather than taken from the
 * request, so renaming a PHP script to .webm does not get it past the allowlist.
 */
class AttachmentStorage
{
    /**
     * Types accepted for upload. Browsers differ on what they produce for a MediaRecorder
     * capture (Chrome gives audio/webm, Safari audio/mp4), so the media families are
     * deliberately broad; documents are an explicit list.
     *
     * @var list<string>
     */
    private const ALLOWED_MIME_TYPES = [
        // Voice notes and audio files
        'audio/webm', 'audio/ogg', 'audio/mpeg', 'audio/mp4', 'audio/aac', 'audio/flac',
        'audio/wav', 'audio/x-wav', 'audio/x-m4a', 'audio/3gpp', 'audio/amr',
        // Video, including in-browser camera recordings
        'video/webm', 'video/mp4', 'video/ogg', 'video/quicktime', 'video/x-matroska', 'video/3gpp',
        // Images
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/heic', 'image/heif',
        // Documents
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/vnd.oasis.opendocument.text',
        'application/vnd.oasis.opendocument.spreadsheet',
        'application/vnd.oasis.opendocument.presentation',
        'application/rtf', 'application/zip',
        'text/plain', 'text/csv', 'text/markdown',
    ];

    private readonly string $baseDir;
    private readonly Filesystem $filesystem;

    public function __construct(string $uploadDir, private readonly int $maxUploadSize)
    {
        // A relative IDEASTORM_UPLOAD_DIR is taken as relative to wherever PHP runs from,
        // which is why .env ships an absolute default built from %kernel.project_dir%.
        $this->baseDir = rtrim($uploadDir, '/');
        $this->filesystem = new Filesystem();
    }

    public function getMaxUploadSize(): int
    {
        return $this->maxUploadSize;
    }

    /** @return list<string> */
    public function getAllowedMimeTypes(): array
    {
        return self::ALLOWED_MIME_TYPES;
    }

    /**
     * @throws AttachmentRejectedException when the file is too large, empty or of a type we do not accept
     */
    public function store(UploadedFile $file, Idea $idea, ?User $uploader, ?int $durationSeconds = null): Attachment
    {
        if (!$file->isValid()) {
            throw new AttachmentRejectedException(\sprintf('Upload of "%s" failed: %s', $file->getClientOriginalName(), $file->getErrorMessage()));
        }

        $size = (int) $file->getSize();
        if ($size <= 0) {
            throw new AttachmentRejectedException(\sprintf('"%s" is empty.', $file->getClientOriginalName()));
        }

        if ($size > $this->maxUploadSize) {
            throw new AttachmentRejectedException(\sprintf(
                '"%s" is %s, which is over the %s limit for a single attachment.',
                $file->getClientOriginalName(),
                self::formatBytes($size),
                self::formatBytes($this->maxUploadSize),
            ));
        }

        // Read from the bytes on disk, not from the Content-Type the browser claimed.
        $mimeType = strtolower($file->getMimeType() ?? 'application/octet-stream');
        if (!\in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            throw new AttachmentRejectedException(\sprintf(
                '"%s" is a %s file, which is not an accepted attachment type.',
                $file->getClientOriginalName(),
                $mimeType,
            ));
        }

        $storedFilename = $this->buildStoredFilename($file, $mimeType);
        $target = $this->absolutePath($storedFilename);

        try {
            $this->filesystem->mkdir(\dirname($target));
            $file->move(\dirname($target), basename($target));
        } catch (FileException $e) {
            throw new AttachmentRejectedException(\sprintf('Could not save "%s": %s', $file->getClientOriginalName(), $e->getMessage()), previous: $e);
        }

        $attachment = new Attachment();
        $attachment->setIdea($idea)
            ->setUploader($uploader)
            ->setKind(AttachmentKind::fromMimeType($mimeType))
            ->setOriginalFilename($this->cleanOriginalName($file, $mimeType))
            ->setStoredFilename($storedFilename)
            ->setMimeType($mimeType)
            ->setSize($size)
            ->setDurationSeconds($durationSeconds);

        $idea->addAttachment($attachment);

        return $attachment;
    }

    /** Absolute path of an attachment's bytes on disk. */
    public function absolutePath(string $storedFilename): string
    {
        return $this->baseDir.'/'.$storedFilename;
    }

    public function pathFor(Attachment $attachment): string
    {
        return $this->absolutePath($attachment->getStoredFilename());
    }

    public function exists(Attachment $attachment): bool
    {
        return is_file($this->pathFor($attachment));
    }

    public function remove(Attachment $attachment): void
    {
        $this->filesystem->remove($this->pathFor($attachment));
    }

    /**
     * Random name in a two-character shard directory, so a busy project does not end up
     * with a hundred thousand entries in one folder.
     */
    private function buildStoredFilename(UploadedFile $file, string $mimeType): string
    {
        $random = bin2hex(random_bytes(16));
        $extension = $this->extensionFor($file, $mimeType);

        return substr($random, 0, 2).'/'.$random.('' !== $extension ? '.'.$extension : '');
    }

    private function extensionFor(UploadedFile $file, string $mimeType): string
    {
        $guessed = $file->guessExtension();
        if (\is_string($guessed) && '' !== $guessed) {
            return strtolower($guessed);
        }

        $fromName = pathinfo($file->getClientOriginalName(), \PATHINFO_EXTENSION);
        if (\is_string($fromName) && preg_match('/^[A-Za-z0-9]{1,8}$/', $fromName)) {
            return strtolower($fromName);
        }

        // Both browser recorders default to a WebM container when nothing better is known.
        return match (AttachmentKind::fromMimeType($mimeType)) {
            AttachmentKind::Audio, AttachmentKind::Video => 'webm',
            default => 'bin',
        };
    }

    /**
     * The name shown in the UI and used for downloads. Anything path-like is stripped:
     * the value is echoed into a Content-Disposition header, so it must stay boring.
     */
    private function cleanOriginalName(UploadedFile $file, string $mimeType): string
    {
        $raw = $file->getClientOriginalName();
        $base = pathinfo($raw, \PATHINFO_FILENAME);
        $extension = pathinfo($raw, \PATHINFO_EXTENSION);

        $slugger = new AsciiSlugger();
        $safeBase = (string) $slugger->slug($base, '-');
        if ('' === $safeBase) {
            $safeBase = match (AttachmentKind::fromMimeType($mimeType)) {
                AttachmentKind::Audio => 'voice-note',
                AttachmentKind::Video => 'recording',
                AttachmentKind::Image => 'image',
                default => 'attachment',
            };
        }

        $safeBase = mb_substr($safeBase, 0, 80);
        $safeExtension = preg_match('/^[A-Za-z0-9]{1,8}$/', $extension) ? '.'.strtolower($extension) : '';

        return $safeBase.$safeExtension;
    }

    public static function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $value = (float) $bytes;
        $unit = 0;
        while ($value >= 1024 && $unit < \count($units) - 1) {
            $value /= 1024;
            ++$unit;
        }

        return \sprintf($value < 10 && $unit > 0 ? '%.1f %s' : '%.0f %s', $value, $units[$unit]);
    }
}
