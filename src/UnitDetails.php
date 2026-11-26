<?php

namespace Arkhan\Zatca;

/** What ZATCA's certificate request (CSR) says about the taxpayer and the EGS unit being onboarded. */
final readonly class UnitDetails
{
    public function __construct(
        public string $vatNumber,
        public string $organizationName,
        public string $branchName,
        public string $commonName,
        public string $solutionName,
        public string $model,
        public string $serialNumber,
        public string $address,
        public string $businessCategory,
        public string $invoiceTypes = '1100', // standard and simplified
    ) {}

    /** @param  array<string, string>  $details  as stored by Onboarding::start() */
    public static function fromArray(array $details): self
    {
        return new self(...$details);
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'vatNumber' => $this->vatNumber,
            'organizationName' => $this->organizationName,
            'branchName' => $this->branchName,
            'commonName' => $this->commonName,
            'solutionName' => $this->solutionName,
            'model' => $this->model,
            'serialNumber' => $this->serialNumber,
            'address' => $this->address,
            'businessCategory' => $this->businessCategory,
            'invoiceTypes' => $this->invoiceTypes,
        ];
    }
}
