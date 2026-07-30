<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Review\PullRequestRef;
use App\Services\Review\ReviewResult;
use App\Services\Review\ReviewService;
use Illuminate\Console\Command;
use Throwable;

class ReviewPr extends Command
{
    protected $signature = 'review:pr {url : GitHub pull request URL} {--force : Re-review even if already reviewed}';

    protected $description = 'Run an agentic Claude review on a GitHub pull request';

    public function handle(ReviewService $service): int
    {
        $url = (string) $this->argument('url');
        $pr = PullRequestRef::fromUrl($url);

        if (! $pr instanceof PullRequestRef) {
            $this->error("Not a GitHub PR URL: {$url}");

            return self::FAILURE;
        }

        $this->info("Reviewing {$pr->slug()}#{$pr->number} …");

        try {
            $outcome = $service->review($pr, (bool) $this->option('force'));
        } catch (Throwable $e) {
            $this->error("Review failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        if (! $outcome->reviewed) {
            $this->warn("Skipped: {$outcome->skipReason}");

            return self::SUCCESS;
        }

        $result = $outcome->result;
        if (! $result instanceof ReviewResult) {
            return self::SUCCESS;
        }

        $this->newLine();
        $this->line("<options=bold>Verdict:</> {$result->verdict->label()}".
            ($result->verdict->value === 'approve' && $result->hasFindings() ? ' (with comments)' : ''));
        $this->line("<options=bold>Summary:</> {$result->summary}");

        if ($result->hasFindings()) {
            $this->newLine();
            $rows = [];
            foreach ($result->findings as $f) {
                $rows[] = [$f->severity->value, $f->path.($f->line !== null ? ":{$f->line}" : ''), $f->comment];
            }
            $this->table(['Severity', 'Location', 'Comment'], $rows);
        }

        $this->newLine();
        $this->info($outcome->posted ? 'Posted to GitHub.' : 'Dry-run — not posted to GitHub (REVIEW_POST_ENABLED=false).');

        return self::SUCCESS;
    }
}
