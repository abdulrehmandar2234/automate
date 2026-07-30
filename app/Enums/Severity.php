<?php

declare(strict_types=1);

namespace App\Enums;

enum Severity: string
{
    case Blocker = 'blocker';
    case Warning = 'warning';
    case Nit = 'nit';

    public static function fromString(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::Warning;
    }

    public function emoji(): string
    {
        return match ($this) {
            self::Blocker => '🚫',
            self::Warning => '⚠️',
            self::Nit => '💬',
        };
    }
}
