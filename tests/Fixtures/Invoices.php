<?php

namespace Arkhan\Zatca\Tests\Fixtures;

use Illuminate\Support\Str;

/** Valid invoice data in the shape saleh7/php-zatca-xml's InvoiceMapper accepts. */
final class Invoices
{
    /** @return array<string, mixed> */
    public static function simplified(?string $uuid = null): array
    {
        return [
            'uuid' => $uuid ?? (string) Str::uuid(),
            'id' => 'SME00010',
            'issueDate' => '2026-11-26',
            'issueTime' => '14:30:00',
            'currencyCode' => 'SAR',
            'taxCurrencyCode' => 'SAR',
            'invoiceType' => ['invoice' => 'simplified', 'type' => 'invoice'],
            'supplier' => [
                'registrationName' => 'Maximum Speed Tech Supply',
                'taxId' => '399999999900003',
                'identificationId' => '1010010000',
                'identificationType' => 'CRN',
                'address' => [
                    'street' => 'Prince Sultan', 'buildingNumber' => '2322', 'subdivision' => 'Al-Murabba',
                    'city' => 'Riyadh', 'postalZone' => '23333', 'country' => 'SA',
                ],
            ],
            'paymentMeans' => ['code' => '10'],
            'taxTotal' => [
                'taxAmount' => 30.15,
                'subTotals' => [[
                    'taxableAmount' => 201.00,
                    'taxAmount' => 30.15,
                    'taxCategory' => ['id' => 'S', 'percent' => 15, 'taxScheme' => ['id' => 'VAT']],
                ]],
            ],
            'legalMonetaryTotal' => [
                'lineExtensionAmount' => 201.00,
                'taxExclusiveAmount' => 201.00,
                'taxInclusiveAmount' => 231.15,
                'prepaidAmount' => 0,
                'payableAmount' => 231.15,
                'allowanceTotalAmount' => 0,
            ],
            'invoiceLines' => [[
                'id' => 1, 'unitCode' => 'PCE', 'quantity' => 33,
                'lineExtensionAmount' => 99.00,
                'item' => ['name' => 'Book', 'classifiedTaxCategory' => [['id' => 'S', 'percent' => 15, 'taxScheme' => ['id' => 'VAT']]]],
                'price' => ['amount' => 3.00, 'unitCode' => 'UNIT'],
                'taxTotal' => ['taxAmount' => 14.85, 'roundingAmount' => 113.85],
            ], [
                'id' => 2, 'unitCode' => 'PCE', 'quantity' => 3,
                'lineExtensionAmount' => 102.00,
                'item' => ['name' => 'Pen', 'classifiedTaxCategory' => [['id' => 'S', 'percent' => 15, 'taxScheme' => ['id' => 'VAT']]]],
                'price' => ['amount' => 34.00, 'unitCode' => 'UNIT'],
                'taxTotal' => ['taxAmount' => 15.30, 'roundingAmount' => 117.30],
            ]],
        ];
    }
}
