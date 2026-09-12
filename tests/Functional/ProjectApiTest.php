<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Enum\ProjectRole;
use App\Enum\ProjectStatus;
use Symfony\Component\HttpFoundation\Response;

class ProjectApiTest extends ApiTestCase
{
    public function testVisitorsCanBrowseProjectsWithoutAnAccount(): void
    {
        $this->project('Sea Breeze', 'sea-breeze');
        $this->project('Tool library', 'tool-library');

        $data = $this->get('/api/projects');

        self::assertSame(Response::HTTP_OK, $this->statusCode());
        self::assertCount(2, $data['projects']);
        self::assertSame(['sea-breeze', 'tool-library'], array_column($data['projects'], 'slug'));
    }

    public function testProjectListingCanBeSearched(): void
    {
        $this->project('Sea Breeze', 'sea-breeze');
        $this->project('Tool library', 'tool-library');

        $data = $this->get('/api/projects?q=breeze');

        self::assertCount(1, $data['projects']);
        self::assertSame('sea-breeze', $data['projects'][0]['slug']);
    }

    public function testProjectDetailListsMembersAndTheirRoles(): void
    {
        $project = $this->project('Sea Breeze', 'sea-breeze');
        $this->assignRole($project, $this->user('Ada', 'ada@example.com'), ProjectRole::Owner);
        $this->assignRole($project, $this->user('Ben', 'ben@example.com'), ProjectRole::Reviewer);

        $data = $this->get('/api/projects/sea-breeze');

        self::assertSame('Sea Breeze', $data['project']['name']);
        self::assertCount(2, $data['members']);
        self::assertSame(['owner', 'reviewer'], array_column(array_column($data['members'], 'role'), 'value'));
        self::assertSame('Ada', $data['members'][0]['user']['displayName']);
    }

    public function testIdeaCountExcludesVersionsSoItMatchesTheStatusChips(): void
    {
        $project = $this->project('Sea Breeze', 'sea-breeze');
        $ada = $this->user('Ada', 'ada@example.com');
        $idea = $this->idea($project, $ada, 'Offline tickets');

        $this->signIn($ada);
        $this->multipart('/api/projects/sea-breeze/ideas', [
            'title' => 'Offline tickets, wallet pass edition',
            'body' => 'Same feature, different route.',
            'versionOf' => (string) $idea->getId(),
        ]);
        self::assertSame(Response::HTTP_CREATED, $this->statusCode());

        $data = $this->get('/api/projects/sea-breeze');

        self::assertSame(1, $data['project']['ideaCount']);
        self::assertSame(1, array_sum($data['ideaCountsByStatus']));
    }

    public function testAnUnknownProjectIsA404WithJsonRatherThanHtml(): void
    {
        $data = $this->get('/api/projects/does-not-exist');

        self::assertSame(Response::HTTP_NOT_FOUND, $this->statusCode());
        self::assertSame('http_error', $data['error']);
    }

    public function testCreatingAProjectMakesTheCreatorItsOwner(): void
    {
        $this->signIn($this->user('Ada', 'ada@example.com'));

        $data = $this->json('POST', '/api/projects', [
            'name' => 'Bike parking at the depot',
            'summary' => 'Where to put 200 bikes.',
        ]);

        self::assertSame(Response::HTTP_CREATED, $this->statusCode());
        self::assertSame('bike-parking-at-the-depot', $data['project']['slug']);
        self::assertSame('owner', $data['project']['viewerRole']['value']);
        self::assertTrue($data['project']['permissions']['manageMembers']);
    }

    public function testProjectSlugsDoNotCollide(): void
    {
        $this->project('Bike parking', 'bike-parking');
        $this->signIn($this->user('Ada', 'ada@example.com'));

        $data = $this->json('POST', '/api/projects', ['name' => 'Bike parking', 'summary' => 'Another one.']);

        self::assertSame('bike-parking-2', $data['project']['slug']);
    }

