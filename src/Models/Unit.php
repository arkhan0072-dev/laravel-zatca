<?php

namespace Arkhan\Zatca\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

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
 * @property array<string, string>|null $details the certificate request's details, for renewal
 */
class Unit extends Model
{
    protected $table = 'zatca_units';

    protected $fillable = ['unit', 'environment', 'status', 'private_key', 'compliance_certificate', 'compliance_secret', 'compliance_request_id', 'certificate', 'secret', 'details'];

    protected $casts = ['private_key' => 'encrypted', 'compliance_secret' => 'encrypted', 'secret' => 'encrypted', 'details' => 'array'];

    protected $hidden = ['private_key', 'compliance_secret', 'secret'];

    /** When the production certificate expires, or null before the unit has one. */
    public function certificateExpiresAt(): ?Carbon
    {
        if ($this->certificate === null) {
            return null;
        }
        $parsed = openssl_x509_parse("-----BEGIN CERTIFICATE-----\n".chunk_split($this->certificate, 64, "\n")."-----END CERTIFICATE-----\n");
        $expires = is_array($parsed) ? ($parsed['validTo_time_t'] ?? null) : null;

        return is_int($expires) ? Carbon::createFromTimestamp($expires) : null;
    }
}
