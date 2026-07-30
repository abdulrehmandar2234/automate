<?php

declare(strict_types=1);

namespace App\Services\Review;

use App\Enums\Verdict;

/** The parsed outcome of a Claude review. */
final readonly class ReviewResult
{
    /** @param  array<int, Finding>  $findings */
    public function __construct(
        public string $summary,
        public Verdict $verdict,
        public array $findings,
    ) {}

    public function hasFindings(): bool
    {
        return $this->findings !== [];
    }
}
