<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Coarse bucket an attachment falls into, derived from its MIME type. The UI uses it
 * to pick a renderer: an <audio> player for a voice note, <video> for a recording,
 * an inline preview for images and a download card for everything else.
 */
enum AttachmentKind: string
{
    case Audio = 'audio';
    case Video = 'video';
    case Image = 'image';
    case Document = 'document';
    case Other = 'other';

    public static function fromMimeType(string $mimeType): self
    {
        $mimeType = strtolower($mimeType);

        return match (true) {
            str_starts_with($mimeType, 'audio/') => self::Audio,
            str_starts_with($mimeType, 'video/') => self::Video,
            str_starts_with($mimeType, 'image/') => self::Image,
            \in_array($mimeType, self::documentMimeTypes(), true) => self::Document,
            default => self::Other,
        };
    }

    /** @return list<string> */
    public static function documentMimeTypes(): array
    {
        return [
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
            'application/rtf',
            'text/plain',
            'text/csv',
            'text/markdown',
        ];
    }

    public function label(): string
    {
        return match ($this) {
            self::Audio => 'Voice note',
            self::Video => 'Video',
            self::Image => 'Image',
            self::Document => 'Document',
            self::Other => 'File',
        };
    }
}
