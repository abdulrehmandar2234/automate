<?php

declare(strict_types=1);

namespace App\Services\Review;

/** Metadata about a pull request, read from the GitHub API. */
final readonly class PullRequestMeta
{
    public function __construct(
        public string $authorLogin,
        public string $title,
        public string $body,
        public string $baseRef,
        public string $headRef,
    ) {}
}
