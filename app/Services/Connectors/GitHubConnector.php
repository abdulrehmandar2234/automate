<?php

declare(strict_types=1);

namespace App\Services\Connectors;

use App\Enums\Verdict;
use App\Services\Review\Finding;
use App\Services\Review\PullRequestMeta;
use App\Services\Review\PullRequestRef;
use App\Services\Review\ReviewResult;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class GitHubConnector implements Connector
{
    private const BASE_URL = 'https://api.github.com';

    public function key(): string
    {
        return 'github';
    }

    public function configured(): bool
    {
        return filled($this->token());
    }

    /** The login that owns the configured token (for self-PR detection). */
    public function authenticatedLogin(): ?string
    {
        $login = $this->client()->get(self::BASE_URL.'/user')->throw()->json('login');

        return is_string($login) ? $login : null;
    }

    public function getPullRequest(PullRequestRef $pr): PullRequestMeta
    {
        $data = $this->client()
            ->get(self::BASE_URL."/repos/{$pr->owner}/{$pr->repo}/pulls/{$pr->number}")
            ->throw()
            ->json();

        $data = is_array($data) ? $data : [];

        return new PullRequestMeta(
            authorLogin: (string) data_get($data, 'user.login', ''),
            title: (string) data_get($data, 'title', ''),
            body: (string) data_get($data, 'body', ''),
            baseRef: (string) data_get($data, 'base.ref', ''),
            headRef: (string) data_get($data, 'head.ref', ''),
        );
    }

    /** True if the PR already has a completed (approve/changes) review. */
    public function hasCompletedReview(PullRequestRef $pr): bool
    {
        $reviews = $this->client()
            ->get(self::BASE_URL."/repos/{$pr->owner}/{$pr->repo}/pulls/{$pr->number}/reviews")
            ->throw()
            ->json();

        if (! is_array($reviews)) {
            return false;
        }

        foreach ($reviews as $review) {
            $state = data_get($review, 'state');
            if ($state === 'APPROVED' || $state === 'CHANGES_REQUESTED') {
                return true;
            }
        }

        return false;
    }

    /** The PR's unified diff. */
    public function getDiff(PullRequestRef $pr): string
    {
        return $this->client()
            ->withHeaders(['Accept' => 'application/vnd.github.v3.diff'])
            ->get(self::BASE_URL."/repos/{$pr->owner}/{$pr->repo}/pulls/{$pr->number}")
            ->throw()
            ->body();
    }

    /** Post the review to GitHub, falling back to a plain review if inline comments are rejected. */
    public function postReview(PullRequestRef $pr, ReviewResult $result): void
    {
        $endpoint = self::BASE_URL."/repos/{$pr->owner}/{$pr->repo}/pulls/{$pr->number}/reviews";
        $comments = $this->inlineComments($result->findings);

        $response = $this->client()->post($endpoint, [
            'event' => $result->verdict->githubEvent(),
            'body' => $this->reviewBody($result),
            'comments' => $comments,
        ]);

        if ($response->successful() || $comments === []) {
            $response->throw();

            return;
        }

        // Inline anchoring rejected (e.g. a line isn't in the diff) — retry with
        // every finding folded into the body so nothing is lost.
        $this->client()->post($endpoint, [
            'event' => $result->verdict === Verdict::Approve ? 'COMMENT' : $result->verdict->githubEvent(),
            'body' => $this->foldedBody($result),
        ])->throw();
    }

    private function token(): string
    {
        $token = config('services.github.token');

        return is_string($token) ? $token : '';
    }

    private function client(): PendingRequest
    {
        return Http::withToken($this->token())
            ->acceptJson()
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
            ->timeout(30);
    }

    /**
     * @param  array<int, Finding>  $findings
     * @return array<int, array{path: string, line: int, side: string, body: string}>
     */
    private function inlineComments(array $findings): array
    {
        $comments = [];
        foreach ($findings as $f) {
            if ($f->line === null) {
                continue;
            }
            $comments[] = [
                'path' => $f->path,
                'line' => $f->line,
                'side' => 'RIGHT',
                'body' => $f->severity->emoji().' '.$f->comment,
            ];
        }

        return $comments;
    }

    private function reviewBody(ReviewResult $result): string
    {
        $lines = ['**Automated review**', '', $result->summary];

        foreach ($result->findings as $f) {
            if ($f->line === null) {
                $lines[] = '- '.$f->severity->emoji().' '.$f->comment;
            }
        }

        return implode("\n", $lines);
    }

    private function foldedBody(ReviewResult $result): string
    {
        $lines = ['**Automated review**', '', $result->summary, '', '**Findings:**'];

        foreach ($result->findings as $f) {
            $loc = $f->line !== null ? " (`{$f->path}:{$f->line}`)" : " (`{$f->path}`)";
            $lines[] = '- '.$f->severity->emoji().$loc.': '.$f->comment;
        }

        return implode("\n", $lines);
    }
}
