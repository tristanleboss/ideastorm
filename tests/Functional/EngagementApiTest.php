<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Enum\ProjectRole;
use Symfony\Component\HttpFoundation\Response;

/** Comments and votes — the two things an account unlocks for a visitor. */
class EngagementApiTest extends ApiTestCase
{
    public function testCommentsAreReadableByAnyoneAndWritableBySignedInPeople(): void
    {
        $project = $this->project('Sea Breeze', 'sea-breeze');
        $ada = $this->user('Ada', 'ada@example.com');
        $idea = $this->idea($project, $ada);

        $this->json('POST', '/api/ideas/'.$idea->getId().'/comments', ['body' => 'Sneaking in.']);
        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->statusCode());

        $this->signIn($ada);
        $data = $this->json('POST', '/api/ideas/'.$idea->getId().'/comments', ['body' => 'Strong yes from me.']);

        self::assertSame(Response::HTTP_CREATED, $this->statusCode());
        self::assertSame('Strong yes from me.', $data['comment']['body']);

        $this->client->restart();
        $thread = $this->get('/api/ideas/'.$idea->getId().'/comments');

        self::assertCount(1, $thread['comments'], 'a signed-out visitor can still read the discussion');
    }

    public function testRepliesStayOneLevelDeep(): void
    {
        $project = $this->project('Sea Breeze', 'sea-breeze');
        $ada = $this->user('Ada', 'ada@example.com');
        $idea = $this->idea($project, $ada);
        $this->signIn($ada);

        $root = $this->json('POST', '/api/ideas/'.$idea->getId().'/comments', ['body' => 'Top level.']);
        $reply = $this->json('POST', '/api/ideas/'.$idea->getId().'/comments', [
            'body' => 'A reply.', 'parentId' => $root['comment']['id'],
        ]);
        $replyToReply = $this->json('POST', '/api/ideas/'.$idea->getId().'/comments', [
            'body' => 'A reply to the reply.', 'parentId' => $reply['comment']['id'],
        ]);

        self::assertSame($root['comment']['id'], $reply['comment']['parentId']);
        self::assertSame(
            $root['comment']['id'],
            $replyToReply['comment']['parentId'],
            'a reply to a reply re-attaches to the root rather than nesting deeper',
        );

        $thread = $this->get('/api/ideas/'.$idea->getId().'/comments');
        self::assertCount(1, $thread['comments']);
        self::assertCount(2, $thread['comments'][0]['replies']);
    }

    public function testCommentCountIsReportedOnTheBoard(): void
    {
        $project = $this->project('Sea Breeze', 'sea-breeze');
        $ada = $this->user('Ada', 'ada@example.com');
        $idea = $this->idea($project, $ada);

        $this->signIn($ada);
        $this->json('POST', '/api/ideas/'.$idea->getId().'/comments', ['body' => 'One.']);
        $this->json('POST', '/api/ideas/'.$idea->getId().'/comments', ['body' => 'Two.']);

        self::assertSame(2, $this->get('/api/projects/sea-breeze/ideas')['ideas'][0]['commentCount']);
    }

    public function testEmptyCommentsAreRejected(): void
    {
        $project = $this->project('Sea Breeze', 'sea-breeze');
        $ada = $this->user('Ada', 'ada@example.com');
        $idea = $this->idea($project, $ada);
        $this->signIn($ada);

        $data = $this->json('POST', '/api/ideas/'.$idea->getId().'/comments', ['body' => '   ']);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->statusCode());
        self::assertArrayHasKey('body', $data['fields']);
    }

    public function testAuthorsEditTheirOwnCommentsAndModeratorsCanOnlyRemoveThem(): void
    {
        $project = $this->project('Sea Breeze', 'sea-breeze');
        $ada = $this->user('Ada', 'ada@example.com');
        $ben = $this->user('Ben', 'ben@example.com');
        $this->assignRole($project, $ada, ProjectRole::Owner);
        $idea = $this->idea($project, $ada);

        $this->signIn($ben);
        $comment = $this->json('POST', '/api/ideas/'.$idea->getId().'/comments', ['body' => 'Original wording.']);
        $commentId = $comment['comment']['id'];

        $edited = $this->json('PATCH', '/api/comments/'.$commentId, ['body' => 'Reworded by its author.']);
        self::assertSame('Reworded by its author.', $edited['comment']['body']);
        self::assertNotNull($edited['comment']['editedAt']);

        // The project owner may moderate the comment away, but not rewrite what Ben said.
        $this->signIn($ada);
        $this->json('PATCH', '/api/comments/'.$commentId, ['body' => 'Words in his mouth.']);
        self::assertSame(Response::HTTP_FORBIDDEN, $this->statusCode());

        $this->json('DELETE', '/api/comments/'.$commentId);
        self::assertSame(Response::HTTP_OK, $this->statusCode());
        self::assertCount(0, $this->get('/api/ideas/'.$idea->getId().'/comments')['comments']);
    }

    public function testAnUninvolvedPersonCannotDeleteSomebodyElsesComment(): void
    {
        $project = $this->project('Sea Breeze', 'sea-breeze');
        $ada = $this->user('Ada', 'ada@example.com');
        $idea = $this->idea($project, $ada);

        $this->signIn($ada);
        $comment = $this->json('POST', '/api/ideas/'.$idea->getId().'/comments', ['body' => 'Mine.']);

        $this->signIn($this->user('Chi', 'chi@example.com'));
        $this->json('DELETE', '/api/comments/'.$comment['comment']['id']);

        self::assertSame(Response::HTTP_FORBIDDEN, $this->statusCode());
    }

    public function testVotingRequiresAnAccount(): void
    {
        $project = $this->project('Sea Breeze', 'sea-breeze');
        $idea = $this->idea($project, $this->user('Ada', 'ada@example.com'));

        $this->json('POST', '/api/ideas/'.$idea->getId().'/vote', ['value' => 1]);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->statusCode());
    }

    public function testVotingTwiceTheSameWayTakesTheVoteBack(): void
    {
        $project = $this->project('Sea Breeze', 'sea-breeze');
        $idea = $this->idea($project, $this->user('Ada', 'ada@example.com'));
        $this->signIn($this->user('Ben', 'ben@example.com'));

        $up = $this->json('POST', '/api/ideas/'.$idea->getId().'/vote', ['value' => 1]);
        self::assertSame(['up' => 1, 'down' => 0, 'score' => 1, 'mine' => 1], $up['idea']['votes']);

        $again = $this->json('POST', '/api/ideas/'.$idea->getId().'/vote', ['value' => 1]);
        self::assertSame(['up' => 0, 'down' => 0, 'score' => 0, 'mine' => 0], $again['idea']['votes']);
    }

    public function testChangingYourMindFlipsTheVoteRatherThanAddingOne(): void
    {
        $project = $this->project('Sea Breeze', 'sea-breeze');
        $idea = $this->idea($project, $this->user('Ada', 'ada@example.com'));
        $this->signIn($this->user('Ben', 'ben@example.com'));

        $this->json('POST', '/api/ideas/'.$idea->getId().'/vote', ['value' => 1]);
        $down = $this->json('POST', '/api/ideas/'.$idea->getId().'/vote', ['value' => -1]);

        self::assertSame(['up' => 0, 'down' => 1, 'score' => -1, 'mine' => -1], $down['idea']['votes']);
    }

    public function testOneAccountCountsOnceNoMatterHowManyTimesItVotes(): void
    {
        $project = $this->project('Sea Breeze', 'sea-breeze');
        $idea = $this->idea($project, $this->user('Ada', 'ada@example.com'));
        $ben = $this->user('Ben', 'ben@example.com');
        $chi = $this->user('Chi', 'chi@example.com');

        $this->signIn($ben);
        $this->json('POST', '/api/ideas/'.$idea->getId().'/vote', ['value' => 1]);
        $this->json('POST', '/api/ideas/'.$idea->getId().'/vote', ['value' => -1]);
        $this->json('POST', '/api/ideas/'.$idea->getId().'/vote', ['value' => 1]);

        $this->signIn($chi);
        $final = $this->json('POST', '/api/ideas/'.$idea->getId().'/vote', ['value' => 1]);

        self::assertSame(2, $final['idea']['votes']['up']);
        self::assertSame(2, $final['idea']['votes']['score']);
    }

    public function testAnInvalidVoteValueIsRejected(): void
    {
        $project = $this->project('Sea Breeze', 'sea-breeze');
        $idea = $this->idea($project, $this->user('Ada', 'ada@example.com'));
        $this->signIn($this->user('Ben', 'ben@example.com'));

        $this->json('POST', '/api/ideas/'.$idea->getId().'/vote', ['value' => 5]);

        self::assertSame(Response::HTTP_BAD_REQUEST, $this->statusCode());
    }
}
