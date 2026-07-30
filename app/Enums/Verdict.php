<?php

declare(strict_types=1);

namespace App\Enums;

use App\Services\Review\Finding;

enum Verdict: string
{
    case Approve = 'approve';
    case RequestChanges = 'request_changes';
    case Comment = 'comment';

    /**
     * Derive the verdict from finding severity: any blocker (a major issue)
     * means request changes; otherwise approve — minor findings do not block.
     *
     * @param  array<int, Finding>  $findings
     */
    public static function fromFindings(array $findings): self
    {
        foreach ($findings as $finding) {
            if ($finding->severity === Severity::Blocker) {
                return self::RequestChanges;
            }
        }

        return self::Approve;
    }

    /** The GitHub Reviews API event name for this verdict. */
    public function githubEvent(): string
    {
        return match ($this) {
            self::Approve => 'APPROVE',
            self::RequestChanges => 'REQUEST_CHANGES',
            self::Comment => 'COMMENT',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Approve => 'Approved',
            self::RequestChanges => 'Changes requested',
            self::Comment => 'Commented',
        };
    }
}
