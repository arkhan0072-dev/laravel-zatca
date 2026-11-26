<?php

namespace Arkhan\Zatca\Testing;

use Arkhan\Zatca\Models\Invoice;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Assert;
use Psr\Http\Message\RequestInterface;

/**
 * Answers ZATCA's endpoints offline, as Zatca::fake() installs it: certificates are issued, compliance
 * checks pass, invoices are reported or cleared. Reject or take ZATCA down to test the unhappy paths.
 */
final class ZatcaFake
{
    /** @var array<string, string> uuid => reported|cleared */
    private array $submitted = [];

    /** @var array{code: string, message: string}|null */
    private ?array $rejection = null;

    private bool $down = false;

    /** Reject the next reported or cleared invoice with this ZATCA rule code and message. */
    public function rejectNext(string $code, string $message): self
    {
        $this->rejection = ['code' => $code, 'message' => $message];

        return $this;
    }

    /** Answer reporting and clearance with HTTP 503 until called with false. */
    public function outage(bool $down = true): self
    {
        $this->down = $down;

        return $this;
    }

    public function assertReported(Invoice $invoice): void
    {
        Assert::assertSame('reported', $this->submitted[$invoice->uuid] ?? null, "Invoice {$invoice->uuid} was not reported.");
    }

    public function assertCleared(Invoice $invoice): void
    {
        Assert::assertSame('cleared', $this->submitted[$invoice->uuid] ?? null, "Invoice {$invoice->uuid} was not cleared.");
    }

    public function assertSubmittedCount(int $count): void
    {
        Assert::assertCount($count, $this->submitted, count($this->submitted).' invoice(s) were submitted to ZATCA.');
    }

    public function assertNothingSubmitted(): void
    {
        $this->assertSubmittedCount(0);
    }

    /** @internal the Guzzle handler Zatca::fake() gives the API client */
    public function __invoke(RequestInterface $request): PromiseInterface
    {
        $path = $request->getUri()->getPath();
        $body = json_decode((string) $request->getBody(), true);
        $uuid = is_array($body) && is_string($body['uuid'] ?? null) ? $body['uuid'] : '';
        $passed = ['infoMessages' => [], 'warningMessages' => [], 'errorMessages' => [], 'status' => 'PASS'];

        if (str_ends_with($path, '/compliance') || str_ends_with($path, '/production/csids')) {
            return $this->json(200, ['requestID' => 1234567890123, 'dispositionMessage' => 'ISSUED', 'binarySecurityToken' => base64_encode(FakeCertificate::pair()['certificate']), 'secret' => 'fake-secret']);
        }
        if (str_ends_with($path, '/compliance/invoices')) {
            return $this->json(200, ['validationResults' => $passed, 'reportingStatus' => 'REPORTED']);
        }
        if ($this->down) {
            return Create::promiseFor(new Response(503));
        }
        if ($this->rejection !== null) {
            $errors = [['type' => 'ERROR', ...$this->rejection, 'status' => 'ERROR']];
            $this->rejection = null;

            return $this->json(400, ['validationResults' => [...$passed, 'errorMessages' => $errors, 'status' => 'ERROR'], 'reportingStatus' => 'NOT_REPORTED']);
        }

        if (str_ends_with($path, '/clearance/single')) {
            $this->submitted[$uuid] = 'cleared';

            // A real clearance returns ZATCA's stamped copy; the fake returns the invoice as sent.
            return $this->json(200, ['validationResults' => $passed, 'clearanceStatus' => 'CLEARED', 'clearedInvoice' => is_array($body) ? ($body['invoice'] ?? null) : null]);
        }
        $this->submitted[$uuid] = 'reported';

        return $this->json(200, ['validationResults' => $passed, 'reportingStatus' => 'REPORTED']);
    }

    /** @param  array<string, mixed>  $body */
    private function json(int $status, array $body): PromiseInterface
    {
        return Create::promiseFor(new Response($status, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR)));
    }
}
