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
}
