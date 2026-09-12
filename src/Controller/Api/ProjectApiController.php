<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Project;
use App\Entity\ProjectMembership;
use App\Enum\ProjectRole;
use App\Enum\ProjectStatus;
use App\Repository\IdeaRepository;
use App\Repository\ProjectMembershipRepository;
use App\Repository\ProjectRepository;
use App\Repository\UserRepository;
use App\Security\Voter\ProjectVoter;
use App\Service\ApiPresenter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/projects', name: 'api_project_')]
class ProjectApiController extends AbstractApiController
{
    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly ApiPresenter $presenter,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /** The project directory shown on the landing page. Open to visitors. */
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $search = $request->query->getString('q');
        $projects = $this->projects->findForListing('' !== $search ? $search : null);
        $counts = $this->projects->countsByProject();

        return $this->json([
            'projects' => array_map(
                fn (Project $project): array => $this->presenter->project($project, $counts[$project->getId()] ?? []),
                $projects,
            ),
        ]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request, SluggerInterface $slugger, ValidatorInterface $validator): JsonResponse
    {
        $user = $this->requireUser();
        $payload = $this->payload($request);

        $project = new Project();
        $project->setName((string) $this->stringField($payload, 'name', ''));
        $project->setSummary((string) $this->stringField($payload, 'summary', ''));
        $project->setDescription($this->stringField($payload, 'description'));
        $project->setSlug($this->uniqueSlug($slugger, $project->getName()));

        $status = ProjectStatus::tryFrom((string) $this->stringField($payload, 'status', ProjectStatus::Open->value));
        $project->setStatus($status ?? ProjectStatus::Open);

        $accent = $this->stringField($payload, 'accentColor');
        if (null !== $accent) {
            $project->setAccentColor($accent);
        }

        $violations = $validator->validate($project);
        if (\count($violations) > 0) {
            return $this->validationError($violations);
        }

        // Whoever creates a project owns it, otherwise nobody could manage its members.
        $project->addMembership(new ProjectMembership($project, $user, ProjectRole::Owner));

        $this->entityManager->persist($project);
        $this->entityManager->flush();

        return $this->json(
            ['project' => $this->presenter->project($project, ['ideas' => 0, 'members' => 1], withDetail: true)],
            Response::HTTP_CREATED,
        );
    }

    #[Route('/{slug}', name: 'show', methods: ['GET'])]
    public function show(string $slug, IdeaRepository $ideas, ProjectMembershipRepository $memberships): JsonResponse
    {
        $project = $this->findProject($slug);
        $members = $memberships->findForProject($project);
        $countsByStatus = $ideas->countByStatus($project);

        return $this->json([
            'project' => $this->presenter->project(
                $project,
                ['ideas' => array_sum($countsByStatus), 'members' => \count($members)],
                withDetail: true,
            ),
            'members' => array_map(
                fn (ProjectMembership $membership): array => $this->presenter->membership($membership),
                $members,
            ),
            'ideaCountsByStatus' => $countsByStatus,
        ]);
    }

    #[Route('/{slug}', name: 'update', methods: ['PATCH'])]
    public function update(string $slug, Request $request, ValidatorInterface $validator): JsonResponse
    {
        $project = $this->findProject($slug);
        $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $project);

        $payload = $this->payload($request);

        if (\array_key_exists('name', $payload)) {
            $project->setName((string) $this->stringField($payload, 'name', $project->getName()));
        }
        if (\array_key_exists('summary', $payload)) {
            $project->setSummary((string) $this->stringField($payload, 'summary', $project->getSummary()));
        }
        if (\array_key_exists('description', $payload)) {
            $project->setDescription($this->stringField($payload, 'description'));
        }
        if (\array_key_exists('accentColor', $payload)) {
            $project->setAccentColor((string) $this->stringField($payload, 'accentColor', $project->getAccentColor()));
        }
        if (\array_key_exists('status', $payload)) {
            $status = ProjectStatus::tryFrom((string) $this->stringField($payload, 'status', ''));
            if (null === $status) {
                return $this->error('Unknown project status.');
            }
            $project->setStatus($status);
        }

        $violations = $validator->validate($project);
        if (\count($violations) > 0) {
            return $this->validationError($violations);
        }

        $project->touch();
        $this->entityManager->flush();

