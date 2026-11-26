<?php

use Arkhan\Zatca\Chain;
use Arkhan\Zatca\Link;
use Arkhan\Zatca\Models\Invoice;
use Arkhan\Zatca\Signer;
use Arkhan\Zatca\Tests\Fixtures\Invoices;
use Arkhan\Zatca\Tests\Fixtures\TestCertificate;
use Arkhan\Zatca\Zatca;

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

beforeEach(fn () => $this->link = new Link(7, Chain::FIRST_PREVIOUS_HASH));

it('signs the invoice with its place in the chain', function () {
    $signed = app(Signer::class)->sign(Invoices::simplified(), $this->link, TestCertificate::make());
    $xpath = xpath($signed->xml);

    expect($xpath->evaluate("string(//cac:AdditionalDocumentReference[cbc:ID='ICV']/cbc:UUID)"))->toBe('7')
        ->and($xpath->evaluate("string(//cac:AdditionalDocumentReference[cbc:ID='PIH']//cbc:EmbeddedDocumentBinaryObject)"))->toBe(Chain::FIRST_PREVIOUS_HASH)
        ->and($xpath->evaluate("count(//cac:AdditionalDocumentReference[cbc:ID='ICV'])"))->toBe(1.0);
});

it('returns the invoice\'s UUID and the hash it was signed over', function () {
    $data = Invoices::simplified('8e6000cf-1a98-4174-b3e7-b5d5954bc10d');

    $signed = app(Signer::class)->sign($data, $this->link, TestCertificate::make());

    expect($signed->uuid)->toBe('8e6000cf-1a98-4174-b3e7-b5d5954bc10d')
        ->and(strlen((string) base64_decode($signed->hash, true)))->toBe(32)
        ->and(xpath($signed->xml)->evaluate("string(//ds:Reference[@Id='invoiceSignedData']/ds:DigestValue)"))->toBe($signed->hash);
});

it('embeds a ZATCA QR code with the seller, VAT number, totals and hash', function () {
    $signed = app(Signer::class)->sign(Invoices::simplified(), $this->link, TestCertificate::make());
    $tags = qrTags($signed->qr);

    expect($tags[1])->toBe('Maximum Speed Tech Supply')
        ->and($tags[2])->toBe('399999999900003')
        ->and($tags[3])->toStartWith('2026-11-26T14:30:00')
        ->and($tags[4])->toBe('231.15')
        ->and($tags[5])->toBe('30.15')
        ->and($tags[6])->toBe($signed->hash)
        ->and(xpath($signed->xml)->evaluate("string(//cac:AdditionalDocumentReference[cbc:ID='QR']//cbc:EmbeddedDocumentBinaryObject)"))->toBe($signed->qr);
});

it('replaces chain fields the caller put in the data', function () {
    $data = Invoices::simplified();
    $data['additionalDocuments'] = [['id' => 'ICV', 'uuid' => '999'], ['id' => 'PIH', 'attachment' => ['content' => 'stale']]];

    $xpath = xpath(app(Signer::class)->sign($data, $this->link, TestCertificate::make())->xml);

    expect($xpath->evaluate("count(//cac:AdditionalDocumentReference[cbc:ID='ICV'])"))->toBe(1.0)
        ->and($xpath->evaluate("string(//cac:AdditionalDocumentReference[cbc:ID='ICV']/cbc:UUID)"))->toBe('7')
        ->and($xpath->evaluate("count(//cac:AdditionalDocumentReference[cbc:ID='PIH'])"))->toBe(1.0);
});

it('issues invoices into a unit\'s chain', function () {
    $zatca = app(Zatca::class);

    $first = $zatca->issue('pos-1', Invoices::simplified(), TestCertificate::make());
    $second = $zatca->issue('pos-1', Invoices::simplified(), TestCertificate::make());

    expect([$first->counter, $second->counter])->toBe([1, 2])
        ->and(xpath($second->xml)->evaluate("string(//cac:AdditionalDocumentReference[cbc:ID='PIH']//cbc:EmbeddedDocumentBinaryObject)"))->toBe($first->hash)
        ->and(qrTags($second->qr)[6])->toBe($second->hash)
        ->and(Invoice::count())->toBe(2);
});
