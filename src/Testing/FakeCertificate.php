<?php

namespace Arkhan\Zatca\Testing;

use phpseclib3\Crypt\Common\PublicKey;
use phpseclib3\Crypt\EC;
use phpseclib3\File\X509;
use RuntimeException;

/** A self-signed secp256k1 certificate, the curve ZATCA uses, for signing invoices in tests. */
final class FakeCertificate
{
    /** @var array{certificate: string, privateKey: string}|null */
    private static ?array $pair = null;

    /** @return array{certificate: string, privateKey: string} base64 DER certificate and PEM private key */
    public static function pair(): array
    {
        if (self::$pair !== null) {
            return self::$pair;
        }

        // phpseclib's loose return types are checked inline; neither failure can happen with these inputs.
        $key = EC::createKey('secp256k1');
        $publicKey = $key->getPublicKey();
        $subject = new X509;
        $subject->setDNProp('id-at-commonName', 'TST-886431145-399999999900003');
        $subject->setPublicKey($publicKey instanceof PublicKey ? $publicKey : throw new RuntimeException('phpseclib did not return a public key.'));
        $issuer = new X509;
        $issuer->setPrivateKey($key);
        $issuer->setDN($subject->getDN());

        $x509 = new X509;
        $signed = $x509->sign($issuer, $subject);
        $der = $x509->saveX509(is_array($signed) ? $signed : throw new RuntimeException('phpseclib could not sign the fake certificate.'), X509::FORMAT_DER);

        return self::$pair = ['certificate' => base64_encode((string) $der), 'privateKey' => $key->toString('PKCS8')];
    }
}
