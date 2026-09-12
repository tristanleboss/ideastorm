<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Idea;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class IdeaVoter extends Voter
{
    public const EDIT = 'IDEA_EDIT';
    public const DELETE = 'IDEA_DELETE';
    /** Changing an idea's status — reserved for the people who triage the project. */
    public const TRIAGE = 'IDEA_TRIAGE';
    /** Branching a version off an idea. */
    public const BRANCH = 'IDEA_BRANCH';
    public const COMMENT = 'IDEA_COMMENT';
    public const VOTE = 'IDEA_VOTE';

    public function __construct(private readonly Security $security)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::EDIT, self::DELETE, self::TRIAGE, self::BRANCH, self::COMMENT, self::VOTE], true)
            && $subject instanceof Idea;
    }

    /** @param Idea $subject */
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        if ($this->security->isGranted('ROLE_ADMIN')) {
            return true;
        }

        $project = $subject->getProject();
        $role = $project?->getRoleFor($user);
        $isAuthor = $subject->getAuthor() === $user;
        $steersProject = null !== $role && $role->canManageMembers();

        return match ($attribute) {
            self::EDIT, self::DELETE => $isAuthor || $steersProject,
            self::TRIAGE => null !== $role && $role->canTriage(),
            // A version keeps the parent's feature, so it belongs to the same project and is
            // only worth branching while that project still takes ideas. One level only:
            // versions of versions turn a discussion into a maze.
            self::BRANCH => !$subject->isVersion() && true === $project?->getStatus()->acceptsIdeas(),
            self::COMMENT, self::VOTE => true,
            default => false,
        };
    }
}
