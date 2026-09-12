<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Enum\ProjectRole;
use App\Enum\ProjectStatus;
use PHPUnit\Framework\TestCase;

class ProjectRoleTest extends TestCase
{
    public function testOnlyOwnersAndLeadsManageMembers(): void
    {
        self::assertTrue(ProjectRole::Owner->canManageMembers());
        self::assertTrue(ProjectRole::Lead->canManageMembers());

        self::assertFalse(ProjectRole::Contributor->canManageMembers());
        self::assertFalse(ProjectRole::Reviewer->canManageMembers());
        self::assertFalse(ProjectRole::Observer->canManageMembers());
    }

    public function testReviewersCanTriageButNotManageMembers(): void
    {
        self::assertTrue(ProjectRole::Reviewer->canTriage());
        self::assertFalse(ProjectRole::Reviewer->canManageMembers());
    }

    public function testContributorsAndObserversCannotTriage(): void
    {
        self::assertFalse(ProjectRole::Contributor->canTriage());
        self::assertFalse(ProjectRole::Observer->canTriage());
    }

    public function testOnlyOpenProjectsAcceptIdeas(): void
    {
        self::assertTrue(ProjectStatus::Open->acceptsIdeas());

        self::assertFalse(ProjectStatus::Draft->acceptsIdeas());
        self::assertFalse(ProjectStatus::Closed->acceptsIdeas());
        self::assertFalse(ProjectStatus::Archived->acceptsIdeas());
    }

    public function testEveryRoleAndStatusHasAReadableLabel(): void
    {
        foreach (ProjectRole::cases() as $role) {
            self::assertNotSame('', $role->label());
            self::assertNotSame('', $role->description());
        }

        foreach (ProjectStatus::cases() as $status) {
            self::assertNotSame('', $status->label());
        }
    }
}
