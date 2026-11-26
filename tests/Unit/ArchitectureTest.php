<?php

// laravel-zatca works without laravel-ledger: only the tests and the README recipe use it.
arch('does not depend on laravel-ledger')
    ->expect('Arkhan\Zatca')
    ->not->toUse('Arkhan\Ledger');
