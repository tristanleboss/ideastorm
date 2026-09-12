<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Enum\ProjectRole;
use App\Enum\ProjectStatus;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

class IdeaApiTest extends ApiTestCase
{
    public function testTheBoardIsReadableWithoutAnAccount(): void
    {
        $project = $this->project('Sea Breeze', 'sea-breeze');
        $this->idea($project, $this->user('Ada', 'ada@example.com'), 'Offline tickets');

        $data = $this->get('/api/projects/sea-breeze/ideas');

        self::assertSame(Response::HTTP_OK, $this->statusCode());
        self::assertCount(1, $data['ideas']);
        self::assertSame('Offline tickets', $data['ideas'][0]['title']);
        self::assertFalse($data['ideas'][0]['permissions']['vote'], 'a guest gets no write permissions');
    }

    public function testSubmittingAnIdeaWithText(): void
    {
        $this->project('Sea Breeze', 'sea-breeze');
        $this->signIn($this->user('Ada', 'ada@example.com'));

        $data = $this->multipart('/api/projects/sea-breeze/ideas', [
            'title' => 'Pin the next departure',
            'body' => 'Put it at the top of the home screen.',
        ]);

        self::assertSame(Response::HTTP_CREATED, $this->statusCode());
        self::assertSame('Pin the next departure', $data['idea']['title']);
        self::assertSame('proposed', $data['idea']['status']['value']);
        self::assertSame('Ada', $data['idea']['author']['displayName']);
        self::assertSame(0, $data['idea']['votes']['score']);
    }

