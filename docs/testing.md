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
| A sidebar item may name the action it needs (`action`, default `view`); Team and Settings need `edit`, so a Manager is not shown links that answer 403; an extension item can set `action` too | `AdminNavigationTest::testTeamAndSettingsAreShownOnlyToPeopleWhoMayEditThem`, `AdminNavigationTest::testAnExtensionItemCanNameTheActionItNeedsAndAnUnknownActionFallsBackToView` |
| UI kit page (`/{org}/admin/ui-kit`) refuses signed-out users and renders all components with labels and keyboard attributes | `UiKitPageTest` |
| No request goes to an external host in `templates/` or `public/assets/` | `NoExternalHostsTest` |

## Stage 5 acceptance rules and the tests that check them

| Rule | Test |
| --- | --- |
| Every Admin GET page (Dashboard, Inventory x3, Products x3, Brands, Categories, Procurement x5, Orders x2, Customers, Reports, Team x3, Settings hub, Branches x3, Terminals x3, POS sales x2, UI kit) renders 200 through the real `App` + `Router` + `core/routes.php` + middleware stack without 500s, unexpected 404s, PHP warnings or unbound route variables | `AdminRouterSmokeTest::testSignedInAdminCanLoadEveryAdminGetPageWithoutErrors` |
| `PermissionService::can()` checked on every Admin Dashboard, Inventory, Products, Brands, Categories, Procurement, Orders, Customers and POS Sales action (signed-out -> 401; unauthorized role / view-only on edit or archive -> 403) | `AdminBatch51Test::testDashboardEnforcesPermissionsAndRendersGrossProfitFirstWithWidgetsHook`, `AdminBatch51Test::testInventoryControllerEnforcesPermissionsValidatesFormsAndSupportsListQueries`, `AdminBatch52Test::testPermissionsAndFiltersOnProductsBrandsAndCategories`, `AdminBatch52Test::testBrandAndCategoryRulesPermissionsAndLogoUpload`, `AdminBatch53Test` |
| Reason required on inventory adjustments and write-offs; rejected forms return 422 with inline field errors (`aria-invalid="true"`, `.field-error`) | `AdminBatch51Test::testInventoryControllerEnforcesPermissionsValidatesFormsAndSupportsListQueries`, `InventoryTest` |
| State-changing Admin POST routes register `CsrfMiddleware` and reject missing/invalid `_csrf` tokens with 403 while accepting valid tokens | `AdminRouteSecurityTest::testEveryStateChangingAdminRouteRequiresTheCsrfToken`, `AdminRouteSecurityTest::testEveryAdminRouteExceptSignInAndOutRequiresASignedInStaffMember`, `AdminBatch51Test::testCsrfMiddlewareBlocksStateChangeWithoutValidToken`, `AdminBatch52Test::testCsrfMiddlewareBlocksProductStateChangeWithoutToken` |
| Server-side validation renders inline field errors on Product, Variant, Tier, Photo, Brand, Category, Supplier, Purchase Order (create + receive line/batch errors), Order (payment & cancel), POS resolve and `validation.attributes` forms | `AdminBatch52Test::testProductEditingVariantsTiersPriceHistoryPhotosAndHooks`, `AdminBatch52Test::testBrandAndCategoryRulesPermissionsAndLogoUpload`, `AdminBatch53Test`, `CatalogTest` |
| Every list supports `ListQuery` search (`q`), `sort`, filters and pagination (`page`, `per_page`) matching `docs/api.md` | `AdminBatch51Test`, `AdminBatch52Test`, `AdminBatch53Test` |
| Brands/categories: `edit` creates/renames/uploads logo; `delete` archives/restores; in-use refuses archive (409); `GFH` prefix refused (422) | `AdminBatch52Test::testBrandAndCategoryRulesPermissionsAndLogoUpload`, `CatalogTest` |
| Suppliers (`procurement:view/edit`): list search/sort/filter, create/edit validation (422) and active/inactive toggle | `AdminBatch53Test::testSuppliersPermissionsFiltersValidationAndDeactivation`, `ProcurementTest::testSuppliersAreValidatedAndUnique` |
| PO receive flow: stock increases by accepted qty only; rejected qty stays out of stock; weighted-average cost updates; batch code and `expires_on` stored; over-receiving refused (422); second receive of a fully received line or PO refused (422) | `AdminBatch53Test::testPurchaseOrdersCreateSendCancelAndReceiveWithRejectedAndBatchCapture`, `ProcurementTest::testReceivingUpdatesStockAndAverageCostAndRecordsRejectedQuantities`, `ProcurementTest::testAnAveragePriceBuildsAcrossPurchaseOrders` |
| Orders: list/detail keep progress independent of payment status, render paid & delivery details (`paid_at`), enforce reference & overpayment validation (422), require reason to cancel and render `order.detail.panels` hook | `AdminBatch53Test::testOrdersListDetailProgressPaymentCancelAndExtensionPanelHook`, `AdminRouterSmokeTest::testSignedInAdminCanLoadEveryAdminGetPageWithoutErrors`, `OrderTest` |
| Customers: list filters (`has_account`) and one-time password reset link generation restricted to organization Admins (403 for Manager/Staff; 422 without online account) | `AdminBatch53Test::testCustomersListAndAdminOnlyPasswordResetLink`, `PasswordResetTest` |
| POS sales & `needs_review` queue: queue filtering/badge counts, detail view and required resolution note (422 when short; 403 for view-only) | `AdminBatch53Test::testPosSalesListNeedsReviewQueueAndResolve`, `AdminRouterSmokeTest::testSignedInAdminCanLoadEveryAdminGetPageWithoutErrors`, `PosTest` |
| Phase 1 repairs: a sent purchase order saves and shows its sent date (migration 0007 backfills older ones from the audit log) | `Phase1FixesTest::testSendingAPurchaseOrderSavesTheSentDate`, `Phase1FixesTest::testMigrationBackfillGivesAlreadySentOrdersTheirRealSentDate`, `AdminRouterSmokeTest` |
| Average cost is stored with four decimals and displayed rounded half up (₱124.67 for 124.6667) | `FormatTest::testPesoRoundsHalfUpInsteadOfCuttingOffExtraDecimals`, `Phase1FixesTest::testAverageCostIsStoredExactlyAndShownRoundedHalfUp` |
| Receive form offers batch code, expiry and bin only for variants that track batches; an expiry before today (Asia/Manila) is refused with a field error, today is accepted; over-receiving is refused for its quantities, not for empty optional fields | `Phase1FixesTest::testReceiveRefusesAnExpiryDateBeforeTodayButAcceptsTodayInManila`, `Phase1FixesTest::testReceiveStillRefusesAMalformedExpiryDateWithAFieldError`, `Phase1FixesTest::testReceivingMoreThanOrderedIsRefusedBecauseOfTheQuantitiesNotTheEmptyOptionalFields`, `Phase1FixesTest::testAPlainVariantIsReceivedWithoutAnyBatchFields`, `AdminRouterSmokeTest` |
| A partly paid order reads "Partly paid (₱X of ₱Y)" on the list and the order page while the stored status and the core rule are unchanged; the overpayment error uses ₱ | `FormatTest::testPaymentStatusReadsPartlyPaidOnlyBetweenZeroAndTheTotal`, `Phase1FixesTest::testPartialPaymentKeepsTheStoredStatusAndReadsPartlyPaid`, `Phase1FixesTest::testTheOverpaymentErrorUsesThePesoSign`, `AdminRouterSmokeTest` |
| Moving an order forward logs every step passed, in order, and stock is sold once on completion | `Phase1FixesTest::testMovingStraightToCompletedLogsEveryStepInOrderAndSellsStockOnce`, `Phase1FixesTest::testASingleStepMoveLogsOneEntryAndAJumpAheadLogsEachStepPassed` |
| Resolving a `needs_review` POS sale needs a real note and changes no stock | `Phase1FixesTest::testResolvingAReviewWithAnEmptyNoteIsRefusedAndTheSaleStaysInTheQueue`, `Phase1FixesTest::testResolvingAReviewDoesNotChangeStock` |
| Reports page: only people with `reports_admin:view` (Admin, Manager) open it; Cashier and Staff get 403, signed-out gets 401; the sidebar link follows the same rule | `AdminBatch54aTest::testTheCashierIsRefusedTheReportsPageAndSoIsStaff`, `AdminBatch54aTest::testTheSidebarShowsReportsOnlyToPeopleWhoMayOpenIt`, `AdminRouteSecurityTest` |
| Reports day boundaries are Asia/Manila: a sale at 23:30 belongs to that day and one at 00:30 to the next, in the day, month and year totals and in the default "today" | `AdminBatch54aTest::testADayBelongsToManilaTimeNotUtc`, `AdminBatch54aTest::testTheDefaultDayFollowsTheManilaClock` |
| Reports totals are exact, the breakdown rows add up to the total, and the totals equal the dashboard's (same views, same `period()`) | `AdminBatch54aTest::testDayMonthAndYearTotalsBreakdownsAndTheDashboardAllAgree` |
| Empty periods show zeros (every branch, day or month still listed); invalid day, month or year values give a field error (422) and never reach SQL | `AdminBatch54aTest::testAnEmptyPeriodShowsZerosNotErrors`, `AdminBatch54aTest::testInvalidDatesAreRefusedWithAFieldError`, `AdminBatch54aTest::testThePageShowsTheFiguresTheRuleTheChartAndTheFieldError` |
| `reports.tabs` extension tabs render (ids cleaned, the built-in Sales tab cannot be replaced, unlabelled tabs dropped); no tab strip without an extension | `AdminBatch54aTest::testAnExtensionTabRendersThroughTheReportsTabsHook`, `AdminBatch54aTest::testThePageHasNoTabsWhenNoExtensionAddsOne` |
| Every Reports view (day, month, year, and an invalid date) is requested through the real router; a controller missing from the `/{org}` route group's `use (...)` list fails here | `AdminRouterSmokeTest::testSignedInAdminCanLoadEveryAdminGetPageWithoutErrors` |
| Customers: Orders and Lifetime spent both leave out cancelled orders | `AdminBatch54aTest::testCustomersOrdersCountAndLifetimeSpentBothLeaveOutCancelledOrders` |
| Team (`/{org}/admin/team`): Manager, Staff and Cashier get 403 on every Team page and POST; signed-out visitors are redirected to sign-in; a missing or wrong CSRF token is refused and changes nothing | `AdminBatch54bTest::testManagerStaffAndCashierAreRefusedOnEveryTeamPageAndPost`, `testOnlyAnAdminHasReportsAdminEditSoOnlyAnAdminOpensTeam`, `testSignedOutVisitorsAreRedirectedToSignInAndSignedOutPostsChangeNothing`, `testEveryTeamWriteRefusesAMissingOrWrongCsrfTokenAndChangesNothing`, `testTheListServiceMethodsRefuseAnyoneWhoIsNotAnAdmin`, `AdminRouteSecurityTest` |
| Team: an Admin creates, edits, switches Staff and Cashier, deactivates and reactivates people in their own organization; no password is set or shown; nothing is deleted | `AdminBatch54bTest::testAnAdminCreatesAStaffAndACashierAccountInTheirOwnOrganization`, `testCreateValidatesTheFieldsAndRefusesADuplicateOnTheTeam`, `testAddingSomeoneWhoAlreadyHasAnAccountElsewhereChangesNothingAboutThatAccount`, `testAnAdminEditsNameEmailAndSwitchesBetweenStaffAndCashier`, `testEditRefusesBadValuesAndAnEmailAnotherAccountUsesAndKeepsTheOldData`, `testDeactivateAndReactivateWorkForStaffAndCashierAndNothingIsDeleted` |
| Team: a deactivated member cannot sign in and an open session stops working; deactivating someone who also works elsewhere only ends access here | `AdminBatch54bTest::testADeactivatedMemberCannotSignIn`, `testDeactivatingSomeoneWhoAlsoWorksElsewhereOnlyEndsAccessHere` |
| Team: tampered POSTs fail and change nothing: role set to admin, manager or a group role (create and edit; an edit is rolled back whole), another organization's user id, the actor's own id, an Admin or Manager target, a group-level account, a malformed id | `AdminBatch54bTest::testATamperedRoleIsRefusedOnCreateAndNothingIsCreated`, `testATamperedRoleIsRefusedOnEditAndTheWholeSaveIsRolledBack`, `testAUserFromAnotherOrganizationIsOutOfReachOnEveryTeamRoute`, `testTheActorsOwnAccountCannotBeChangedFromTheTeamPages`, `testAdminAndManagerTargetsCannotBeChanged`, `testAGroupLevelAccountIsNotOnTheTeamAndCannotBeReached`, `testAMalformedMemberIdIsNotFound` |
| Team reset link: shown once, not stored in plain text, not in the audit log or the application log, sent with no-cache headers, no password shown; a newer link retires the older one; the Reset links page still works | `AdminBatch54bTest::testAResetLinkIsShownOnceNeverStoredOrLoggedAndSentWithNoCacheHeaders`, `testANewResetLinkRetiresTheOldOneAndAPendingMemberCanGetOne`, `testTheExistingResetLinksPageStillWorksForAnAdmin`, `PasswordResetTest` |
| Team list: this organization only, Admin and Manager rows read-only with no actions, group-level accounts left out; search, sort, role and status filters and pagination with the `docs/api.md` parameters; unknown filters give a 422 field error | `AdminBatch54bTest::testTheListShowsOnlyThisOrganizationsTeamWithAdminAndManagerRowsReadOnly`, `testTheListSearchesSortsFiltersAndPaginatesWithTheSharedParameters`, `testTheListPageRefusesAnUnknownFilterOrSortWithAFieldError` |
| Reports polish: an empty period's Cost badge reads "No cost recorded" (with sales it still reads "Cost known for X% of sales"); a date that does not exist (2026-02-30) has its own message; the example date comes from today in Asia/Manila | `AdminBatch54bTest::testAnEmptyPeriodsCostBadgeSaysNoCostRecordedNotADash`, `testADateThatDoesNotExistGetsItsOwnMessageAndTheExampleComesFromTodayInManila`, `AdminBatch54aTest::testThePageShowsTheFiguresTheRuleTheChartAndTheFieldError` |
| Settings (`/{org}/admin/settings`): Manager, Staff, Cashier, a Super Admin and an Admin of another organization get 403 on every Settings page and POST; signed-out visitors are redirected to sign-in; a missing or wrong CSRF token is refused and changes nothing; a refused request writes no audit entry | `AdminBatch54c1Test::testManagerStaffCashierSuperAdminAndOutsiderAreRefusedOnEveryPageAndPost`, `testSignedOutVisitorsAreRedirectedToSignInAndSignedOutPostsChangeNothing`, `testEveryWriteRefusesAMissingOrWrongCsrfTokenAndChangesNothing`, `AdminRouteSecurityTest` |
| `BranchService` and `TerminalService` refuse a non-Admin actor on every method even when the controller is bypassed | `AdminBatch54c1Test::testTheServiceMethodsRefuseAnyoneWhoIsNotAnAdminEvenWhenTheControllerIsBypassed` |
| The hub lists every registered section with a description and a link; the sidebar Settings item opens it for an Admin and is not shown to a Manager | `AdminBatch54c1Test::testTheHubListsEveryRegisteredSectionWithADescriptionAndALink`, `testOnlyAnAdminOpensTheHubAndTheSidebarSettingsLinkOpensIt`, `AdminNavigationTest` |
| The hub, Branches (list, new, edit) and Terminals (list, new, edit) are requested through the real router; a controller missing from the `/{org}` route group's `use (...)` list fails here | `AdminRouterSmokeTest::testSignedInAdminCanLoadEveryAdminGetPageWithoutErrors` |
| Branches: create, edit and list (name, code, kind, flags, status, active terminals), output escaped, every validation message (code, name, address, city, province, region, kind), duplicate code, optional fields stored as null, no audit entry when nothing changed | `AdminBatch54c1Test::testAnAdminCreatesABranchAndItAppearsInTheListWithEscapedOutput`, `testTheListShowsNameCodeKindFlagsStatusAndTheNumberOfActiveTerminals`, `testBranchCreateValidatesEveryFieldWithItsOwnMessage`, `testADuplicateBranchCodeIsRefusedAndNothingIsAdded`, `testAnAdminEditsABranchAndTheCodeIsShownReadOnly`, `testBranchEditShowsEveryValidationMessageAndKeepsWhatWasTyped`, `testSavingABranchWithNoChangeWritesNoAuditEntry` |
| Tampered code, kind, status or branch id is refused or ignored and nothing is saved | `AdminBatch54c1Test::testATamperedCodeKindStatusOrBranchIdIsRefusedOrIgnoredAndNothingIsSaved`, `testATerminalCannotChangeBranchOrCodeAndLastSeenIsNeverEdited` |
| Delivery branch: the designation moves atomically and there is never more than one; a failed save leaves the old one in place; unticking is refused with a message; an inactive branch cannot be the delivery branch or a pickup point | `AdminBatch54c1Test::testMakingABranchTheDeliveryBranchMovesTheDesignationAtomically`, `testAFailedMoveLeavesTheOldDeliveryBranchInPlace`, `testUntickingTheCurrentDeliveryBranchIsRefusedWithAClearMessage`, `testAnInactiveBranchCannotBecomeTheDeliveryBranchOrAPickupPoint` |
| Branch deactivation is refused for the delivery branch, an active terminal, stock on hand, and the pickup or ship-from branch of an open order, each with its own message and test; a branch nothing uses deactivates and reactivates; nothing is deleted | `AdminBatch54c1Test::testDeactivationIsRefusedForTheDeliveryBranch`, `testDeactivationIsRefusedWhileTheBranchHasAnActiveTerminal`, `testDeactivationIsRefusedWhileTheBranchHoldsStockOnHand`, `testDeactivationIsRefusedForTheBranchOfAnOpenPickupOrder`, `testDeactivationIsRefusedForTheShipFromBranchOfAnOpenOrder`, `testEveryReasonIsListedWhenSeveralApply`, `testABranchNothingUsesDeactivatesAndReactivatesAndNothingIsDeleted`, `testReactivationIsAlwaysAllowedEvenIfTheBranchHasAnInactiveHistory` |
| The online pickup list still shows only active pickup points and ship-from still uses the active delivery branch | `AdminBatch54c1Test::testTheOnlinePickupListAndShipFromStillFollowTheBranchStatus`, `OrderTest` |
| Terminals: create, edit and list, every validation message, duplicate code, only active branches offered, branch and code fixed after create, an open POS shift blocks deactivation, an inactive branch blocks reactivation, nothing deleted | `AdminBatch54c1Test::testAnAdminCreatesEditsAndListsTerminals`, `testTerminalCreateValidatesEveryFieldAndRefusesADuplicateCode`, `testOnlyActiveBranchesAreOfferedAndAnInactiveBranchIsRefused`, `testATerminalCannotChangeBranchOrCodeAndLastSeenIsNeverEdited`, `testAnOpenPosShiftBlocksDeactivationUntilTheShiftIsClosed`, `testAnInactiveBranchBlocksReactivationOfItsTerminal`, `testATerminalDeactivatesAndReactivatesAndNothingIsDeleted` |
| Every Settings change writes an organization audit entry with the actor and no secret (no token, password or connection string) | `AdminBatch54c1Test::testEveryChangeWritesAnAuditEntryWithTheActorAndNoSecret` |
| Development seed (`php bin/seed.php --demo`) is repeatable with no duplicate rows | `AdminBatch51Test::testDevelopmentSeedIsCompleteAndIdempotent` |

