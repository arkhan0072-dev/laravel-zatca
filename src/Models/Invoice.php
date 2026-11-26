<?php

namespace Arkhan\Zatca\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $unit EGS unit identifier
 * @property int $counter invoice counter value (ICV)
 * @property string $uuid
 * @property string $previous_hash previous invoice hash (PIH)
 * @property string $hash
 * @property string $xml
 */
class Invoice extends Model
{
    protected $table = 'zatca_invoices';

    protected $fillable = ['unit', 'counter', 'uuid', 'previous_hash', 'hash', 'xml'];

    protected $casts = ['counter' => 'integer'];
}
