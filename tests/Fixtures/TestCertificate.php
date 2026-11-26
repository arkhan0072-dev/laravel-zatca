<?php

namespace Arkhan\Zatca\Tests\Fixtures;

use Arkhan\Zatca\Testing\FakeCertificate;
use Saleh7\Zatca\Helpers\Certificate;

/** The package's fake certificate, wrapped for signing in tests. */
final class TestCertificate
{
    public static function make(): Certificate
    {
        return new Certificate(FakeCertificate::pair()['certificate'], FakeCertificate::pair()['privateKey'], 'test-secret');
    }

    public static function privateKey(): string
    {
        return FakeCertificate::pair()['privateKey'];
    }
}
