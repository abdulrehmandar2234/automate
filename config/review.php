<?php

return [

    /*
    |--------------------------------------------------------------------------
    | PR Review Engine
    |--------------------------------------------------------------------------
    |
    | Configuration for the agentic pull-request reviewer (Phase 0). The review
    | runs the `claude` CLI against an isolated checkout of the PR and posts the
    | result back to GitHub.
    |
    */

    // Model passed to `claude -p --model`.
    'model' => env('REVIEW_MODEL', 'sonnet'),

    // When false, the review is NOT posted to GitHub (dry-run) — the outcome is
    // only printed / returned. Flip to true for live GitHub reviews.
    'post_enabled' => filter_var(env('REVIEW_POST_ENABLED', true), FILTER_VALIDATE_BOOL),

    // Extra, repo-agnostic guidance appended to every review prompt.
    'focus' => env('REVIEW_FOCUS', ''),

    // Routing table: "owner/repo" => absolute path to a local clone the engine
    // fetches the PR into and checks out in a throwaway worktree. Only repos
    // listed here are reviewable. (Clone-on-demand is a later phase.)
    'repos' => [
        // 'qureos/api-v3' => '/Users/you/Sites/api-v3',
    ],

];
