<?php

namespace Arkhan\Zatca;

use Saleh7\Zatca\GeneratorInvoice;
use Saleh7\Zatca\Helpers\Certificate;
use Saleh7\Zatca\InvoiceSigner;
use Saleh7\Zatca\Mappers\InvoiceMapper;

/** Turns invoice data into signed UBL XML with its chain link, hash and QR code (via saleh7/php-zatca-xml). */
class Signer
{
    /** @param  array<string, mixed>  $invoice  data in the shape saleh7/php-zatca-xml's InvoiceMapper accepts */
    public function sign(array $invoice, Link $link, Certificate $certificate): SignedInvoice
    {
        // The chain decides ICV and PIH; anything the caller passed for them would break the chain.
        $documents = is_array($invoice['additionalDocuments'] ?? null) ? $invoice['additionalDocuments'] : [];
        $invoice['additionalDocuments'] = [
            ...array_filter($documents, fn ($doc) => ! in_array(is_array($doc) ? ($doc['id'] ?? null) : null, ['ICV', 'PIH'], true)),
            ['id' => 'ICV', 'uuid' => (string) $link->counter],
            ['id' => 'PIH', 'attachment' => ['content' => $link->previousHash]],
        ];

        $mapped = (new InvoiceMapper)->mapToInvoice($invoice); // throws when a required field such as the UUID is missing
        $signed = InvoiceSigner::signInvoice(GeneratorInvoice::invoice($mapped)->getXML(), $certificate);

        return new SignedInvoice((string) $mapped->getUUID(), $signed->getHash(), $signed->getInvoice(), $signed->getQRCode());
    }
}
