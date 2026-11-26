<?php

namespace Arkhan\Zatca;

/** Where a new invoice sits in its unit's chain: its counter (ICV) and the previous invoice's hash (PIH). */
final readonly class Link
{
    public function __construct(
        public int $counter,
        public string $previousHash,
    ) {}
}