    public function testCreatingAProjectValidatesItsFields(): void
    {
        $this->signIn($this->user('Ada', 'ada@example.com'));

        $data = $this->json('POST', '/api/projects', ['name' => 'x', 'summary' => '']);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->statusCode());
        self::assertSame('validation_failed', $data['error']);
        self::assertArrayHasKey('name', $data['fields']);
        self::assertArrayHasKey('summary', $data['fields']);
    }

    public function testGuestsCannotCreateProjects(): void
    {
        $this->json('POST', '/api/projects', ['name' => 'Anything', 'summary' => 'At all.']);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->statusCode());
    }

    public function testOnlyOwnersAndLeadsCanAssignMembers(): void
    {
        $project = $this->project('Sea Breeze', 'sea-breeze');
        $ada = $this->user('Ada', 'ada@example.com');
        $ben = $this->user('Ben', 'ben@example.com');
        $chi = $this->user('Chi', 'chi@example.com');
        $this->assignRole($project, $ada, ProjectRole::Owner);
        $this->assignRole($project, $ben, ProjectRole::Contributor);

        $this->signIn($ben);
        $this->json('POST', '/api/projects/sea-breeze/members', ['email' => 'chi@example.com', 'role' => 'reviewer']);
        self::assertSame(Response::HTTP_FORBIDDEN, $this->statusCode());

        $this->signIn($ada);
        $data = $this->json('POST', '/api/projects/sea-breeze/members', [
            'userId' => $chi->getId(),
            'role' => 'reviewer',
            'focus' => 'Timetable data',
        ]);

        self::assertSame(Response::HTTP_CREATED, $this->statusCode());
        self::assertSame('reviewer', $data['member']['role']['value']);
        self::assertSame('Timetable data', $data['member']['focus']);
    }

    public function testSomebodyCannotBeAssignedTwice(): void
    {
        $project = $this->project('Sea Breeze', 'sea-breeze');
        $ada = $this->user('Ada', 'ada@example.com');
        $ben = $this->user('Ben', 'ben@example.com');
        $this->assignRole($project, $ada, ProjectRole::Owner);
        $this->assignRole($project, $ben, ProjectRole::Contributor);

        $this->signIn($ada);
        $data = $this->json('POST', '/api/projects/sea-breeze/members', ['email' => 'ben@example.com']);

        self::assertSame(Response::HTTP_CONFLICT, $this->statusCode());
        self::assertSame('already_member', $data['error']);
    }

    public function testTheLastOwnerCannotBeDemotedOrRemoved(): void
    {
        $project = $this->project('Sea Breeze', 'sea-breeze');
        $ada = $this->user('Ada', 'ada@example.com');
        $membership = $this->assignRole($project, $ada, ProjectRole::Owner);

        $this->signIn($ada);

        $demote = $this->json('PATCH', '/api/projects/sea-breeze/members/'.$membership->getId(), ['role' => 'observer']);
        self::assertSame(Response::HTTP_CONFLICT, $this->statusCode());
        self::assertSame('last_owner', $demote['error']);

        $remove = $this->json('DELETE', '/api/projects/sea-breeze/members/'.$membership->getId());
        self::assertSame(Response::HTTP_CONFLICT, $this->statusCode());
        self::assertSame('last_owner', $remove['error']);
    }

    public function testAnOwnerCanBeRemovedOnceAnotherOwnerExists(): void
    {
        $project = $this->project('Sea Breeze', 'sea-breeze');
        $ada = $this->user('Ada', 'ada@example.com');
        $ben = $this->user('Ben', 'ben@example.com');
        $this->assignRole($project, $ada, ProjectRole::Owner);
        $bensMembership = $this->assignRole($project, $ben, ProjectRole::Owner);

        $this->signIn($ada);
        $this->json('DELETE', '/api/projects/sea-breeze/members/'.$bensMembership->getId());

        self::assertSame(Response::HTTP_OK, $this->statusCode());
        self::assertCount(1, $this->get('/api/projects/sea-breeze/members')['members']);
    }

    public function testAProjectCanBeClosedByItsOwner(): void
    {
        $project = $this->project('Sea Breeze', 'sea-breeze');
        $ada = $this->user('Ada', 'ada@example.com');
        $this->assignRole($project, $ada, ProjectRole::Owner);

        $this->signIn($ada);
        $data = $this->json('PATCH', '/api/projects/sea-breeze', ['status' => ProjectStatus::Closed->value]);

        self::assertSame('closed', $data['project']['status']['value']);
        self::assertFalse($data['project']['acceptsIdeas']);
    }

    public function testTheAccountDirectoryIsNotPublic(): void
    {
        $this->user('Ada', 'ada@example.com');

        $this->get('/api/users?q=ada');
        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->statusCode());

        $this->signIn($this->user('Ben', 'ben@example.com'));
        $data = $this->get('/api/users?q=ada');

        self::assertSame(Response::HTTP_OK, $this->statusCode());
        self::assertSame(['Ada'], array_column($data['users'], 'displayName'));
    }
}
