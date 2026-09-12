<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Enum\AttachmentKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AttachmentKindTest extends TestCase
{
    #[DataProvider('mimeTypes')]
    public function testMimeTypeMapsToKind(string $mimeType, AttachmentKind $expected): void
    {
        self::assertSame($expected, AttachmentKind::fromMimeType($mimeType));
    }

    /** @return iterable<string, array{string, AttachmentKind}> */
    public static function mimeTypes(): iterable
    {
        yield 'browser voice note' => ['audio/webm', AttachmentKind::Audio];
        yield 'safari voice note' => ['audio/mp4', AttachmentKind::Audio];
        yield 'camera recording' => ['video/webm', AttachmentKind::Video];
        yield 'phone recording' => ['video/quicktime', AttachmentKind::Video];
        yield 'screenshot' => ['image/png', AttachmentKind::Image];
        yield 'word document' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', AttachmentKind::Document];
        yield 'pdf' => ['application/pdf', AttachmentKind::Document];
        yield 'plain text' => ['text/plain', AttachmentKind::Document];
        yield 'anything else' => ['application/x-tar', AttachmentKind::Other];
    }

    public function testMimeTypeMatchingIsCaseInsensitive(): void
    {
        self::assertSame(AttachmentKind::Image, AttachmentKind::fromMimeType('IMAGE/JPEG'));
        self::assertSame(AttachmentKind::Document, AttachmentKind::fromMimeType('APPLICATION/PDF'));
    }
}
