<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Idea;
use App\Enum\AttachmentKind;
use App\Service\AttachmentRejectedException;
use App\Service\AttachmentStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class AttachmentStorageTest extends TestCase
{
    private string $uploadDir;
    private string $workDir;

    protected function setUp(): void
    {
        $this->uploadDir = sys_get_temp_dir().'/ideastorm-uploads-'.bin2hex(random_bytes(6));
        $this->workDir = sys_get_temp_dir().'/ideastorm-work-'.bin2hex(random_bytes(6));
        (new Filesystem())->mkdir([$this->uploadDir, $this->workDir]);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove([$this->uploadDir, $this->workDir]);
    }

    public function testStoringAnImageProducesAnAttachmentOutsideTheWebRoot(): void
    {
        $storage = new AttachmentStorage($this->uploadDir, 1_048_576);
        $idea = new Idea();

        $attachment = $storage->store($this->upload($this->pngBytes(), 'my holiday photo.png'), $idea, null);

        self::assertSame(AttachmentKind::Image, $attachment->getKind());
        self::assertSame('image/png', $attachment->getMimeType());
        self::assertSame('my-holiday-photo.png', $attachment->getOriginalFilename(), 'the display name is slugified');
        self::assertTrue($storage->exists($attachment));
        self::assertStringStartsWith($this->uploadDir, $storage->pathFor($attachment));
        self::assertCount(1, $idea->getAttachments());
    }

    public function testStoredNameIsRandomAndShardedRatherThanTheUploadedOne(): void
    {
        $storage = new AttachmentStorage($this->uploadDir, 1_048_576);

        $attachment = $storage->store($this->upload($this->pngBytes(), 'photo.png'), new Idea(), null);

        self::assertMatchesRegularExpression('#^[0-9a-f]{2}/[0-9a-f]{32}\.png$#', $attachment->getStoredFilename());
        self::assertStringNotContainsString('photo', $attachment->getStoredFilename());
    }

    public function testRecordingDurationIsKept(): void
    {
        $storage = new AttachmentStorage($this->uploadDir, 1_048_576);

        $attachment = $storage->store($this->upload($this->wavBytes(), 'voice-note.wav'), new Idea(), null, 42);

        self::assertSame(AttachmentKind::Audio, $attachment->getKind());
        self::assertSame(42, $attachment->getDurationSeconds());
    }

    public function testATypeOutsideTheAllowlistIsRejected(): void
    {
        $storage = new AttachmentStorage($this->uploadDir, 1_048_576);

        $this->expectException(AttachmentRejectedException::class);
        $this->expectExceptionMessageMatches('/not an accepted attachment type/');

        // A PHP script wearing a .png extension: the type is read from the bytes, not the name.
        $storage->store($this->upload('<?php echo "hello";', 'innocent.png'), new Idea(), null);
    }

    public function testAnOversizedFileIsRejected(): void
    {
        $storage = new AttachmentStorage($this->uploadDir, 64);

        $this->expectException(AttachmentRejectedException::class);
        $this->expectExceptionMessageMatches('/over the .* limit/');

        $storage->store($this->upload($this->wavBytes(), 'voice-note.wav'), new Idea(), null);
    }

    public function testRemovingAnAttachmentDeletesItsBytes(): void
    {
        $storage = new AttachmentStorage($this->uploadDir, 1_048_576);
        $attachment = $storage->store($this->upload($this->pngBytes(), 'photo.png'), new Idea(), null);

        $storage->remove($attachment);

        self::assertFalse($storage->exists($attachment));
    }

    public function testByteFormatting(): void
    {
        self::assertSame('512 B', AttachmentStorage::formatBytes(512));
        self::assertSame('1.0 KB', AttachmentStorage::formatBytes(1024));
        self::assertSame('1.5 MB', AttachmentStorage::formatBytes(1_572_864));
        self::assertSame('64 MB', AttachmentStorage::formatBytes(67_108_864));
    }

    private function upload(string $contents, string $clientName): UploadedFile
    {
        $path = $this->workDir.'/'.bin2hex(random_bytes(8));
        file_put_contents($path, $contents);

        return new UploadedFile($path, $clientName, null, null, true);
    }

    /** Smallest valid PNG: a single black pixel. */
    private function pngBytes(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAAAAAA6fptVAAAACklEQVR4nGNiAAAABgADNjd8qAAAAABJRU5ErkJggg==', true);
    }

    /** A short silent mono WAV, enough for fileinfo to recognise the container. */
    private function wavBytes(): string
    {
        $samples = str_repeat("\x00\x00", 800);
        $dataSize = \strlen($samples);

        return 'RIFF'.pack('V', 36 + $dataSize).'WAVE'
            .'fmt '.pack('V', 16).pack('vvVVvv', 1, 1, 16000, 32000, 2, 16)
            .'data'.pack('V', $dataSize).$samples;
    }
}
