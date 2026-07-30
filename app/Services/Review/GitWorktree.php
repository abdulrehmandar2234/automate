<?php

declare(strict_types=1);

namespace App\Services\Review;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Checks a PR out into a throwaway git worktree that lives OUTSIDE the repo, so
 * the reviewer can read the PR's files without touching the working branch.
 */
class GitWorktree
{
    /**
     * Fetch the PR head into a dedicated branch and materialise it in an
     * external worktree. Returns [dir, branch]; always call remove() after.
     *
     * @return array{dir: string, branch: string}
     */
    public function prepare(PullRequestRef $pr, string $repoPath): array
    {
        $id = "pr-{$pr->number}-".Str::random(8);
        $branch = "review/{$id}";
        $root = storage_path('app/worktrees');
        $dir = $root.'/'.$id;

        File::ensureDirectoryExists($root);

        Process::path($repoPath)
            ->timeout(120)
            ->run(['git', 'fetch', '--force', 'origin', "pull/{$pr->number}/head:{$branch}"])
            ->throw();

        Process::path($repoPath)
            ->timeout(120)
            ->run(['git', 'worktree', 'add', '--detach', $dir, $branch])
            ->throw();

        return ['dir' => $dir, 'branch' => $branch];
    }

    /** Best-effort teardown of the worktree and its branch. */
    public function remove(string $repoPath, string $dir, string $branch): void
    {
        Process::path($repoPath)->run(['git', 'worktree', 'remove', '--force', $dir]);
        Process::path($repoPath)->run(['git', 'branch', '-D', $branch]);
        File::deleteDirectory($dir);
    }
}
