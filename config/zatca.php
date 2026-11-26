<?php

return [

    /*
     * Which ZATCA environment to onboard units with and send invoices to:
     *  - sandbox:    ZATCA's developer portal; accepts the test OTP 123345, nothing is legally reported
     *  - simulation: the Fatoora simulation portal, with your real taxpayer onboarding
     *  - production: the live Fatoora platform
     */
    'environment' => env('ZATCA_ENVIRONMENT', 'sandbox'),

];