To reset the demo organization's data, follow `docs/reset-demo.md`.

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

## Browser hand-check, Batch 5.4b Team and Reports polish

Sign in as the demo Admin, then:

1. The sidebar shows **Team** and **Settings** (Manage group). Sign in as a Manager: Reports is shown, Team and Settings are not. Typing `/demo/admin/team` as a Manager gives the 403 page.
2. **Team** lists everyone on the team. Admin and Manager rows say "Read-only" and have no link. Try the search box, the Role and Status filters, the column headings (sort) and Previous/Next with 10 rows per page.
3. **Add team member**: add a Cashier. You land on their page with a notice, their status is Pending, and no password was asked for.
4. On that page choose **Generate password reset link**. The link appears once. Open it in a private window, set a password, sign in as that person. Press Back and reload the reset page: the link must not reappear from the browser cache.
5. Change the Cashier's role to Staff and the name, press **Save changes**. Try an email that another person uses: the form shows the error and keeps what you typed.
6. **Deactivate** the person. Their status reads Inactive; in a private window they cannot sign in. **Reactivate** brings them back.
7. Open your own row from the address bar (`/demo/admin/team/{your id}`): it is read-only with no buttons.
8. **Reports**, Month view: the Month box looks like the Day and Year boxes (same height, border and font). At 1280 and 1920 pixels wide the Cost card badge stays inside the card and the page has no sideways scrollbar. Pick a year with no sales: the Cost card says "No cost recorded". Enter 2026-02-30 as a Day: the message says the date does not exist; enter `16/11/2026`: the message asks for YYYY-MM-DD with an example from today's date.

