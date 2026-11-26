<?php

namespace Arkhan\Zatca\Models;

use DOMDocument;
use DOMXPath;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $unit EGS unit identifier
 * @property int $counter invoice counter value (ICV)
 * @property string $uuid
 * @property string $previous_hash previous invoice hash (PIH)
 * @property string $hash
 * @property string $xml
 * @property string|null $qr base64 TLV QR code, printed on the invoice
 * @property string $status pending, reported, cleared or rejected
 * @property int $attempts times it was sent to ZATCA
 * @property Carbon|null $submitted_at
 * @property array<mixed>|null $zatca_response ZATCA's last answer, with its validation messages
 * @property string|null $cleared_xml ZATCA's stamped copy of a cleared invoice
 * @property Carbon $created_at when it was issued
 */
class Invoice extends Model
{
    protected $table = 'zatca_invoices';

    protected $fillable = ['unit', 'counter', 'uuid', 'previous_hash', 'hash', 'xml', 'qr', 'status', 'attempts', 'submitted_at', 'zatca_response', 'cleared_xml'];

    protected $attributes = ['status' => 'pending', 'attempts' => 0];

    protected $casts = ['counter' => 'integer', 'attempts' => 'integer', 'submitted_at' => 'datetime', 'zatca_response' => 'array'];

    /** The invoice number (cbc:ID) the seller gave it. */
    public function number(): string
    {
        return $this->read('string(/*/cbc:ID)');
    }

    /** Standard (B2B) documents have an InvoiceTypeCode name starting "01", simplified (B2C) ones "02". */
    public function isStandard(): bool
    {
        return str_starts_with($this->read('string(//cbc:InvoiceTypeCode/@name)'), '01');
    }

    /** invoice (388), credit (381) or debit (383). */
    public function documentType(): string
    {
        return match ($this->read('string(//cbc:InvoiceTypeCode)')) {
            '381' => 'credit',
            '383' => 'debit',
            default => 'invoice',
        };
    }

    private function read(string $expression): string
    {
        $dom = new DOMDocument;
        $dom->loadXML($this->xml);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $value = $xpath->evaluate($expression);

        return is_string($value) ? $value : '';
    }
}
