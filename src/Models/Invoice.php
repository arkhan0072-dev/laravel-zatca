<?php

namespace Arkhan\Zatca\Models;

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
 */
class Invoice extends Model
{
    protected $table = 'zatca_invoices';

    protected $fillable = ['unit', 'counter', 'uuid', 'previous_hash', 'hash', 'xml', 'qr', 'status', 'attempts', 'submitted_at', 'zatca_response', 'cleared_xml'];

    protected $attributes = ['status' => 'pending', 'attempts' => 0];

    protected $casts = ['counter' => 'integer', 'attempts' => 'integer', 'submitted_at' => 'datetime', 'zatca_response' => 'array'];
}
