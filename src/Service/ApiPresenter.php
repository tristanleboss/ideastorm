<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Attachment;
use App\Entity\Comment;
use App\Entity\Idea;
use App\Entity\Project;
use App\Entity\ProjectMembership;
use App\Entity\User;
use App\Enum\AttachmentKind;
use App\Enum\IdeaStatus;
use App\Enum\ProjectRole;
use App\Enum\ProjectStatus;
use App\Repository\CommentRepository;
use App\Repository\VoteRepository;
use App\Security\Voter\CommentVoter;
use App\Security\Voter\IdeaVoter;
use App\Security\Voter\ProjectVoter;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Turns entities into the plain arrays the Vue frontend consumes.
 *
 * Everything the UI needs to decide what to show — vote tallies, comment counts, and the
 * viewer's own permissions — is computed here, so a component never has to re-derive
 * authorization rules in JavaScript. Aggregates are fetched in bulk (one query for all vote
 * tallies, one for all comment counts) rather than per idea.
 */
class ApiPresenter
{
    public function __construct(
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly VoteRepository $votes,
        private readonly CommentRepository $comments,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function user(?User $user): ?array
    {
        if (null === $user) {
            return null;
        }

        return [
            'id' => $user->getId(),
            'displayName' => $user->getDisplayName(),
            'initials' => $user->getInitials(),
            'avatarUrl' => $user->getAvatarUrl(),
        ];
    }

    /** The signed-in viewer, with the extra fields only they get to see. */
    public function viewer(?User $user): ?array
    {
        if (null === $user) {
            return null;
        }

        return array_merge($this->user($user), [
            'email' => $user->getEmail(),
            'roles' => $user->getRoles(),
            'socialProviders' => array_map(
                static fn ($identity): string => $identity->getProvider(),
                $user->getSocialIdentities()->toArray(),
            ),
        ]);
    }

    /**
     * @param array{ideas?: int, members?: int} $counts
     *
     * @return array<string, mixed>
     */
    public function project(Project $project, array $counts = [], bool $withDetail = false): array
    {
        $payload = [
            'id' => $project->getId(),
            'slug' => $project->getSlug(),
            'name' => $project->getName(),
            'summary' => $project->getSummary(),
            'status' => $this->enum($project->getStatus()),
            'acceptsIdeas' => $project->getStatus()->acceptsIdeas(),
            'accentColor' => $project->getAccentColor(),
            'ideaCount' => $counts['ideas'] ?? $project->getIdeas()->count(),
            'memberCount' => $counts['members'] ?? $project->getMemberships()->count(),
            'createdAt' => $this->date($project->getCreatedAt()),
            'updatedAt' => $this->date($project->getUpdatedAt()),
            'permissions' => [
                'submitIdea' => $this->security->isGranted(ProjectVoter::SUBMIT_IDEA, $project),
                'manageMembers' => $this->security->isGranted(ProjectVoter::MANAGE_MEMBERS, $project),
                'edit' => $this->security->isGranted(ProjectVoter::EDIT, $project),
            ],
        ];

        if ($withDetail) {
            $payload['description'] = $project->getDescription();
            $payload['viewerRole'] = $this->enumOrNull($project->getRoleFor($this->currentUser()));
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    public function membership(ProjectMembership $membership): array
    {
        return [
            'id' => $membership->getId(),
            'user' => $this->user($membership->getUser()),
            'role' => $this->enum($membership->getRole()),
            'roleDescription' => $membership->getRole()->description(),
            'focus' => $membership->getFocus(),
            'joinedAt' => $this->date($membership->getJoinedAt()),
        ];
    }

    /**
     * A list of ideas with their versions, tallies and comment counts resolved in bulk.
     *
     * @param list<Idea> $ideas
     *
     * @return list<array<string, mixed>>
     */
    public function ideaCollection(array $ideas, string $sort = 'recent'): array
    {
        $context = $this->buildContext($ideas);

        $payload = array_map(fn (Idea $idea): array => $this->idea($idea, $context, withVersions: true), $ideas);

        return $this->sortIdeas($payload, $sort);
    }

    /**
     * @param list<array<string, mixed>> $ideas
     *
     * @return list<array<string, mixed>>
     */
    private function sortIdeas(array $ideas, string $sort): array
    {
        usort($ideas, static fn (array $a, array $b): int => match ($sort) {
            'top' => [$b['votes']['score'], $b['createdAt']] <=> [$a['votes']['score'], $a['createdAt']],
            'discussed' => [$b['commentCount'], $b['createdAt']] <=> [$a['commentCount'], $a['createdAt']],
            'versions' => [\count($b['versions']), $b['createdAt']] <=> [\count($a['versions']), $a['createdAt']],
            default => $b['createdAt'] <=> $a['createdAt'],
        });

        return array_values($ideas);
    }

    /** Single idea, for the detail panel. */
    public function ideaDetail(Idea $idea): array
    {
        $context = $this->buildContext([$idea]);
        $payload = $this->idea($idea, $context, withVersions: true);
        $payload['project'] = null !== $idea->getProject() ? $this->project($idea->getProject()) : null;

        return $payload;
    }

    /**
     * @param array{tallies: array<int, array{up: int, down: int, score: int}>, myVotes: array<int, int>, commentCounts: array<int, int>} $context
     *
     * @return array<string, mixed>
     */
    public function idea(Idea $idea, array $context, bool $withVersions = false): array
    {
        $id = (int) $idea->getId();
        $tally = $context['tallies'][$id] ?? ['up' => 0, 'down' => 0, 'score' => 0];

        $payload = [
            'id' => $id,
            'title' => $idea->getTitle(),
            'body' => $idea->getBody(),
            'status' => $this->enum($idea->getStatus()),
            'author' => $this->user($idea->getAuthor()),
            'projectSlug' => $idea->getProject()?->getSlug(),
            'parentId' => $idea->getParent()?->getId(),
            'isVersion' => $idea->isVersion(),
            'versionLabel' => $idea->getVersionLabel(),
            'createdAt' => $this->date($idea->getCreatedAt()),
            'updatedAt' => $this->date($idea->getUpdatedAt()),
            'attachments' => array_map(
                fn (Attachment $attachment): array => $this->attachment($attachment),
                $idea->getAttachments()->toArray(),
            ),
            'votes' => [
                'up' => $tally['up'],
                'down' => $tally['down'],
                'score' => $tally['score'],
                'mine' => $context['myVotes'][$id] ?? 0,
            ],
            'commentCount' => $context['commentCounts'][$id] ?? 0,
            'permissions' => [
                'edit' => $this->security->isGranted(IdeaVoter::EDIT, $idea),
                'delete' => $this->security->isGranted(IdeaVoter::DELETE, $idea),
                'triage' => $this->security->isGranted(IdeaVoter::TRIAGE, $idea),
                'branch' => $this->security->isGranted(IdeaVoter::BRANCH, $idea),
                'comment' => $this->security->isGranted(IdeaVoter::COMMENT, $idea),
                'vote' => $this->security->isGranted(IdeaVoter::VOTE, $idea),
            ],
        ];

        if ($withVersions) {
            $versions = array_map(
                fn (Idea $child): array => $this->idea($child, $context),
                $idea->getChildren()->toArray(),
            );
            usort($versions, static fn (array $a, array $b): int => $a['createdAt'] <=> $b['createdAt']);
            $payload['versions'] = array_values($versions);
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    public function attachment(Attachment $attachment): array
    {
        return [
            'id' => $attachment->getId(),
            // A bare string, unlike the {value, label} pairs used for statuses and roles: the
            // frontend switches renderers on this and builds the same value locally for files
            // a visitor has picked but not yet uploaded.
            'kind' => $attachment->getKind()->value,
            'kindLabel' => $attachment->getKind()->label(),
            'filename' => $attachment->getOriginalFilename(),
            'mimeType' => $attachment->getMimeType(),
            'size' => $attachment->getSize(),
            'sizeLabel' => AttachmentStorage::formatBytes($attachment->getSize()),
            'durationSeconds' => $attachment->getDurationSeconds(),
            'inline' => $attachment->isInlineRenderable(),
            'url' => $this->urlGenerator->generate('app_attachment_show', ['id' => $attachment->getId()]),
            'downloadUrl' => $this->urlGenerator->generate('app_attachment_download', ['id' => $attachment->getId()]),
            'uploader' => $this->user($attachment->getUploader()),
            'createdAt' => $this->date($attachment->getCreatedAt()),
        ];
    }

    /**
     * Comments as a two-level thread: top-level entries each carry their own replies.
     *
     * @param list<Comment> $comments
     *
     * @return list<array<string, mixed>>
     */
    public function commentThread(array $comments): array
    {
        $byParent = [];
        $roots = [];
        foreach ($comments as $comment) {
            $parentId = $comment->getParent()?->getId();
            if (null === $parentId) {
                $roots[] = $comment;
            } else {
                $byParent[$parentId][] = $comment;
            }
        }

        return array_map(function (Comment $comment) use ($byParent): array {
            $payload = $this->comment($comment);
            $payload['replies'] = array_map(
                fn (Comment $reply): array => $this->comment($reply),
                $byParent[$comment->getId()] ?? [],
            );

            return $payload;
        }, $roots);
    }

    /** @return array<string, mixed> */
    public function comment(Comment $comment): array
    {
        return [
            'id' => $comment->getId(),
            'ideaId' => $comment->getIdea()?->getId(),
            'author' => $this->user($comment->getAuthor()),
            'body' => $comment->getBody(),
            'parentId' => $comment->getParent()?->getId(),
            'createdAt' => $this->date($comment->getCreatedAt()),
            'editedAt' => $this->date($comment->getEditedAt()),
            'replies' => [],
            'permissions' => [
                'edit' => $this->security->isGranted(CommentVoter::EDIT, $comment),
                'delete' => $this->security->isGranted(CommentVoter::DELETE, $comment),
            ],
        ];
    }

    /**
     * Vocabulary the UI needs to render pickers and badges without hard-coding enum values.
     *
     * @return array<string, mixed>
     */
    public function vocabulary(): array
    {
        return [
            'ideaStatuses' => array_map(
                fn (IdeaStatus $status): array => $this->enum($status),
                IdeaStatus::cases(),
            ),
            'projectStatuses' => array_map(
                fn (ProjectStatus $status): array => $this->enum($status),
                ProjectStatus::cases(),
            ),
            'projectRoles' => array_map(
                fn (ProjectRole $role): array => $this->enum($role) + ['description' => $role->description()],
                ProjectRole::cases(),
            ),
            'attachmentKinds' => array_map(
                fn (AttachmentKind $kind): array => $this->enum($kind),
                AttachmentKind::cases(),
            ),
        ];
    }

    /**
     * Bulk-loads the aggregates an idea payload needs: vote tallies, the viewer's own votes
     * and comment counts, for the given ideas *and* their versions.
     *
     * @param list<Idea> $ideas
     *
     * @return array{tallies: array<int, array{up: int, down: int, score: int}>, myVotes: array<int, int>, commentCounts: array<int, int>}
     */
    public function buildContext(array $ideas): array
    {
        $ids = [];
        foreach ($ideas as $idea) {
            if (null !== $idea->getId()) {
                $ids[] = $idea->getId();
            }
            foreach ($idea->getChildren() as $child) {
                if (null !== $child->getId()) {
                    $ids[] = $child->getId();
                }
            }
        }
        $ids = array_values(array_unique($ids));

        $viewer = $this->currentUser();

        return [
            'tallies' => $this->votes->tallyForIdeas($ids),
            'myVotes' => null !== $viewer ? $this->votes->votesByUser($viewer, $ids) : [],
            'commentCounts' => $this->comments->countForIdeas($ids),
        ];
    }

    private function currentUser(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }

    /** @return array{value: string, label: string} */
    private function enum(IdeaStatus|ProjectStatus|ProjectRole|AttachmentKind $case): array
    {
        return ['value' => $case->value, 'label' => $case->label()];
    }

    private function enumOrNull(IdeaStatus|ProjectStatus|ProjectRole|AttachmentKind|null $case): ?array
    {
        return null !== $case ? $this->enum($case) : null;
    }

    private function date(?\DateTimeImmutable $date): ?string
    {
        return $date?->format(\DateTimeInterface::ATOM);
    }
}
