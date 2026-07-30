<?php

declare(strict_types=1);

namespace App\Services\Review;

use App\Services\Connectors\GitHubConnector;

/**
 * Orchestrates an agentic PR review: resolve the repo, apply skip rules, check
 * the PR out in an isolated worktree, run Claude, and post the result.
 */
class ReviewService
{
    public function __construct(
        private readonly GitHubConnector $github,
        private readonly GitWorktree $worktree,
        private readonly ClaudeRunner $claude,
    ) {}

    public function review(PullRequestRef $pr, bool $force = false): ReviewOutcome
    {
        $repoPath = $this->repoPath($pr);
        if ($repoPath === null) {
            return ReviewOutcome::skipped("{$pr->slug()} is not in the review routing table (config/review.php).");
        }

        $meta = $this->github->getPullRequest($pr);

        $login = $this->github->authenticatedLogin();
        if ($login !== null && $login === $meta->authorLogin) {
            return ReviewOutcome::skipped('PR was opened by the reviewer account.');
        }

        if (! $force && $this->github->hasCompletedReview($pr)) {
            return ReviewOutcome::skipped('PR already has a completed review (use --force to re-review).');
        }

        $diff = $this->github->getDiff($pr);
        $handle = $this->worktree->prepare($pr, $repoPath);

        try {
            $result = $this->claude->review($handle['dir'], $this->buildPrompt($pr, $meta, $diff));

            $posted = false;
            if ($this->postEnabled()) {
                $this->github->postReview($pr, $result);
                $posted = true;
            }

            return ReviewOutcome::reviewed($result, $posted);
        } finally {
            $this->worktree->remove($repoPath, $handle['dir'], $handle['branch']);
        }
    }

    private function repoPath(PullRequestRef $pr): ?string
    {
        $repos = config('review.repos');
        if (! is_array($repos)) {
            return null;
        }
        $path = $repos[$pr->slug()] ?? null;

        return is_string($path) && $path !== '' ? $path : null;
    }

    private function postEnabled(): bool
    {
        return (bool) config('review.post_enabled', true);
    }

    private function buildPrompt(PullRequestRef $pr, PullRequestMeta $meta, string $diff): string
    {
        $focus = config('review.focus');
        $focus = is_string($focus) && $focus !== '' ? "\nRepo focus: {$focus}" : '';

        $diff = mb_strlen($diff) > 60000
            ? mb_substr($diff, 0, 60000)."\n\n[diff truncated]"
            : $diff;

        return <<<PROMPT
        You are a senior code reviewer. Review pull request #{$pr->number} of {$pr->slug()}
        ("{$meta->title}"). The PR head is checked out in the current directory — use
        Read/Grep/Glob to inspect surrounding code. Judge correctness, security, and scope;
        prefer few high-signal findings over many nits. Only cite lines present in the diff.{$focus}

        The unified diff:
        ---
        {$diff}
        ---

        Output rules (STRICT):
        - Respond with ONE JSON object and nothing else. No prose, no code fences.
        - Shape: {"summary": string, "findings": [{"path": string, "line": number, "severity": "blocker"|"warning"|"nit", "comment": string}]}
        - "path" is repo-relative; "line" is the line number in the NEW version of the file.
        - Severity: "blocker" = a major issue that must be fixed before merge (real bug, security,
          correctness/data risk, broken build); "warning" = minor, should fix but not blocking;
          "nit" = trivial/style. Be honest and strict about what is truly a blocker.
        - If there are no findings, return an empty "findings" array.
        PROMPT;
    }
}
