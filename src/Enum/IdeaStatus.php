<?php

declare(strict_types=1);

namespace App\Enum;

enum IdeaStatus: string
{
    case Proposed = 'proposed';
    case UnderReview = 'under_review';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Shipped = 'shipped';

    public function label(): string
    {
        return match ($this) {
            self::Proposed => 'Proposed',
            self::UnderReview => 'Under review',
            self::Accepted => 'Accepted',
            self::Rejected => 'Not moving forward',
            self::Shipped => 'Shipped',
        };
    }
}
