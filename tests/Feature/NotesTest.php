<?php

use Arkhan\Zatca\Models\Invoice;
use Arkhan\Zatca\Models\Unit;
use Arkhan\Zatca\Tests\Fixtures\Invoices;
use Arkhan\Zatca\Tests\Fixtures\TestCertificate;
use Arkhan\Zatca\Zatca;

beforeEach(function () {
    Unit::create([
        'unit' => 'pos-1', 'environment' => 'sandbox', 'status' => 'active',
        'private_key' => TestCertificate::privateKey(),
        'compliance_certificate' => 'c', 'compliance_secret' => 's', 'compliance_request_id' => '1',
        'certificate' => TestCertificate::make()->getRawCertificate(), 'secret' => 'production-secret',
    ]);
    $this->zatca = app(Zatca::class);
});

it('reads an invoice\'s number, kind and type from its signed XML', function () {
    $simplified = $this->zatca->issue('pos-1', [...Invoices::simplified(), 'id' => 'INV-100']);
    $standard = $this->zatca->issue('pos-1', Invoices::standard());

    expect($simplified->number())->toBe('INV-100')
        ->and($simplified->isStandard())->toBeFalse()
        ->and($simplified->documentType())->toBe('invoice')
        ->and($standard->isStandard())->toBeTrue();
});

it('issues a credit note against a simplified invoice, in the same chain', function () {
    $original = $this->zatca->issue('pos-1', [...Invoices::simplified(), 'id' => 'INV-100']);

    $note = $this->zatca->creditNote($original, [...Invoices::simplified(), 'id' => 'CN-1'], 'Customer returned the pen');

    $xml = xpath($note->xml);
    expect($note->counter)->toBe(2)
        ->and($note->previous_hash)->toBe($original->hash)
        ->and($note->documentType())->toBe('credit')
        ->and($note->isStandard())->toBeFalse()
        ->and($xml->evaluate('string(//cbc:InvoiceTypeCode)'))->toBe('381')
        ->and($xml->evaluate('string(//cac:BillingReference//cbc:ID)'))->toBe('INV-100')
        ->and($xml->evaluate('string(//cac:PaymentMeans/cbc:InstructionNote)'))->toBe('Customer returned the pen');
});

it('issues a debit note against a standard invoice', function () {
    $original = $this->zatca->issue('pos-1', [...Invoices::standard(), 'id' => 'INV-200']);

    $note = $this->zatca->debitNote($original, [...Invoices::standard(), 'id' => 'DN-1'], 'Delivery charge was not invoiced');

    expect($note->documentType())->toBe('debit')
        ->and($note->isStandard())->toBeTrue()
        ->and(xpath($note->xml)->evaluate('string(//cbc:InvoiceTypeCode)'))->toBe('383')
        ->and(xpath($note->xml)->evaluate('string(//cac:BillingReference//cbc:ID)'))->toBe('INV-200');
});

it('routes notes like their original: reporting for simplified, clearance for standard', function () {
    $fake = Zatca::fake();
    $simplified = $this->zatca->issue('pos-1', Invoices::simplified());
    $standard = $this->zatca->issue('pos-1', Invoices::standard());

    $credit = $this->zatca->submit($this->zatca->creditNote($simplified, Invoices::simplified(), 'Refund'))->fresh();
    $debit = $this->zatca->submit($this->zatca->debitNote($standard, Invoices::standard(), 'Extra charge'))->fresh();

    $fake->assertReported($credit);
    $fake->assertCleared($debit);
});

it('needs a reason for every note', function () {
    $original = $this->zatca->issue('pos-1', Invoices::simplified());

    $this->zatca->creditNote($original, Invoices::simplified(), '  ');
})->throws(InvalidArgumentException::class, 'A credit or debit note needs a reason (ZATCA rule KSA-10).');

it('refuses a note against a standard invoice ZATCA rejected, since it never became a tax invoice', function () {
    $original = $this->zatca->issue('pos-1', Invoices::standard());
    $original->update(['status' => 'rejected']);

    $this->zatca->creditNote($original, Invoices::standard(), 'Refund');
})->throws(InvalidArgumentException::class, 'was not cleared by ZATCA, so it is not a tax invoice; correct it with a new invoice, not a note.');

it('cancels a rejected simplified invoice with a credit note, as ZATCA allows (guideline, page 77)', function () {
    $original = $this->zatca->issue('pos-1', [...Invoices::simplified(), 'id' => 'INV-7']);
    $original->update(['status' => 'rejected']);

    $note = $this->zatca->creditNote($original, [...Invoices::simplified(), 'id' => 'CN-7'], 'Cancels INV-7, which ZATCA did not report');

    expect(xpath($note->xml)->evaluate('string(//cac:BillingReference//cbc:ID)'))->toBe('INV-7');
});

it('keeps the invoice\'s other payment details on a note', function () {
    $original = $this->zatca->issue('pos-1', Invoices::simplified());

    $note = $this->zatca->creditNote($original, [...Invoices::simplified(), 'paymentMeans' => ['code' => '42']], 'Refund to bank');

    expect(xpath($note->xml)->evaluate('string(//cac:PaymentMeans/cbc:PaymentMeansCode)'))->toBe('42')
        ->and(Invoice::count())->toBe(2);
});