        return $this->json(['project' => $this->presenter->project($project, withDetail: true)]);
    }

    /** Members and their roles. Public, so visitors can see who is behind a project. */
    #[Route('/{slug}/members', name: 'members', methods: ['GET'])]
    public function members(string $slug, ProjectMembershipRepository $memberships): JsonResponse
    {
        $project = $this->findProject($slug);

        return $this->json([
            'members' => array_map(
                fn (ProjectMembership $membership): array => $this->presenter->membership($membership),
                $memberships->findForProject($project),
            ),
        ]);
    }

    #[Route('/{slug}/members', name: 'member_add', methods: ['POST'])]
    public function addMember(
        string $slug,
        Request $request,
        UserRepository $users,
        ProjectMembershipRepository $memberships,
    ): JsonResponse {
        $project = $this->findProject($slug);
        $this->denyAccessUnlessGranted(ProjectVoter::MANAGE_MEMBERS, $project);

        $payload = $this->payload($request);
        $email = $this->stringField($payload, 'email');
        $userId = $this->intField($payload, 'userId');

        $user = null !== $userId ? $users->find($userId) : (null !== $email ? $users->findOneByEmail($email) : null);
        if (null === $user) {
            return $this->error('No account matches that person. They need to sign up first.', Response::HTTP_NOT_FOUND, 'user_not_found');
        }

        if (null !== $memberships->findOneForProjectAndUser($project, $user)) {
            return $this->error(\sprintf('%s is already assigned to this project.', $user->getDisplayName()), Response::HTTP_CONFLICT, 'already_member');
        }

        $role = ProjectRole::tryFrom((string) $this->stringField($payload, 'role', ProjectRole::Contributor->value));
        if (null === $role) {
            return $this->error('Unknown project role.');
        }

        $membership = new ProjectMembership($project, $user, $role);
        $membership->setFocus($this->stringField($payload, 'focus'));
        $project->addMembership($membership);

        $this->entityManager->persist($membership);
        $project->touch();
        $this->entityManager->flush();

        return $this->json(['member' => $this->presenter->membership($membership)], Response::HTTP_CREATED);
    }

    #[Route('/{slug}/members/{membershipId}', name: 'member_update', methods: ['PATCH'])]
    public function updateMember(string $slug, int $membershipId, Request $request, ProjectMembershipRepository $memberships): JsonResponse
    {
        $project = $this->findProject($slug);
        $this->denyAccessUnlessGranted(ProjectVoter::MANAGE_MEMBERS, $project);

        $membership = $memberships->find($membershipId);
        if (null === $membership || $membership->getProject() !== $project) {
            throw $this->createNotFoundException('That assignment does not exist.');
        }

        $payload = $this->payload($request);

        if (\array_key_exists('role', $payload)) {
            $role = ProjectRole::tryFrom((string) $this->stringField($payload, 'role', ''));
            if (null === $role) {
                return $this->error('Unknown project role.');
            }

            if (ProjectRole::Owner === $membership->getRole() && ProjectRole::Owner !== $role && $this->countOwners($project) <= 1) {
                return $this->error('A project needs at least one owner. Promote somebody else first.', Response::HTTP_CONFLICT, 'last_owner');
            }

            $membership->setRole($role);
        }

        if (\array_key_exists('focus', $payload)) {
            $membership->setFocus($this->stringField($payload, 'focus'));
        }

        $project->touch();
        $this->entityManager->flush();

        return $this->json(['member' => $this->presenter->membership($membership)]);
    }

    #[Route('/{slug}/members/{membershipId}', name: 'member_remove', methods: ['DELETE'])]
    public function removeMember(string $slug, int $membershipId, ProjectMembershipRepository $memberships): JsonResponse
    {
        $project = $this->findProject($slug);
        $this->denyAccessUnlessGranted(ProjectVoter::MANAGE_MEMBERS, $project);

        $membership = $memberships->find($membershipId);
        if (null === $membership || $membership->getProject() !== $project) {
            throw $this->createNotFoundException('That assignment does not exist.');
        }

        if (ProjectRole::Owner === $membership->getRole() && $this->countOwners($project) <= 1) {
            return $this->error('A project needs at least one owner. Promote somebody else first.', Response::HTTP_CONFLICT, 'last_owner');
        }

        $project->removeMembership($membership);
        $this->entityManager->remove($membership);
        $project->touch();
        $this->entityManager->flush();

        return $this->json(['removed' => true]);
    }

    private function countOwners(Project $project): int
    {
        $owners = 0;
        foreach ($project->getMemberships() as $membership) {
            if (ProjectRole::Owner === $membership->getRole()) {
                ++$owners;
            }
        }

        return $owners;
    }

    private function findProject(string $slug): Project
    {
        $project = $this->projects->findOneBySlug($slug);
        if (null === $project) {
            throw $this->createNotFoundException(\sprintf('No project named "%s".', $slug));
        }

        return $project;
    }

    /** Derives a URL-safe slug from the project name, adding -2, -3… on a collision. */
    private function uniqueSlug(SluggerInterface $slugger, string $name): string
    {
        $base = strtolower((string) $slugger->slug($name));
        $base = '' !== $base ? $base : 'project';

        $candidate = $base;
        $suffix = 1;
        while (null !== $this->projects->findOneBySlug($candidate)) {
            $candidate = $base.'-'.++$suffix;
        }

        return $candidate;
    }
}