    public function testAnIdeaNeedsMoreThanATitle(): void
    {
        $this->project('Sea Breeze', 'sea-breeze');
        $this->signIn($this->user('Ada', 'ada@example.com'));

        $data = $this->multipart('/api/projects/sea-breeze/ideas', ['title' => 'Just a title']);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->statusCode());
        self::assertSame('empty_idea', $data['error']);
    }

    public function testAnIdeaMadeOfNothingButAVoiceNoteIsAccepted(): void
    {
        $this->project('Sea Breeze', 'sea-breeze');
        $this->signIn($this->user('Ada', 'ada@example.com'));

        $data = $this->multipart(
            '/api/projects/sea-breeze/ideas',
            ['title' => 'Recorded on the ferry', 'durations' => ['37']],
            ['files' => [$this->wavUpload()]],
        );

        self::assertSame(Response::HTTP_CREATED, $this->statusCode());
        self::assertCount(1, $data['idea']['attachments']);

        $attachment = $data['idea']['attachments'][0];
        self::assertSame('audio', $attachment['kind'], 'the frontend switches renderers on a plain string');
        self::assertSame('Voice note', $attachment['kindLabel']);
        self::assertSame(37, $attachment['durationSeconds']);
        self::assertTrue($attachment['inline']);
    }

    public function testTextRecordingsAndDocumentsArriveInOneRequest(): void
    {
        $this->project('Sea Breeze', 'sea-breeze');
        $this->signIn($this->user('Ada', 'ada@example.com'));

        $data = $this->multipart(
            '/api/projects/sea-breeze/ideas',
            ['title' => 'The whole package', 'body' => 'Typed, recorded and sketched.', 'durations' => ['12', '']],
            ['files' => [$this->wavUpload(), $this->pngUpload()]],
        );

        self::assertSame(Response::HTTP_CREATED, $this->statusCode());
        self::assertSame(['audio', 'image'], array_column($data['idea']['attachments'], 'kind'));
        self::assertSame([12, null], array_column($data['idea']['attachments'], 'durationSeconds'));
    }

    public function testAnIdeaIsDiscardedWholeWhenOneAttachmentIsRejected(): void
    {
        $project = $this->project('Sea Breeze', 'sea-breeze');
        $this->signIn($this->user('Ada', 'ada@example.com'));

        $data = $this->multipart(
            '/api/projects/sea-breeze/ideas',
            ['title' => 'Half of this is fine', 'body' => 'But not the second file.'],
            ['files' => [$this->pngUpload(), $this->disguisedScriptUpload()]],
        );

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->statusCode());
        self::assertSame('attachment_rejected', $data['error']);
        self::assertSame([], $this->get('/api/projects/sea-breeze/ideas')['ideas'], 'nothing was persisted');
        self::assertSame(0, $this->get('/api/projects/'.$project->getSlug())['project']['ideaCount']);
    }

    public function testAttachmentsAreServedBackAndAreNotReachableAsStaticFiles(): void
    {
        $this->project('Sea Breeze', 'sea-breeze');
        $this->signIn($this->user('Ada', 'ada@example.com'));

        $created = $this->multipart(
            '/api/projects/sea-breeze/ideas',
            ['title' => 'With a sketch', 'body' => 'See attached.'],
            ['files' => [$this->pngUpload()]],
        );
        $attachment = $created['idea']['attachments'][0];

        $this->client->request('GET', $attachment['url']);
        $response = $this->client->getResponse();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('image/png', $response->headers->get('Content-Type'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertStringContainsString('inline;', (string) $response->headers->get('Content-Disposition'));

        self::assertFileDoesNotExist(
            \dirname(__DIR__, 2).'/public/'.$attachment['url'],
            'uploads live outside the document root',
        );
    }

    public function testDocumentsAreSentAsDownloadsRatherThanRendered(): void
    {
        $this->project('Sea Breeze', 'sea-breeze');
        $this->signIn($this->user('Ada', 'ada@example.com'));

        $created = $this->multipart(
            '/api/projects/sea-breeze/ideas',
            ['title' => 'Requirements', 'body' => 'Attached.'],
            ['files' => [$this->textUpload()]],
        );

        $this->client->request('GET', $created['idea']['attachments'][0]['url']);

        self::assertStringContainsString('attachment;', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
    }

    public function testAVersionKeepsTheFeatureAndHangsOffItsParent(): void
    {
        $project = $this->project('Sea Breeze', 'sea-breeze');
        $ada = $this->user('Ada', 'ada@example.com');
        $parent = $this->idea($project, $ada, 'Offline tickets');

        $this->signIn($this->user('Ben', 'ben@example.com'));
        $version = $this->multipart('/api/projects/sea-breeze/ideas', [
            'title' => 'Offline tickets as a wallet pass',
            'body' => 'The OS handles it.',
            'versionOf' => (string) $parent->getId(),
            'versionLabel' => 'Wallet pass route',
        ]);

        self::assertSame(Response::HTTP_CREATED, $this->statusCode());
        self::assertTrue($version['idea']['isVersion']);
        self::assertSame($parent->getId(), $version['idea']['parentId']);
        self::assertSame('Wallet pass route', $version['idea']['versionLabel']);

        $board = $this->get('/api/projects/sea-breeze/ideas');
        self::assertCount(1, $board['ideas'], 'versions do not appear as top-level ideas');
        self::assertCount(1, $board['ideas'][0]['versions']);
    }

    public function testAVersionCannotItselfBeBranched(): void
    {
        $project = $this->project('Sea Breeze', 'sea-breeze');
        $ada = $this->user('Ada', 'ada@example.com');
        $parent = $this->idea($project, $ada, 'Offline tickets');

        $this->signIn($ada);
        $version = $this->multipart('/api/projects/sea-breeze/ideas', [
            'title' => 'A version', 'body' => 'Of the above.', 'versionOf' => (string) $parent->getId(),
        ]);

        $data = $this->multipart('/api/projects/sea-breeze/ideas', [
            'title' => 'A version of a version', 'body' => 'Too deep.', 'versionOf' => (string) $version['idea']['id'],
        ]);

        self::assertSame(Response::HTTP_FORBIDDEN, $this->statusCode());
        self::assertSame('forbidden', $data['error']);
    }

    public function testAVersionCannotBeBranchedFromAnotherProjectsIdea(): void
    {
        $other = $this->project('Tool library', 'tool-library');
        $this->project('Sea Breeze', 'sea-breeze');
        $ada = $this->user('Ada', 'ada@example.com');
        $elsewhere = $this->idea($other, $ada, 'Somewhere else');

        $this->signIn($ada);
        $this->multipart('/api/projects/sea-breeze/ideas', [
            'title' => 'Sneaky', 'body' => 'Cross-project.', 'versionOf' => (string) $elsewhere->getId(),
        ]);

        self::assertSame(Response::HTTP_BAD_REQUEST, $this->statusCode());
    }

    public function testAClosedProjectRefusesNewIdeas(): void
    {
        $this->project('Café loyalty', 'cafe-loyalty', ProjectStatus::Archived);
        $this->signIn($this->user('Ada', 'ada@example.com'));

        $data = $this->multipart('/api/projects/cafe-loyalty/ideas', ['title' => 'Bring it back', 'body' => 'Please.']);

        self::assertSame(Response::HTTP_FORBIDDEN, $this->statusCode());
        self::assertSame('project_closed', $data['error']);
    }

    public function testOnlyTriagersCanChangeAnIdeaStatus(): void
    {
        $project = $this->project('Sea Breeze', 'sea-breeze');
        $ada = $this->user('Ada', 'ada@example.com');
        $ben = $this->user('Ben', 'ben@example.com');
        $this->assignRole($project, $ada, ProjectRole::Reviewer);
        $idea = $this->idea($project, $ben, 'Offline tickets');

        $this->signIn($ben);
        $this->json('POST', '/api/ideas/'.$idea->getId().'/status', ['status' => 'accepted']);
        self::assertSame(Response::HTTP_FORBIDDEN, $this->statusCode(), 'the author is not automatically a triager');

        $this->signIn($ada);
        $data = $this->json('POST', '/api/ideas/'.$idea->getId().'/status', ['status' => 'accepted']);

        self::assertSame(Response::HTTP_OK, $this->statusCode());
        self::assertSame('accepted', $data['idea']['status']['value']);
    }

    public function testAuthorsCanEditTheirOwnIdeaAndNobodyElseCan(): void
    {
        $project = $this->project('Sea Breeze', 'sea-breeze');
        $ada = $this->user('Ada', 'ada@example.com');
        $idea = $this->idea($project, $ada, 'Offline tickets');

        $this->signIn($this->user('Ben', 'ben@example.com'));
        $this->json('PATCH', '/api/ideas/'.$idea->getId(), ['title' => 'Hijacked']);
        self::assertSame(Response::HTTP_FORBIDDEN, $this->statusCode());

        $this->signIn($ada);
        $data = $this->json('PATCH', '/api/ideas/'.$idea->getId(), ['title' => 'Offline tickets, revised']);

        self::assertSame('Offline tickets, revised', $data['idea']['title']);
    }

    public function testDeletingAnIdeaTakesItsVersionsAndFilesWithIt(): void
    {
        $project = $this->project('Sea Breeze', 'sea-breeze');
        $ada = $this->user('Ada', 'ada@example.com');
        $this->signIn($ada);

        $created = $this->multipart(
            '/api/projects/sea-breeze/ideas',
            ['title' => 'With a file', 'body' => 'Attached.'],
            ['files' => [$this->pngUpload()]],
        );
        $ideaId = $created['idea']['id'];
        $attachmentUrl = $created['idea']['attachments'][0]['url'];

        $this->multipart('/api/projects/sea-breeze/ideas', [
            'title' => 'A version', 'body' => 'Of the above.', 'versionOf' => (string) $ideaId,
        ]);

        $this->json('DELETE', '/api/ideas/'.$ideaId);
        self::assertSame(Response::HTTP_OK, $this->statusCode());

        self::assertSame([], $this->get('/api/projects/sea-breeze/ideas')['ideas']);

        $this->client->request('GET', $attachmentUrl);
        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
    }

    public function testTheBoardCanBeFilteredAndSorted(): void
    {
        $project = $this->project('Sea Breeze', 'sea-breeze');
        $ada = $this->user('Ada', 'ada@example.com');
        $ben = $this->user('Ben', 'ben@example.com');
        $this->assignRole($project, $ada, ProjectRole::Owner);

        $popular = $this->idea($project, $ada, 'Offline tickets');
        $this->idea($project, $ada, 'Seat map');

        $this->signIn($ben);
        $this->json('POST', '/api/ideas/'.$popular->getId().'/vote', ['value' => 1]);

        self::assertSame(
            ['Offline tickets', 'Seat map'],
            array_column($this->get('/api/projects/sea-breeze/ideas?sort=top')['ideas'], 'title'),
        );

        self::assertSame(
            ['Seat map'],
            array_column($this->get('/api/projects/sea-breeze/ideas?q=seat')['ideas'], 'title'),
        );

        $this->signIn($ada);
        $this->json('POST', '/api/ideas/'.$popular->getId().'/status', ['status' => 'accepted']);

        self::assertSame(
            ['Offline tickets'],
            array_column($this->get('/api/projects/sea-breeze/ideas?status=accepted')['ideas'], 'title'),
        );
    }

    public function testAFloodOfSubmissionsIsRateLimited(): void
    {
        $this->project('Sea Breeze', 'sea-breeze');
        $this->signIn($this->user('Ada', 'ada@example.com'));

        // The limiter's counters live in a cache pool, which a kernel reboot between requests
        // would leave untouched — but the test client also loses its CSRF token that way.
        $this->client->disableReboot();

        $lastStatus = null;
        $data = [];
        for ($i = 0; $i < 32; ++$i) {
            $data = $this->multipart('/api/projects/sea-breeze/ideas', [
                'title' => 'Idea number '.$i,
                'body' => 'Submitted in a tight loop.',
            ]);
            $lastStatus = $this->statusCode();

            if (Response::HTTP_TOO_MANY_REQUESTS === $lastStatus) {
                break;
            }
        }

        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $lastStatus, 'the flood is cut off');
        self::assertSame('rate_limited', $data['error']);
        self::assertArrayHasKey('retryAfter', $data);
    }

    private function wavUpload(): UploadedFile
    {
        $samples = str_repeat("\x00\x00", 800);
        $size = \strlen($samples);
        $wav = 'RIFF'.pack('V', 36 + $size).'WAVE'
            .'fmt '.pack('V', 16).pack('vvVVvv', 1, 1, 16000, 32000, 2, 16)
            .'data'.pack('V', $size).$samples;

        return $this->upload($wav, 'voice-note.wav');
    }

    private function pngUpload(): UploadedFile
    {
        return $this->upload(
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAAAAAA6fptVAAAACklEQVR4nGNiAAAABgADNjd8qAAAAABJRU5ErkJggg==', true),
            'sketch.png',
        );
    }

    private function textUpload(): UploadedFile
    {
        return $this->upload("Requirements\n\n1. Works offline\n", 'requirements.txt');
    }

    /** A PHP script wearing an image extension — the storage layer sniffs the bytes. */
    private function disguisedScriptUpload(): UploadedFile
    {
        return $this->upload('<?php echo "hello";', 'innocent.png');
    }

    private function upload(string $contents, string $clientName): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'ideastorm-test-');
        file_put_contents($path, $contents);

        return new UploadedFile($path, $clientName, null, null, true);
    }
}
