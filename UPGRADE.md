# Upgrading

## From 0.x to 1.0

1. Update the package and run the new migration:

   ```bash
   composer update arkhan0072-dev/laravel-zatca
   php artisan migrate
   ```

2. If you set `zatca.environment` yourself, nothing changes. Otherwise set it in `.env`:

   ```dotenv
   ZATCA_ENVIRONMENT=sandbox   # or simulation, production
   ```

3. Units onboarded on 0.1 or 0.2 have no stored details. Pass them the first time you renew:

   ```php
   app(Onboarding::class)->renew('pos-1', $otp, $details);
   ```

4. Schedule `zatca:submit-pending` if you have not already, so no invoice misses ZATCA's 24-hour window:

   ```php
   Schedule::command('zatca:submit-pending')->everyFifteenMinutes();
   ```

No public method changed its signature between 0.3 and 1.0. From 1.0 on, breaking changes wait for a major version.
