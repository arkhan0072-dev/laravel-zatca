<?php

namespace Arkhan\Zatca\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $unit EGS unit identifier
 * @property string $environment sandbox, simulation or production
 * @property string $status compliance, active
 * @property string $private_key PEM, encrypted at rest
 * @property string $compliance_certificate base64 DER
 * @property string $compliance_secret encrypted at rest
 * @property string $compliance_request_id
 * @property string|null $certificate production CSID, base64 DER
 * @property string|null $secret encrypted at rest
 */
class Unit extends Model
{
    protected $table = 'zatca_units';

    protected $fillable = ['unit', 'environment', 'status', 'private_key', 'compliance_certificate', 'compliance_secret', 'compliance_request_id', 'certificate', 'secret'];

    protected $casts = ['private_key' => 'encrypted', 'compliance_secret' => 'encrypted', 'secret' => 'encrypted'];

    protected $hidden = ['private_key', 'compliance_secret', 'secret'];
}
