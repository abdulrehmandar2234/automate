<?php

declare(strict_types=1);

namespace App\Services\Review;

/** The result of asking the engine to review a PR: reviewed, or skipped. */
final readonly class ReviewOutcome
{
    private function __construct(
        public bool $reviewed,
        public ?ReviewResult $result,
        public ?string $skipReason,
        public bool $posted,
    ) {}

    public static function reviewed(ReviewResult $result, bool $posted): self
    {
        return new self(true, $result, null, $posted);
    }

    public static function skipped(string $reason): self
    {
        return new self(false, null, $reason, false);
    }
}
