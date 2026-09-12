<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Attachment;
use App\Entity\Idea;
use App\Entity\Vote;
use App\Enum\IdeaStatus;
use App\Repository\AttachmentRepository;
use App\Repository\IdeaRepository;
use App\Repository\ProjectRepository;
use App\Repository\VoteRepository;
use App\Security\Voter\IdeaVoter;
use App\Security\Voter\ProjectVoter;
use App\Service\ApiPresenter;
use App\Service\AttachmentRejectedException;
use App\Service\AttachmentStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Ideas, their versions, their attachments and their votes.
 *
 * Idea submission is the one endpoint that accepts multipart/form-data: the composer posts
 * the text together with everything the visitor recorded, pasted or dropped in a single
 * request, so an idea is never stored half-finished.
 */
#[Route('/api', name: 'api_idea_')]
class IdeaApiController extends AbstractApiController
{
    public function __construct(
        private readonly IdeaRepository $ideas,
        private readonly ApiPresenter $presenter,
        private readonly EntityManagerInterface $entityManager,
        private readonly AttachmentStorage $storage,
    ) {
    }

    #[Route('/projects/{slug}/ideas', name: 'index', methods: ['GET'])]
    public function index(string $slug, Request $request, ProjectRepository $projects): JsonResponse
    {
        $project = $projects->findOneBySlug($slug);
        if (null === $project) {
            throw $this->createNotFoundException(\sprintf('No project named "%s".', $slug));
        }

        $statusFilter = $request->query->getString('status');
        $status = '' !== $statusFilter && 'all' !== $statusFilter ? IdeaStatus::tryFrom($statusFilter) : null;
        if ('' !== $statusFilter && 'all' !== $statusFilter && null === $status) {
            return $this->error('Unknown idea status filter.');
        }

        $search = $request->query->getString('q');
        $sort = $request->query->getString('sort', IdeaRepository::SORT_RECENT);

        $ideas = $this->ideas->findBoardForProject($project, $status, '' !== $search ? $search : null);

        return $this->json(['ideas' => $this->presenter->ideaCollection($ideas, $sort)]);
    }

    /**
     * Submits an idea, or a version of an existing one when `versionOf` is set.
     *
     * Accepts text plus any number of files in `files[]`; `durations[]` carries the length in
     * seconds of each recording, in the same order, for the ones the browser could measure.
     */
    #[Route('/projects/{slug}/ideas', name: 'create', methods: ['POST'])]
    public function create(
        string $slug,
        Request $request,
        ProjectRepository $projects,
        ValidatorInterface $validator,
        #[Autowire(service: 'limiter.idea_submission')] RateLimiterFactoryInterface $ideaSubmissionLimiter,
    ): JsonResponse {
        $user = $this->requireUser();

        $project = $projects->findOneBySlug($slug);
        if (null === $project) {
            throw $this->createNotFoundException(\sprintf('No project named "%s".', $slug));
        }

        if (!$this->isGranted(ProjectVoter::SUBMIT_IDEA, $project)) {
            return $this->error(
                \sprintf('This project is %s, so it is not taking new ideas right now.', strtolower($project->getStatus()->label())),
                Response::HTTP_FORBIDDEN,
                'project_closed',
            );
        }

        $limit = $ideaSubmissionLimiter->create((string) $user->getId())->consume();
        if (!$limit->isAccepted()) {
            return $this->error(
                'That is a lot of ideas in a short time. Please wait a moment before submitting another.',
                Response::HTTP_TOO_MANY_REQUESTS,
                'rate_limited',
                ['retryAfter' => $limit->getRetryAfter()->getTimestamp()],
            );
        }

        $payload = $this->payload($request);

        $idea = new Idea();
        $idea->setProject($project)
            ->setAuthor($user)
            ->setTitle((string) $this->stringField($payload, 'title', ''))
            ->setBody($this->stringField($payload, 'body'));

        $parentId = $this->intField($payload, 'versionOf');
        if (null !== $parentId) {
            $parent = $this->ideas->find($parentId);
            if (null === $parent || $parent->getProject() !== $project) {
                return $this->error('The idea you are branching from is not part of this project.');
            }
            if (!$this->isGranted(IdeaVoter::BRANCH, $parent)) {
                return $this->error('You cannot branch a version off that idea.', Response::HTTP_FORBIDDEN, 'forbidden');
            }

            $idea->setParent($parent);
            $idea->setVersionLabel($this->stringField($payload, 'versionLabel'));
        }

        $violations = $validator->validate($idea);
        if (\count($violations) > 0) {
            return $this->validationError($violations);
        }

        $files = $this->uploadedFiles($request);
        $durations = $this->durations($request);

        if ('' === (string) $idea->getBody() && [] === $files) {
            return $this->error('An idea needs a description, a recording or a file — something to go on.', Response::HTTP_UNPROCESSABLE_ENTITY, 'empty_idea');
        }

        $this->entityManager->persist($idea);

        $stored = [];
        try {
            foreach ($files as $index => $file) {
                $attachment = $this->storage->store($file, $idea, $user, $durations[$index] ?? null);
                $this->entityManager->persist($attachment);
                $stored[] = $attachment;
            }
        } catch (AttachmentRejectedException $e) {
            // All-or-nothing: nothing has been flushed yet, so drop the files that did make it
            // to disk rather than leaving an idea whose attachments silently went missing.
            foreach ($stored as $attachment) {
                $this->storage->remove($attachment);
            }
            $this->entityManager->clear();

            return $this->error($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY, 'attachment_rejected');
        }

        $project->touch();
        $this->entityManager->flush();

        return $this->json(['idea' => $this->presenter->ideaDetail($idea)], Response::HTTP_CREATED);
    }

