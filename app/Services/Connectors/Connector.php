<?php

declare(strict_types=1);

namespace App\Services\Connectors;

interface Connector
{
    /** Stable identifier for this integration, e.g. "github". */
    public function key(): string;

    /** Whether the required credentials/config are present. */
    public function configured(): bool;
}
