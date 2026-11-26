<?php

namespace Arkhan\Zatca;

/** A signed invoice ready to be chained: its UUID, its hash (base64 of the raw SHA-256) and the XML. */
final readonly class SignedInvoice
{
    public function __construct(
        public string $uuid,
        public string $hash,
        public string $xml,
    ) {}
}
