<?php

use Arkhan\Zatca\Link;
use Arkhan\Zatca\SignedInvoice;
use Arkhan\Zatca\Tests\ConcurrencyTestCase;
use Arkhan\Zatca\Tests\TestCase;
use Arkhan\Zatca\UnitDetails;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Str;
use Saleh7\Zatca\ZatcaAPI;

uses(TestCase::class)->in('Feature', 'Unit', 'Integration');
uses(ConcurrencyTestCase::class)->in('Concurrency');

/** Fake ZATCA: queue responses, record requests. */
function fakeZatca(Response ...$responses): ArrayObject
{
    $sent = new ArrayObject;
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($sent));
    app()->instance(ZatcaAPI::class, new ZatcaAPI('sandbox', new Client(['handler' => $stack])));

    return $sent;
}

function zatcaJson(int $status, array $body): Response
{
    return new Response($status, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
}

function details(): UnitDetails
{
    return new UnitDetails(
        vatNumber: '399999999900003',
        organizationName: 'Maximum Speed Tech Supply',
        branchName: 'Riyadh Branch',
        commonName: 'TST-886431145-399999999900003',
        solutionName: 'TST',
        model: 'TST',
        serialNumber: 'ed22f1d8-e6a2-1118-9b58-d9a8f11e445f',
        address: 'RRRD2929',
        businessCategory: 'Supply activities',
    );
}

/** XPath over a signed invoice with UBL's namespaces registered. */
function xpath(string $xml): DOMXPath
{
    $dom = new DOMDocument;
    $dom->loadXML($xml);
    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
    $xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
    $xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');

    return $xpath;
}

/** @return array<int, string> ZATCA's QR code: base64 of tag-length-value fields. */
function qrTags(string $qr): array
{
    $bytes = base64_decode($qr, true);
    $tags = [];
    for ($i = 0; $i < strlen($bytes);) {
        $tag = ord($bytes[$i]);
        $length = ord($bytes[$i + 1]);
        $tags[$tag] = substr($bytes, $i + 2, $length);
        $i += 2 + $length;
    }

    return $tags;
}

/** A stand-in for slice 2's signer: any XML, its real ZATCA-style hash (base64 of the raw SHA-256). */
function signed(Link $link, ?string $uuid = null): SignedInvoice
{
    $xml = "<Invoice><ICV>{$link->counter}</ICV><PIH>{$link->previousHash}</PIH></Invoice>";

    return new SignedInvoice($uuid ?? (string) Str::uuid(), base64_encode(hash('sha256', $xml, true)), $xml);
}
