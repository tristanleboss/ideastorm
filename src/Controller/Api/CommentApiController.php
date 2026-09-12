<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Comment;
use App\Repository\CommentRepository;
use App\Repository\IdeaRepository;
use App\Security\Voter\CommentVoter;
use App\Security\Voter\IdeaVoter;
use App\Service\ApiPresenter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** The discussion attached to an idea. Reading is public; posting needs an account. */
#[Route('/api', name: 'api_comment_')]
class CommentApiController extends AbstractApiController
{
    public function __construct(
        private readonly IdeaRepository $ideas,
        private readonly CommentRepository $comments,
        private readonly ApiPresenter $presenter,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/ideas/{id}/comments', name: 'index', methods: ['GET'])]
    public function index(int $id): JsonResponse
    {
        $idea = $this->ideas->find($id) ?? throw $this->createNotFoundException('That idea does not exist.');

        return $this->json(['comments' => $this->presenter->commentThread($this->comments->findForIdea($idea))]);
    }

    #[Route('/ideas/{id}/comments', name: 'create', methods: ['POST'])]
    public function create(
        int $id,
        Request $request,
        ValidatorInterface $validator,
        #[Autowire(service: 'limiter.comment_posting')] RateLimiterFactoryInterface $commentPostingLimiter,
    ): JsonResponse {
        $user = $this->requireUser();
        $idea = $this->ideas->find($id) ?? throw $this->createNotFoundException('That idea does not exist.');
        $this->denyAccessUnlessGranted(IdeaVoter::COMMENT, $idea);

        $limit = $commentPostingLimiter->create((string) $user->getId())->consume();
        if (!$limit->isAccepted()) {
            return $this->error(
                'You are posting very quickly. Please wait a moment.',
                Response::HTTP_TOO_MANY_REQUESTS,
                'rate_limited',
                ['retryAfter' => $limit->getRetryAfter()->getTimestamp()],
            );
        }

        $payload = $this->payload($request);

        $comment = new Comment();
        $comment->setIdea($idea)
            ->setAuthor($user)
            ->setBody((string) $this->stringField($payload, 'body', ''));

        $parentId = $this->intField($payload, 'parentId');
        if (null !== $parentId) {
            $parent = $this->comments->find($parentId);
            if (null === $parent || $parent->getIdea() !== $idea) {
                return $this->error('The comment you are replying to is not on this idea.');
            }
            // Replies stay one level deep: a reply to a reply attaches to the same root.
            $comment->setParent($parent->getParent() ?? $parent);
        }

        $violations = $validator->validate($comment);
        if (\count($violations) > 0) {
            return $this->validationError($violations);
        }

        $idea->addComment($comment);
        $this->entityManager->persist($comment);
        $this->entityManager->flush();

        return $this->json(['comment' => $this->presenter->comment($comment)], Response::HTTP_CREATED);
    }

    #[Route('/comments/{id}', name: 'update', methods: ['PATCH'])]
    public function update(int $id, Request $request, ValidatorInterface $validator): JsonResponse
    {
        $comment = $this->comments->find($id) ?? throw $this->createNotFoundException('That comment does not exist.');
        $this->denyAccessUnlessGranted(CommentVoter::EDIT, $comment);

        $comment->setBody((string) $this->stringField($this->payload($request), 'body', ''));

        $violations = $validator->validate($comment);
        if (\count($violations) > 0) {
            return $this->validationError($violations);
        }

        $comment->markEdited();
        $this->entityManager->flush();

        return $this->json(['comment' => $this->presenter->comment($comment)]);
    }

    #[Route('/comments/{id}', name: 'delete', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $comment = $this->comments->find($id) ?? throw $this->createNotFoundException('That comment does not exist.');
        $this->denyAccessUnlessGranted(CommentVoter::DELETE, $comment);

        $this->entityManager->remove($comment);
        $this->entityManager->flush();

        return $this->json(['deleted' => true]);
    }
}
