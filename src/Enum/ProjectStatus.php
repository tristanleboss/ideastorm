<?php

declare(strict_types=1);

namespace App\Enum;

enum ProjectStatus: string
{
    case Draft = 'draft';
    case Open = 'open';
    case Closed = 'closed';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Open => 'Open for ideas',
            self::Closed => 'Closed for new ideas',
            self::Archived => 'Archived',
        };
    }

    /** Whether the project still accepts new ideas. */
    public function acceptsIdeas(): bool
    {
        return self::Open === $this;
    }
}
