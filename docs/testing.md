# Testing

GFH uses PHPUnit 11. Tests live in `tests/`:

| Folder | Needs a database | What it covers |
| --- | --- | --- |
| `tests/Unit` | No | Config parsing, connection strings and encryption, the router, CSRF, password rules |
| `tests/Database` | Yes (two throwaway databases) | Migrator rules, permissions, sign-in and lockout, accounts, reset links, customer access |

## One-time setup (database tests)

1. Create two **empty** databases whose names end in `_test`. The tests refuse to run against any other name.
   ```powershell
   createdb -h localhost -U postgres gfh_core_test
   createdb -h localhost -U postgres gfh_org_test
   ```
2. Let the migrator role create tables in them (the roles must already exist: see `database/roles/README.md`):
   ```powershell
   psql -h localhost -U postgres -d gfh_core_test -f database/roles/02_prepare_database.sql
   psql -h localhost -U postgres -d gfh_org_test  -f database/roles/02_prepare_database.sql
   ```
3. Point the tests at them. Use the migrator role's password (set in this PowerShell window only):
   ```powershell
   $env:GFH_TEST_CORE_URL = "postgresql://gfh_migrator:YOUR_MIGRATOR_PASSWORD@127.0.0.1:5432/gfh_core_test?sslmode=prefer"
   $env:GFH_TEST_ORG_URL  = "postgresql://gfh_migrator:YOUR_MIGRATOR_PASSWORD@127.0.0.1:5432/gfh_org_test?sslmode=prefer"
   ```
   The first run applies the migrations to these databases. Without the two variables, the database tests are skipped.

## Getting PHPUnit

PHPUnit needs PHP 8.2+ with the `dom`, `json`, `libxml`, `mbstring`, `tokenizer`, `xml` and `xmlwriter` extensions
(check with `php -m`; enable missing ones in `php.ini`).

- **Without Composer (simplest):** download `phpunit-11.phar` from https://phar.phpunit.de/phpunit-11.phar, save it in the project root as `phpunit.phar`.
  This is a development tool only; the application itself never makes external requests.
- **With Composer:** `composer install`, then use `php vendor/bin/phpunit` instead of `php phpunit.phar`.

## Running

```powershell
php phpunit.phar --testsuite unit        # no database needed
php phpunit.phar --testsuite database    # needs the two test databases
php phpunit.phar                         # everything
php phpunit.phar --filter testCashierCannotEditProductsOrPrices
```

Every database test runs inside a transaction that is rolled back, so tests do not affect each other or leave data behind.

## Stage 2 acceptance rules and the tests that check them

| Rule | Test |
| --- | --- |
| A Cashier cannot edit products or prices | `PermissionTest::testCashierCannotEditProductsOrPrices` |
| A customer cannot read another customer's orders | `CustomerAccessTest::testACustomerCanOnlyReadTheirOwnOrders` |
| Super Admin accounts have no write access to organization operations | `PermissionTest::testSuperAdminHasNoWriteAccessToOperations` |
| A user with no membership gets a refusal | `PermissionTest::testNoMembershipMeansRefusal` |
| An organization Admin cannot create an Admin or Manager | `AccountServiceTest::testAnAdminCannotCreateAdminOrManagerAccounts` |
| ...cannot touch another organization's members | `AccountServiceTest::testAnAdminCannotTouchAnotherOrganizationsMembers` |
| ...cannot change the password or profile of someone who belongs to another organization | `AccountServiceTest::testAnAdminCannotChangeTheProfileOrPasswordOfSomeoneWhoBelongsToAnotherOrganization` |
| ...may add an existing email without changing its password or profile | `AccountServiceTest::testAddingAnExistingEmailAddsOnlyAMembership` |
| Failed sign-ins never reveal which part was wrong | `AuthServiceTest::testFailedSignInsNeverRevealWhichPartWasWrong`, `testRepeatedFailuresLockSignInWithOneSharedMessage` |

## Stage 3 acceptance rules and the tests that check them

| Rule | Test |
| --- | --- |
| VAT is right to the cent | `PricingMathTest::testVatIsExtractedAtTwelvePercentToTheCent`, `testVatAddsBackUp...` (every amount up to 50.00) |
| Tier prices follow their basis | `PricingMathTest::testTiersApplyByTheirBasis...`, `OrderTest::testPricesAndTotalsAreRecomputedOnTheServer` |
| Weighted-average cost across receipts, including nothing on hand | `InventoryTest::testWeightedAverageCostAcrossSeveralReceipts`, `testWithNothingOnHandTheNewCostBecomesTheAverage` |
| A conversion is not a purchase | `InventoryTest::testAConversionMovesStockAndCostAndIsNotAPurchase` |
| Reservations expire and release stock | `InventoryTest::testReservationsHoldStockAndReleaseWhenTheyExpire` |
| A POS sale uploaded twice creates one sale | `PosTest::testASaleUploadedTwiceCreatesOneSaleAndOneSetOfMovements` |
| An oversell is kept and flagged needs_review | `PosTest::testAnOversellIsKeptAndFlaggedForReviewNotRejected` |
| The progress path follows applies_when | `OrderTest::testTheProgressPathIncludesOnlyTheStepsThatFitTheOrder` |
| Payment status goes unpaid, deposit_paid, paid; overpayment refused | `OrderTest::testPaymentStatusGoesUnpaidDepositPaidPaid...` |
| Progress and payment change independently | `OrderTest::testProgressAndPaymentStatusChangeIndependently` |
| Every endpoint checks can() | `ApiAuthorizationTest` (scans the controllers and the routes) |

