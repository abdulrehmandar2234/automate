<?php

declare(strict_types=1);

namespace App\Services\Review;

/** An identified GitHub pull request. */
final readonly class PullRequestRef
{
    public function __construct(
        public string $owner,
        public string $repo,
        public int $number,
    ) {}

    /** Parse a GitHub PR URL into a ref, or null if it isn't one. */
    public static function fromUrl(string $url): ?self
    {
        if (preg_match('#github\.com/([\w.-]+)/([\w.-]+)/pull/(\d+)#i', $url, $m) !== 1) {
            return null;
        }

        return new self($m[1], $m[2], (int) $m[3]);
    }

    public function slug(): string
    {
        return "{$this->owner}/{$this->repo}";
    }

    public function url(): string
    {
        return "https://github.com/{$this->owner}/{$this->repo}/pull/{$this->number}";
    }
}
