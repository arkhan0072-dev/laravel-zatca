<?php

namespace Arkhan\Zatca;

/** A signed invoice ready to be chained: its UUID, its hash (base64 of the raw SHA-256), the XML and its QR code. */
final readonly class SignedInvoice
{
    public function __construct(
        public string $uuid,
        public string $hash,
        public string $xml,
        public string $qr = '',
    ) {}
}
