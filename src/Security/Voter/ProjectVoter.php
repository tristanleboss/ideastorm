<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Project;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Who may do what on a project. Reading is deliberately not covered here: the whole point
 * of IdeaStorm is that visitors can browse projects and ideas without an account.
 */
class ProjectVoter extends Voter
{
    public const EDIT = 'PROJECT_EDIT';
    public const MANAGE_MEMBERS = 'PROJECT_MANAGE_MEMBERS';
    public const SUBMIT_IDEA = 'PROJECT_SUBMIT_IDEA';

    public function __construct(private readonly Security $security)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::EDIT, self::MANAGE_MEMBERS, self::SUBMIT_IDEA], true)
            && $subject instanceof Project;
    }

    /** @param Project $subject */
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        if ($this->security->isGranted('ROLE_ADMIN')) {
            return true;
        }

        $role = $subject->getRoleFor($user);

        return match ($attribute) {
            self::EDIT, self::MANAGE_MEMBERS => null !== $role && $role->canManageMembers(),
            // Anybody with an account may submit to an open project, member or not — that is
            // what makes a project a place to collect ideas rather than a closed backlog.
            self::SUBMIT_IDEA => $subject->getStatus()->acceptsIdeas(),
            default => false,
        };
    }
}