## Browser hand-check, Batch 5.4c-1 Settings (Branches and Terminals)

Sign in as the demo Admin (the demo seed has three branches and two terminals), then:

1. The sidebar shows **Settings** (Manage group). Click it: the hub shows a **Branches** card and a **Terminals** card, each with a description and an Open button. Sign in as a Manager: the Settings link is not shown, and typing `/demo/admin/settings` gives the 403 page. Check Staff and Cashier the same way.
2. **Branches** lists every branch with name, code, kind, flags (Pickup point, Delivery branch), status and active terminals. Click a name, then **Back to branches**.
3. **Add branch**: leave everything empty and press Add branch; each field shows its own message and keeps what you typed. Enter the code `bcd-main` in lower case: it is refused; enter a code that already exists: it is refused. Add a branch with the code `TEST-1`, kind Store, Pickup point ticked.
4. Open `TEST-1`. The Code box is greyed out and cannot be typed in. Change the name and city, press **Save changes**, and see the notice and the new values.
5. Tick **Delivery branch** on `TEST-1` and save. Open the list: only `TEST-1` has the Delivery branch flag. Now untick it on `TEST-1` and save: the page explains that you tick it on another branch instead and nothing changes.
6. Press **Deactivate** on the delivery branch: the message says it is the delivery branch. Move the delivery flag to another branch, then try to deactivate a branch that has an active terminal, one that holds stock (see Inventory), and one that is the pickup or ship-from branch of an open order (see Orders): each shows its own reason and the status stays Active. Deactivate `TEST-1` (nothing uses it): its status reads Inactive, and check `GET /demo/api/v1/checkout/options` (signed in or not): `TEST-1` is no longer in `pickup_branches`. **Reactivate** it.
7. Deactivate `TEST-1` again, open it, tick **Pickup point** while it is inactive and save: the page says to reactivate it first. Reactivate it.
8. **Terminals** lists code, name, branch, status and last seen (Never until the POS has been used). **Add terminal**: the Branch box offers only active branches (deactivate `TEST-1` first to check it disappears). Add terminal `T9` at an active branch. Try `t9`, a 7 character code and an existing code: each is refused with its own message.
9. Open `T9`: the Code and Branch boxes are greyed out, only the Name can change. Deactivate it, then deactivate its branch (it has no other active terminal and nothing else uses it), then try to reactivate `T9`: the page says its branch is inactive. Reactivate the branch first, then the terminal.
10. Open a POS shift on a terminal in the POS app (or use the seed's open shift) and press **Deactivate** on that terminal in Settings: the page says it has an open shift. Close the shift and try again.
11. Try the address bar: `/demo/admin/settings/branches/not-a-valid-id` gives the 404 page. There is no Delete button anywhere.
12. At 1280 and 1920 pixels wide the tables and forms fit with no sideways scrollbar, and every field can be reached and used with the keyboard alone.