Also covered: catalog filters, brand and category rules, attribute validation, photo upload and re-encoding (needs `gd`),
procurement receiving with batches and rejected quantities, shifts and expected cash, and the dashboard figures.

Stage 1 rules are covered by `MigratorTest` (runs once, runs twice changes nothing, a modified applied migration is refused).

## Stage 4 acceptance rules and the tests that check them

| Rule | Test |
| --- | --- |
| Three token sets (`admin`, `shop`, `pos`) defined as CSS variables overridable by branding | `TemplateShellTest::testEachAppHasATokenSetOfVariablesThatBrandingCanOverride`, `BrandingCssTest` |
| Self-hosted fonts (`Inter`, `Source Serif 4`) and locally vendored Chart.js (`v4.4.8`, MIT) | `TemplateShellTest::testSelfHostedFontsAreDeclaredAndVendored` |
| Admin sidebar filtered by `PermissionService::can()` and extended via `navigation.admin` hook | `AdminNavigationTest` |
| UI kit page (`/{org}/admin/ui-kit`) refuses signed-out users and renders all components with labels and keyboard attributes | `UiKitPageTest` |
| No request goes to an external host in `templates/` or `public/assets/` | `NoExternalHostsTest` |

## Stage 5 acceptance rules and the tests that check them

| Rule | Test |
| --- | --- |
| `PermissionService::can()` checked on every Admin Dashboard, Inventory, Products, Brands and Categories action (signed-out -> 401; unauthorized role / view-only on edit or archive -> 403) | `AdminBatch51Test::testDashboardEnforcesPermissionsAndRendersGrossProfitFirstWithWidgetsHook`, `AdminBatch51Test::testInventoryControllerEnforcesPermissionsValidatesFormsAndSupportsListQueries`, `AdminBatch52Test::testPermissionsAndFiltersOnProductsBrandsAndCategories`, `AdminBatch52Test::testBrandAndCategoryRulesPermissionsAndLogoUpload` |
| Reason required on inventory adjustments and write-offs; rejected forms return 422 with inline field errors (`aria-invalid="true"`, `.field-error`) | `AdminBatch51Test::testInventoryControllerEnforcesPermissionsValidatesFormsAndSupportsListQueries`, `InventoryTest` |
| State-changing Admin POST routes register `CsrfMiddleware` and reject missing/invalid `_csrf` tokens with 403 while accepting valid tokens | `AdminBatch51Test::testAdminBatch51RoutesAreRegisteredBehindStaffAndCsrfMiddleware`, `AdminBatch51Test::testCsrfMiddlewareBlocksStateChangeWithoutValidToken`, `AdminBatch52Test::testCsrfMiddlewareBlocksProductStateChangeWithoutToken` (note: live HTTP routing of Batch 5.2 routes is inspected via `core/routes.php`; end-to-end router dispatch of Batch 5.2 POST routes is covered at middleware + controller boundary, not full front-controller HTTP dispatch) |
| Server-side validation renders inline field errors on Product, Variant, Tier, Photo, Brand, Category and `validation.attributes` forms | `AdminBatch52Test::testProductEditingVariantsTiersPriceHistoryPhotosAndHooks`, `AdminBatch52Test::testBrandAndCategoryRulesPermissionsAndLogoUpload`, `CatalogTest` |
| Every list supports `ListQuery` search (`q`), `sort`, filters and pagination (`page`, `per_page`) matching `docs/api.md` | `AdminBatch51Test::testInventoryControllerEnforcesPermissionsValidatesFormsAndSupportsListQueries`, `AdminBatch51Test::testReorderSuggestionsCalculateAvailableMinusOnOrderAndSupportListQuery`, `AdminBatch52Test::testPermissionsAndFiltersOnProductsBrandsAndCategories`, `AdminBatch52Test::testBrandAndCategoryRulesPermissionsAndLogoUpload` |
| Brands/categories: `edit` creates/renames/uploads logo; `delete` archives/restores; in-use refuses archive (409); `GFH` prefix refused (422) | `AdminBatch52Test::testBrandAndCategoryRulesPermissionsAndLogoUpload`, `CatalogTest` |
| Development seed (`php bin/seed.php --demo`) is repeatable with no duplicate rows | `AdminBatch51Test::testDevelopmentSeedIsCompleteAndIdempotent` |

## Manual checks in the browser (Stage 2)

With the database set up as in `database/roles/README.md` and the demo organization migrated:

```powershell
php bin/create-user.php --email=admin@example.test --name="Demo Admin" --org=demo --role=admin --generate-password
php bin/create-user.php --email=cashier@example.test --name="Demo Cashier" --org=demo --role=cashier
php -S localhost:8000 -t public
```

1. Open http://localhost:8000/demo/admin/login and sign in with the generated password. A wrong password and a wrong email show the same message.
2. On the page you land on, choose "Generate a password reset link", pick "Staff or cashier", enter `cashier@example.test`.
3. Open the link shown, choose a password, then sign in as the cashier. The cashier sees no reset link option.
4. A link works once: opening it again and submitting shows "invalid or has expired".

Customer endpoints (PowerShell keeps the session cookie in `$s`):

```powershell
$base = "http://localhost:8000/demo/api/v1/auth/customer"
$r = Invoke-RestMethod "$base/csrf" -SessionVariable s
$headers = @{ "X-CSRF-Token" = $r.data.csrf_token }
$body = @{ full_name = "Ana Reyes"; email = "ana@example.test"; password = "a-long-password-1" } | ConvertTo-Json
Invoke-RestMethod -Method Post "$base/register" -WebSession $s -Headers $headers -ContentType "application/json" -Body $body
Invoke-RestMethod "$base/me" -WebSession $s
```
