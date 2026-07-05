# PayHere overlay for Invoice Ninja.
#
# This builds a custom image by taking the official Invoice Ninja release image
# and copying our PayHere gateway files on top of it. The base tag MUST match the
# app version these files were written against (see VERSION.txt) so the two
# modified files (SystemLog.php, PaymentLibrariesSeeder.php) line up exactly.
FROM invoiceninja/invoiceninja:5.13.24

# Application root inside the official image (confirmed from runtime stack traces).
ARG APP_DIR=/var/www/app

# Overlay only the PayHere-related files onto the baked-in application.
COPY --chown=www-data:www-data app/Models/SystemLog.php                                       ${APP_DIR}/app/Models/SystemLog.php
COPY --chown=www-data:www-data app/PaymentDrivers/PayHerePaymentDriver.php                    ${APP_DIR}/app/PaymentDrivers/PayHerePaymentDriver.php
COPY --chown=www-data:www-data app/PaymentDrivers/PayHere/                                     ${APP_DIR}/app/PaymentDrivers/PayHere/
COPY --chown=www-data:www-data database/migrations/2026_07_05_000000_add_payhere_gateway.php  ${APP_DIR}/database/migrations/2026_07_05_000000_add_payhere_gateway.php
COPY --chown=www-data:www-data database/seeders/PaymentLibrariesSeeder.php                     ${APP_DIR}/database/seeders/PaymentLibrariesSeeder.php
COPY --chown=www-data:www-data resources/views/portal/ninja2020/gateways/payhere/             ${APP_DIR}/resources/views/portal/ninja2020/gateways/payhere/