    #[Route('/ideas/{id}', name: 'show', methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        $idea = $this->ideas->findOneWithDetails($id) ?? throw $this->createNotFoundException('That idea does not exist.');

        return $this->json(['idea' => $this->presenter->ideaDetail($idea)]);
    }

    #[Route('/ideas/{id}', name: 'update', methods: ['PATCH'])]
    public function update(int $id, Request $request, ValidatorInterface $validator): JsonResponse
    {
        $idea = $this->findIdea($id);
        $this->denyAccessUnlessGranted(IdeaVoter::EDIT, $idea);

        $payload = $this->payload($request);

        if (\array_key_exists('title', $payload)) {
            $idea->setTitle((string) $this->stringField($payload, 'title', $idea->getTitle()));
        }
        if (\array_key_exists('body', $payload)) {
            $idea->setBody($this->stringField($payload, 'body'));
        }
        if (\array_key_exists('versionLabel', $payload) && $idea->isVersion()) {
            $idea->setVersionLabel($this->stringField($payload, 'versionLabel'));
        }

        $violations = $validator->validate($idea);
        if (\count($violations) > 0) {
            return $this->validationError($violations);
        }

        $idea->touch();
        $this->entityManager->flush();

        return $this->json(['idea' => $this->presenter->ideaDetail($idea)]);
    }

    /** Moving an idea along the pipeline: proposed -> under review -> accepted/rejected/shipped. */
    #[Route('/ideas/{id}/status', name: 'status', methods: ['POST'])]
    public function changeStatus(int $id, Request $request): JsonResponse
    {
        $idea = $this->findIdea($id);
        $this->denyAccessUnlessGranted(IdeaVoter::TRIAGE, $idea);

        $status = IdeaStatus::tryFrom((string) $this->stringField($this->payload($request), 'status', ''));
        if (null === $status) {
            return $this->error('Unknown idea status.');
        }

        $idea->setStatus($status);
        $idea->touch();
        $this->entityManager->flush();

        return $this->json(['idea' => $this->presenter->ideaDetail($idea)]);
    }

    #[Route('/ideas/{id}', name: 'delete', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $idea = $this->findIdea($id);
        $this->denyAccessUnlessGranted(IdeaVoter::DELETE, $idea);

        // Collect the files first: once the rows are gone we can no longer find them.
        $paths = [];
        foreach ($idea->getAttachments() as $attachment) {
            $paths[] = $attachment;
        }
        foreach ($idea->getChildren() as $child) {
            foreach ($child->getAttachments() as $attachment) {
                $paths[] = $attachment;
            }
        }

        $this->entityManager->remove($idea);
        $this->entityManager->flush();

        foreach ($paths as $attachment) {
            $this->storage->remove($attachment);
        }

        return $this->json(['deleted' => true]);
    }

    /** Adds attachments to an idea that already exists. */
    #[Route('/ideas/{id}/attachments', name: 'attachment_add', methods: ['POST'])]
    public function addAttachments(int $id, Request $request): JsonResponse
    {
        $idea = $this->findIdea($id);
        $this->denyAccessUnlessGranted(IdeaVoter::EDIT, $idea);

        $files = $this->uploadedFiles($request);
        if ([] === $files) {
            return $this->error('No file was received.');
        }

        $durations = $this->durations($request);
        $stored = [];

        try {
            foreach ($files as $index => $file) {
                $attachment = $this->storage->store($file, $idea, $this->currentUser(), $durations[$index] ?? null);
                $this->entityManager->persist($attachment);
                $stored[] = $attachment;
            }
        } catch (AttachmentRejectedException $e) {
            foreach ($stored as $attachment) {
                $this->storage->remove($attachment);
            }
            $this->entityManager->clear();

            return $this->error($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY, 'attachment_rejected');
        }

        $idea->touch();
        $this->entityManager->flush();

        return $this->json([
            'attachments' => array_map(fn (Attachment $a): array => $this->presenter->attachment($a), $stored),
        ], Response::HTTP_CREATED);
    }

    #[Route('/attachments/{id}', name: 'attachment_delete', methods: ['DELETE'])]
    public function deleteAttachment(int $id, AttachmentRepository $attachments): JsonResponse
    {
        $attachment = $attachments->find($id) ?? throw $this->createNotFoundException('That attachment does not exist.');
        $idea = $attachment->getIdea();
        if (null === $idea) {
            throw $this->createNotFoundException('That attachment does not exist.');
        }

        $this->denyAccessUnlessGranted(IdeaVoter::EDIT, $idea);

        $idea->removeAttachment($attachment);
        $this->entityManager->remove($attachment);
        $this->entityManager->flush();
        $this->storage->remove($attachment);

        return $this->json(['deleted' => true]);
    }

    /**
     * Casts or changes a vote. Voting the same way twice takes the vote back, which is what
     * people expect from an up/down control they can click again.
     */
    #[Route('/ideas/{id}/vote', name: 'vote', methods: ['POST'])]
    public function vote(int $id, Request $request, VoteRepository $votes): JsonResponse
    {
        $user = $this->requireUser();
        $idea = $this->findIdea($id);
        $this->denyAccessUnlessGranted(IdeaVoter::VOTE, $idea);

        $value = $this->intField($this->payload($request), 'value') ?? Vote::UP;
        if (!\in_array($value, [Vote::UP, Vote::DOWN, 0], true)) {
            return $this->error('A vote is either +1 or -1.');
        }

        $existing = $votes->findOneForIdeaAndUser($idea, $user);

        if (0 === $value || (null !== $existing && $existing->getValue() === $value)) {
            if (null !== $existing) {
                $this->entityManager->remove($existing);
                $this->entityManager->flush();
            }
        } elseif (null !== $existing) {
            $existing->setValue($value);
            $this->entityManager->flush();
        } else {
            $vote = new Vote($idea, $user, $value);
            $this->entityManager->persist($vote);
            $this->entityManager->flush();
        }

        // The tallies in the response come from a fresh aggregate query, so they already
        // reflect the vote that was just cast, changed or withdrawn.
        return $this->json(['idea' => $this->presenter->ideaDetail($idea)]);
    }

    private function findIdea(int $id): Idea
    {
        return $this->ideas->find($id) ?? throw $this->createNotFoundException('That idea does not exist.');
    }

    /**
     * Uploaded files under `files[]`, plus the single-file `file` field, as a flat list.
     *
     * @return list<UploadedFile>
     */
    private function uploadedFiles(Request $request): array
    {
        $candidates = array_merge(
            (array) $request->files->get('files', []),
            array_filter([$request->files->get('file')]),
        );

        $files = [];
        array_walk_recursive($candidates, static function ($file) use (&$files): void {
            if ($file instanceof UploadedFile) {
                $files[] = $file;
            }
        });

        return $files;
    }

    /**
     * Recording lengths in seconds, aligned by index with `files[]`.
     *
     * @return array<int, int|null>
     */
    private function durations(Request $request): array
    {
        $raw = $request->request->all()['durations'] ?? [];
        if (!\is_array($raw)) {
            return [];
        }

        $durations = [];
        foreach (array_values($raw) as $index => $value) {
            $durations[$index] = \is_numeric($value) && (int) $value > 0 ? (int) $value : null;
        }

        return $durations;
    }
}
