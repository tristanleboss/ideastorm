<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The hat somebody wears on a given project. Roles are per project, not global:
 * the same person can own one project and merely observe another.
 */
enum ProjectRole: string
{
    case Owner = 'owner';
    case Lead = 'lead';
    case Contributor = 'contributor';
    case Reviewer = 'reviewer';
    case Observer = 'observer';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Lead => 'Project lead',
            self::Contributor => 'Contributor',
            self::Reviewer => 'Reviewer',
            self::Observer => 'Observer',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Owner => 'Accountable for the project and its members.',
            self::Lead => 'Drives the day-to-day work and triages incoming ideas.',
            self::Contributor => 'Submits ideas and builds on the ideas of others.',
            self::Reviewer => 'Gives feedback and helps rank the shortlist.',
            self::Observer => 'Follows along without an active assignment.',
        };
    }

    /** Roles allowed to change an idea's status. */
    public function canTriage(): bool
    {
        return \in_array($this, [self::Owner, self::Lead, self::Reviewer], true);
    }

    /** Roles allowed to add or remove members. */
    public function canManageMembers(): bool
    {
        return \in_array($this, [self::Owner, self::Lead], true);
    }
}
