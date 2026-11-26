<?php

namespace Arkhan\Zatca\Tests\Fixtures;

use phpseclib3\Crypt\EC;
use phpseclib3\File\X509;
use Saleh7\Zatca\Helpers\Certificate;

/** A self-signed secp256k1 certificate, the curve ZATCA uses, so signing can be tested offline. */
final class TestCertificate
{
    private static ?Certificate $certificate = null;

    private static string $privateKey = '';

    public static function make(): Certificate
    {
        if (self::$certificate !== null) {
            return self::$certificate;
        }

        $key = EC::createKey('secp256k1');
        $subject = new X509;
        $subject->setDNProp('id-at-commonName', 'TST-886431145-399999999900003');
        $subject->setPublicKey($key->getPublicKey());
        $issuer = new X509;
        $issuer->setPrivateKey($key);
        $issuer->setDN($subject->getDN());

        $x509 = new X509;
        $signed = $x509->sign($issuer, $subject, 'sha256');
        $der = $x509->saveX509($signed, X509::FORMAT_DER);
        self::$privateKey = $key->toString('PKCS8');

        return self::$certificate = new Certificate(base64_encode($der), self::$privateKey, 'test-secret');
    }

    /** PEM private key matching make()'s certificate. */
    public static function privateKey(): string
    {
        self::make();

        return self::$privateKey;
    }
}
