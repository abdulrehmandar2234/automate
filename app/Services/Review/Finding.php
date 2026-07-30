<?php

declare(strict_types=1);

namespace App\Services\Review;

use App\Enums\Severity;

/** A single review finding on a specific line of the PR. */
final readonly class Finding
{
    public function __construct(
        public string $path,
        public ?int $line,
        public Severity $severity,
        public string $comment,
    ) {}
}
