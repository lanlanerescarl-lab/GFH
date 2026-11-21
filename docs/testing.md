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
| Batch 5.4c-2 permissions: Manager, Staff, Cashier, a Super Admin and an Admin of another organization get 403 on every new page and POST; signed-out visitors are redirected; a missing or wrong CSRF token is refused and changes nothing; the three services refuse a non-Admin actor on every method even when the controller is bypassed | `AdminBatch54c2Test::testManagerStaffCashierSuperAdminAndOutsiderAreRefusedOnEveryNewPageAndPost`, `testSignedOutVisitorsAreRedirectedToSignInAndSignedOutPostsChangeNothing`, `testEveryWriteRefusesAMissingOrWrongCsrfTokenAndChangesNothing`, `testTheServiceMethodsRefuseAnyoneWhoIsNotAnAdminEvenWhenTheControllerIsBypassed` |
| The hub lists all five sections; the router smoke test requests every new GET page | `AdminBatch54c2Test::testTheHubListsAllFiveSectionsWithTheThreeNewOnesLinked`, `AdminBatch54c1Test::testTheHubListsEveryRegisteredSectionWithADescriptionAndALink`, `AdminRouterSmokeTest` |
| Discount rules: create (audited, escaped output), every field validated with boundaries, duplicate key, edit with a per-change audit, no audit when nothing changed, tampered key refused and tampered active flag ignored, deactivate and reactivate, unknown ids, no delete route | `AdminBatch54c2Test::testAnAdminCreatesADiscountRuleAndItIsAuditedAndListedWithEscapedOutput`, `testEveryDiscountFieldIsValidatedAndABadFormChangesNothing`, `testTheBoundariesOfEveryDiscountFieldAreAccepted`, `testADuplicateKeyIsRefusedEvenAgainstARuleThatAlreadyExists`, `testAnAdminEditsALabelPercentAndVatFlagAndEachChangeIsAudited`, `testSavingTheSameValuesWritesNothingAndAuditsNothing`, `testATamperedKeyIsRefusedAndATamperedActiveFlagIsIgnored`, `testDeactivateAndReactivateFlipTheFlagAreAuditedAndRefuseARepeat`, `testAnUnknownOrMalformedRuleIdIsNotFoundAndThereIsNoWayToDeleteARule` |
| Editing a discount rule changes future sales only: a recorded POS sale keeps its stored discount amounts after the percent and VAT flag are edited; a deactivated rule is not in the POS snapshot and cannot be applied | `AdminBatch54c2Test::testEditingARuleChangesFutureSalesOnlyAndARecordedSaleKeepsItsDiscountAmounts`, `testADeactivatedRuleIsNotOfferedToThePosAndIsOfferedAgainOnceReactivated` |
| Order steps: list in path order with order counts; the label always saves; each structural change is refused while an order is open and allowed when none is; an order on a terminal step or a cancelled order is not open | `AdminBatch54c2Test::testTheListShowsEveryStepInPathOrderWithItsConditionAndHowManyOrdersAreOnIt`, `testTheLabelCanAlwaysBeEditedEvenWhileOrdersAreOpen`, `testEveryStructuralChangeIsRefusedWhileAnOrderIsOpen`, `testAnOrderOnAFinishedStepOrACancelledOrderDoesNotCountAsOpen`, `testEveryStructuralChangeIsAllowedWhenNoOrderIsOpen` |
| Order steps: a step an order or history row references cannot be deleted even with no open order; each invariant (a completing last step, a step that applies always, terminal means complete, no order starts finished) is refused and rolled back with no audit entry | `AdminBatch54c2Test::testAStepThatAnOrderOrAHistoryEntryReferencesCannotBeDeletedEvenWithNoOpenOrder`, `testAChangeThatWouldLeaveNoStepThatCompletesAnOrderIsRefusedAndRolledBack`, `testAChangeThatWouldLeaveNoStepThatAppliesToEveryOrderIsRefusedAndRolledBack`, `testALastStepMustMapToCompleteBecauseStockIsOnlySoldOnACompleteStep`, `testNoOrderMayStartOnAFinishedStep` |
| Order steps: only the six conditions `OrderService` recognises and the three `maps_to` values are accepted; key shape, reserved key, read-only key, position range and uniqueness; unknown keys are 404; a new step joins the right order paths | `AdminBatch54c2Test::testAnApplyConditionOrMapsToTheOrderFlowDoesNotKnowIsRefused`, `testEveryConditionTheOrderFlowRecognisesIsAcceptedAndNothingElseEverApplies`, `testAStepKeyIsChosenOnCreateWithAStrictShapeAndCannotChangeAfterwards`, `testThePositionMustBeAWholeNumberFromOneToNineThousandNineHundredNinetyNineAndFree`, `testAnUnknownOrMalformedStepKeyIsNotFound`, `testANewStepJoinsThePathOfTheOrdersItAppliesTo` |
| Organization settings: core and extension settings are listed with label, type, value, default, range and the extension that adds them; a valid change saves as a typed value and is audited; only changed values are written; an out-of-range, non-numeric or unknown value is refused inline (422) and the whole save is rolled back; boolean, whole number and text keep their types | `AdminBatch54c2Test::testThePageListsEveryCoreSettingWithItsLabelTypeCurrentValueAndDefault`, `testExtensionSettingsAreListedWithTheirTypesLabelsRangesAndTheExtensionThatAddsThem`, `testAValidChangeIsSavedAsAWholeNumberAndAuditedAndOnlyChangedValuesAreWritten`, `testAnOutOfRangeOrNonNumericValueIsRefusedInlineAndNothingIsSavedNotEvenTheValidFields`, `testAnUnknownSettingKeyIsRefusedWith422AndNothingIsSaved`, `testABooleanAndAWholeNumberAndTextKeepTheirTypesForExtensionSettings`, `testExtensionSettingsKeepTheirOwnRangeAndTheyRefuseAnythingButOnOrOff`, `testSettingsAreDescribedForTheServiceWithExtensionMetadata` |
| Development seed (`php bin/seed.php --demo`) is repeatable with no duplicate rows | `AdminBatch51Test::testDevelopmentSeedIsCompleteAndIdempotent` |

## Stage 6 Batch 6.3a acceptance rules and the tests that check them (customer account API)

Back end only: `CustomerAccountService` and `/{org}/api/v1/account/...` (see "Account (customer)" in `docs/api.md`). Every request in
`CustomerAccountTest` goes through the real `App` + `Router` + `core/routes.php` + middleware stack with in-memory customer and staff sessions.

| Rule | Test |
| --- | --- |
| Every account endpoint is reachable through the real router; a controller missing from the `/{org}` route group's `use (...)` list answers 500 and fails here | `CustomerAccountTest::testEveryAccountEndpointIsReachableThroughTheRealRouterAndNeverAnswers500` |
| A signed-out request gets 401 on every endpoint and changes nothing; a signed-out write with no CSRF token is refused by CSRF first (403), as on every customer route | `CustomerAccountTest::testSignedOutRequestsGet401OnEveryEndpointAndChangeNothing`, `testASignedOutWriteWithoutATokenIsRefusedByCsrfFirstAsOnEveryCustomerRoute` |
| A staff session (even an Admin of the same organization) is not a customer session, and a staff CSRF token does not open the customer session | `CustomerAccountTest::testAStaffSessionIsNotACustomerSession` |
| A missing, empty, wrong or foreign CSRF token is refused on all six writes (403) and changes nothing, no audit entry, same session id, same password | `CustomerAccountTest::testEveryWriteRefusesAMissingOrWrongCsrfTokenAndChangesNothing`, `testTheCsrfTokenIsAlsoAcceptedAsAFormField` |
| The routes are exactly the eight documented ones, each on the tenant, the customer session (not staff) and `requireCustomer`, and each write carries CSRF | `AdminRouteSecurityTest::testTheAccountRoutesAreExactlyTheEightThatAreDocumented`, `testEveryAccountRouteNeedsTheTenantTheCustomerSessionAndASignedInCustomer`, `testEveryStateChangingAccountRouteRequiresTheCsrfToken`, `ApiAuthorizationTest::testEveryAccountRouteRunsBehindTheCustomerMiddleware`, `testEveryAccountEndpointTakesTheCustomerFromTheSessionAndNeverFromTheRequest`, `testOnlyTheKnownOpenEndpointsAreOpen` |
| Customer A cannot see or change customer B's profile; a customer id in the URL, query or body is ignored | `CustomerAccountTest::testTheProfileShowsOnlyTheSignedInCustomerWhateverIdIsSent`, `testCustomerAIsNeverShownOrChangedByCustomerBsRequestsEvenWithAIdInTheBody` |
| Customer A cannot update, delete or set the default of B's address: a plain 404, the same answer as an unknown or malformed id, also for a body that would fail validation; nothing changes and no audit entry is written | `CustomerAccountTest::testAnotherCustomersAddressIsAPlain404ForUpdateDeleteAndSetDefault`, `testAMalformedAddressIdIsTheSame404` |
| The repository filters every address query by the customer id on its own (the second guard behind the service's ownership check) | `CustomerAccountTest::testTheRepositoryItselfNeverTouchesAnotherCustomersAddress` |
| Profile update validates the name and phone with the registration messages, and never changes the email, status or password hash; an unchanged save writes no audit entry; the audit entry names fields, not values | `CustomerAccountTest::testProfileUpdateChangesNameAndPhoneAndNeverEmailStatusOrPassword`, `testProfileUpdateValidatesEveryFieldWithTheRegistrationMessages`, `testProfileBoundariesAndClearingThePhone`, `testProfileUpdateAuditNamesTheFieldsAndNeverTheValues`, `testMalformedJsonIsABadRequestAndAFormBodyWorksToo` |
| Address validation: each field has its own message; label 40, line 1 200, barangay, city and province 100; postal code exactly 4 digits as a string; text tidied; nothing saved on error | `CustomerAccountTest::testAddressValidationGivesEachFieldItsOwnMessage`, `testAddressBoundariesAreAcceptedAndTextIsTidied`, `testUpdatingAnAddressReplacesTheFieldsAndClearsLeftOutOptionalOnes` |
| The first address becomes the default and no input changes that; exactly one default after create, set default, edit and delete; deleting the default promotes the newest remaining address; the list is default first, then newest | `CustomerAccountTest::testTheFirstAddressBecomesTheDefaultAndNoInputCanChangeThat`, `testExactlyOneDefaultAfterEveryOperation`, `testDeletingTheDefaultPromotesTheNewestRemainingAddressNotTheOldest`, `testTheListShowsTheDefaultFirstThenTheNewest` |
| At most 10 addresses per customer (409 on the eleventh, nothing saved, per customer, a deleted slot can be reused) | `CustomerAccountTest::testNoMoreThanTenAddressesPerCustomer` |
| Every address change writes an audit entry with the customer id and no address text | `CustomerAccountTest::testEveryAddressChangeWritesAnAuditEntryWithNoAddressTextInIt` |
| A wrong current password is a 422 on `current_password`, recorded under the `customer` scope, changes nothing and leaves the attempted password out of the audit log | `CustomerAccountTest::testAWrongCurrentPasswordIsA422OnThatFieldIsRecordedAndChangesNothing` |
| The lockout is the sign-in lockout: after `LOGIN_MAX_ATTEMPTS` wrong current passwords even the right one gets 429 with the sign-in message and `Retry-After`, sign-in is locked too, failed sign-ins count here, a correct password clears earlier failures, a blank field is not counted | `CustomerAccountTest::testWrongCurrentPasswordsLockTheCustomerOutAtTheSameLimitAsSignIn`, `testFailedSignInsCountTowardsThePasswordChangeLockoutToo`, `testACorrectPasswordClearsEarlierFailuresLikeSignInDoes`, `testABlankCurrentPasswordIsAFormErrorNotAGuess` |
| A weak password, a password equal to the email (any case), a too long one, only spaces and a mismatched or missing confirmation are refused (422 on `new_password` or `new_password_confirmation`) and nothing changes | `CustomerAccountTest::testWeakEmailAndMismatchedNewPasswordsAreRefusedAndNothingChanges` |
| Success stores a real `password_hash` (not the plain text), the old password stops working and the new one works, the session id changes and the customer stays signed in | `CustomerAccountTest::testChangingThePasswordStoresARealHashEndsTheOldPasswordAndRegeneratesTheSession` |
| `customer.password_changed` is audited and no audit row about the customer holds a password, a hash or a token | `CustomerAccountTest::testThePasswordChangeAuditEntryExistsAndHoldsNoPasswordHashOrToken` |
| Other devices stay signed in (PHP sessions), as `docs/api.md` says | `CustomerAccountTest::testOtherDevicesStaySignedInBecauseSessionsArePhpSessions` |
| Unsupported methods answer 405 and reach no service | `CustomerAccountTest::testUnsupportedMethodsAnswer405AndNeverReachAService` |

`CsrfTest` is a unit test of the middleware and the token, and it lists no routes, so it is not extended; the route lists are checked in `AdminRouteSecurityTest`
(real routes) and the per-write CSRF behaviour in `CustomerAccountTest`. `AdminRouterSmokeTest` loads Admin pages with a staff session and is not extended.

To run the PHPUnit 9 setup used in the sandbox (PHP 8.3, PostgreSQL 16): use a copy of `phpunit.xml.dist` without the PHPUnit 11 schema line and run
`phpunit -c <copy> --filter CustomerAccountTest`. The repository's own `phpunit.xml.dist` is for PHPUnit 11.

To reset the demo organization's data, follow `docs/reset-demo.md`.

## Stage 6 acceptance rules and the tests that check them

**Batch 6.1, Online Shop shell and browsing** (`tests/Database/ShopRouterSmokeTest.php`, `tests/Database/ShopBrowseTest.php`, base class `tests/Support/ShopTestCase.php`). The Database tests need the two `_test` databases, as before.

| Rule | Test |
| --- | --- |
| Every Shop GET page (home, listing with each filter, sort and a battery of bad values, product detail with and without `?variant=`, the 404) answers 200 (or the intended 404) as a guest and as a signed-in customer, with no PHP warning and no error text. The demo organization has no product photos, so these pages all render the inline placeholder. | `ShopRouterSmokeTest::testEveryShopGetPageLoadsForAGuestAndForASignedInCustomer` |
| Pages work with an empty catalog; an unknown organization or Shop path is a 404, not a 500. | `ShopRouterSmokeTest` (other two tests) |
| Listing: brand, category (a top-level category includes its children), price range, text, number and yes/no attributes, combined filters; drafts never show. | `ShopBrowseTest::testListingFiltersByBrandCategoryPriceAndAttributes` |
| Sorting by name, price and newest, both directions, with the chosen sort selected in the box. | `testSortOrders` |
| Search by name or SKU, the result count, the removable search chip, the text kept in the header box. | `testSearchFindsProductsShowsTheCountAndKeepsTheTextInTheBox` |
| Result count, one removable chip per filter (the link drops only that filter), clear all, the empty state. | `testResultCountChipsAndTheEmptyState` |
| Pagination keeps the whole state in every link; a page past the end shows the last page; a bad page number is ignored with a notice. | `testPaginationUsesTheSharedQueryParameters` |
| Unknown or malformed filter values (wrong shape, nested arrays, huge numbers, bad keys) are ignored with a notice or shown as a field error, never a 500. | `testUnknownAndMalformedFilterValuesAreIgnoredOrShownAsFieldErrorsNeverAServerError` |
| Filter options show counts, and choosing a brand or category does not shrink its own list. | `testFilterOptionsShowCountsAndStayWideWhenOneIsChosen` |
| Detail: the default variant is rendered by the server, every variant's data is in data attributes, `?variant=` renders another variant, the quantity box follows the variant's step and minimum, the VAT note appears once, Add to cart is disabled with its note, description, attribute table, breadcrumb. | `testDetailRendersTheDefaultVariantAndTheDataOfEveryVariant` |
| An unknown, draft, discontinued or no-active-variant product is the Shop's 404 page. | `testAnUnknownOrInactiveSlugIsA404Page` |
| A product with no photo shows the inline placeholder; photos are linked with size, lazy loading and the primary first; thumbnails link to every photo. | `testProductsWithoutPhotosShowThePlaceholderAndPhotosAreLinkedSafely` |
| Tier prices appear only for a signed-in customer who qualifies (quantity tiers for any signed-in customer, tier and type tiers only on a match), never for a guest. | `testTierPricesAppearOnlyForASignedInCustomerWhoQualifies` |
| No cost, trade price, reorder level or exact stock figure appears in any page. | `testNoCostTradePriceOrExactStockFigureAppearsInAnyPage` |
| HTML in a product, brand, category, variant, attribute or search text is escaped on every page. | `testHtmlInProductDataIsEscapedOnEveryPage` |
| No page contains a URL to another host. | `testNoPageContainsAUrlToAnotherHost` (and the existing `NoExternalHostsTest` for the templates and assets) |
| Alt text and image sizes, a label for every field, the skip link, one h1 and no skipped heading level, on six kinds of page. | `testAltTextLabelsSkipLinkAndHeadingOrder` |
| Home: neutral line or the organization's own `shop_tagline` (plain text), top-level category tiles, newest first. | `testHomeShowsCategoriesNewestProductsAndTheOrganizationsOwnLine` |
| `orgs/{slug}/shop/home.php` replaces the built-in home page (a temporary organization folder, removed afterwards). | `testAnOrganizationCanReplaceTheShopHomePage` |
| `navigation.shop` and `product.detail.sections` items are cleaned, extensions receive only public fields, section titles are escaped and bodies printed as written. | `testNavigationHookItemsAreCleanedAndTheShopLinkIsFirst`, `testProductSectionsHookIsCleanedGetsOnlyPublicFieldsAndIsPrintedByTheTemplate` |

`TemplateShellTest` and `AppViewTest` now copy the Shop partials into their temporary template trees, because the Shop layout is built from several partials.

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

## Browser hand-check, Batch 5.4c-2 Settings (Discount rules, Order steps, Organization settings)

First rerun `database/roles/04_grant_org.sql` on the organization database (it now lets the web role delete an unused order step). Then sign in as the demo Admin:

1. The Settings hub shows five cards: Branches, Terminals, **Discount rules**, **Order steps**, **Organization settings**. As a Manager, Staff and Cashier each new address gives the 403 page.
2. **Discount rules** lists the seeded rules (name, key, percent, VAT, status, sales). **Add discount rule**: submit empty (each field shows its message and keeps what you typed); try the key `Promo`, `1promo`, a 41 character key, and an existing key; try percent `101`, `-1`, `12.345`, `abc`. Add `promo10`, 10 percent. Open it: the Key box is greyed out. Change the percent to `12.5` and save.
3. In the POS app (or after a sync) sell something with a rule, then in Settings change that rule's percent: open the earlier sale under POS sales and check its discount and total did not change (its discount name follows a renamed rule). A new sale uses the new percent.
4. **Deactivate** `promo10`: the status reads Inactive, the POS no longer lists it after its next sync, and deactivating again shows a message. **Reactivate** it. There is no Delete button.
5. **Order steps** lists the steps in order with name, key, counts as, last step, applies when and orders. Rename a step and save: it works even with open orders (check Orders for one that is not completed or cancelled).
6. With an open order, try **Add step**, change a position, the Counts as box, the Last step switch, the Applies when box and **Delete step**: each is refused with the message that orders are open, and nothing changes. Finish or cancel every open order (or use a fresh demo database) and repeat: they work. After that, put things back.
7. On an empty-of-orders organization: make Completed no longer the last step, and change its Counts as to In progress, and delete it: each is refused (a step must map to Complete and be the last step). Make Placed apply to pickup only while no other step applies always: refused. Make Ready the last step: refused (a last step must map to Complete). Add a last step at position 5: refused (orders would be born finished). After each refusal the list is unchanged.
8. Try to delete a step that orders or history still use: refused with the count. The key box on an existing step is greyed out. `/demo/admin/settings/order-steps/Not-A-Key` gives the 404 page.
9. **Organization settings** lists the deposit percentage, reservation time and near-expiry warning with label, value, default and allowed range (and, for an organization with extensions, an Added by extensions card naming each extension). Change the deposit to `30` and save: the notice shows and the value stays. Enter `101` in one box and a valid number in another and save: the message shows under the bad box and neither is saved. Switch an extension on/off setting and save; reload and check it stayed.
10. At 1280 and 1920 pixels wide the tables and forms fit with no sideways scrollbar, and everything can be reached and used with the keyboard alone (including the switch).

## Hand-check, Batch 6.3a customer account API

Back end only, so check it with a REST client. PowerShell, with the demo organization migrated and `php -S localhost:8000 -t public` running. Two customers are used, `$s`/`$h` and `$s2`/`$h2`.
The function prints the status and body of failures as well as successes.

```powershell
$base = "http://localhost:8000/demo/api/v1"; function Call($sess, $hdr, $m, $p, $b) { try { Invoke-RestMethod -Method $m "$base$p" -WebSession $sess -Headers $hdr -ContentType "application/json" -Body ($b | ConvertTo-Json) | ConvertTo-Json -Depth 6 } catch { "$($_.Exception.Response.StatusCode.value__) $($_.ErrorDetails.Message)" } }
```

1. **Sign in two customers.** Register `ana-check@example.test` into `$s` and `ben-check@example.test` into `$s2`, then read each session's CSRF token (registering rotates it, so ask again):

   ```powershell
   $r = Invoke-RestMethod "$base/auth/customer/csrf" -SessionVariable s; $h = @{ "X-CSRF-Token" = $r.data.csrf_token }; Call $s $h POST /auth/customer/register @{ full_name = "Ana Check"; email = "ana-check@example.test"; password = "a-long-password-1" }; $h = @{ "X-CSRF-Token" = (Invoke-RestMethod "$base/auth/customer/csrf" -WebSession $s).data.csrf_token }
   $r2 = Invoke-RestMethod "$base/auth/customer/csrf" -SessionVariable s2; $h2 = @{ "X-CSRF-Token" = $r2.data.csrf_token }; Call $s2 $h2 POST /auth/customer/register @{ full_name = "Ben Check"; email = "ben-check@example.test"; password = "a-long-password-2" }; $h2 = @{ "X-CSRF-Token" = (Invoke-RestMethod "$base/auth/customer/csrf" -WebSession $s2).data.csrf_token }
   ```

2. **Signed out gives 401.** `$none = New-Object Microsoft.PowerShell.Commands.WebRequestSession; $nr = Invoke-RestMethod "$base/auth/customer/csrf" -WebSession $none; $hn = @{ "X-CSRF-Token" = $nr.data.csrf_token }; Call $none $hn GET /account/profile; Call $none $hn POST /account/addresses @{ line1 = "1 Road"; city = "Silay"; province = "Negros Occidental" }` both print `401`. With no `X-CSRF-Token` at all, a write prints `403` (CSRF is checked first).
3. **Profile.** `Call $s $h GET /account/profile` shows Ana's id, name, email and phone and no password. `Call $s $h PUT /account/profile @{ full_name = "Ana M. Check"; phone = "0917 123 4567"; email = "other@example.test"; status = "inactive" }`: the name and phone change, the email stays `ana-check@example.test`. `... @{ full_name = "A"; phone = "abc" }` prints `422` with a message for each field.
4. **Addresses.** Add three: `Call $s $h POST /account/addresses @{ label = "Home"; line1 = "12 Rizal Street"; city = "Bacolod City"; province = "Negros Occidental"; postal_code = "6100" }` (repeat with labels `Work` and `Parents`). The first has `is_default: true`, the others false, even if you add `is_default = $true`. `postal_code = "61"` and a `label` of 41 characters each print `422`. `Call $s $h GET /account/addresses` lists the default first, then the newest.
5. **Set the default.** Copy the id of `Parents` into `$p`, then `Call $s $h POST /account/addresses/$p/default`. The list now shows only `Parents` as the default and it is first. Editing another address (`PUT /account/addresses/{id}` with all fields) never moves the default.
6. **Delete the default.** `Call $s $h DELETE /account/addresses/$p`: the most recently created address that is left (`Work`) becomes the default. Delete the rest: the list is empty, and a new address is the default again.
7. **The limit.** Add 10 addresses; the 11th prints `409` and the list still has 10. Delete one and the next add works.
8. **Another customer's address.** Add an address as Ben (`$s2`) and copy its id into `$b`. As Ana run `Call $s $h PUT /account/addresses/$b @{ line1 = "x"; city = "x"; province = "x" }`, `Call $s $h POST /account/addresses/$b/default` and `Call $s $h DELETE /account/addresses/$b`: all print `404` with the same message as `DELETE /account/addresses/3f0c8a52-6b7e-4a55-9d0e-1c2b3a4d5e6f`. As Ben the address is unchanged.
9. **CSRF.** `Call $s @{} PUT /account/profile @{ full_name = "Nope Nope" }` and the same with `@{ "X-CSRF-Token" = "wrong" }` print `403` and the name does not change.
10. **Password.** `Call $s $h POST /account/password @{ current_password = "wrong-password-1"; new_password = "a-new-passphrase-7"; new_password_confirmation = "a-new-passphrase-7" }` prints `422` on `current_password`. Weak (`short`), the email address, and a different confirmation each print `422` on their own field. With the right current password it prints `password_changed: true`.
11. **Old and new password.** Sign in on a new session with the old password (`POST /auth/customer/login`): it prints `401`. The new password signs in. Your first session is still signed in (`Call $s $h GET /account/profile`), and so would a session on another device be: that is expected.
12. **Lockout.** Send wrong current passwords until the limit (`LOGIN_MAX_ATTEMPTS`, 5 by default): the next request, even with the right password, prints `429` ("Too many sign-in attempts") and sign-in is locked too. Wait `LOGIN_LOCKOUT_MINUTES` before testing again.
13. **Audit.** In the core database, `customer.profile_updated`, `customer.address_*`, `customer.password_changed` and `customer.password_change_failed` rows exist in `audit_log`, and none of their `detail` values contains a password, a hash, a token or an address.

## Browser hand-check, Batch 6.1 Online Shop shell and browsing

No browser was used to build Batch 6.1: the PHP was tested through the router, and the JavaScript modules were exercised in a simulated DOM (jsdom), which is not a browser. Layout, spacing and every visual rule below are unchecked until you do this. Run `php bin/publish-assets.php` if you changed an asset, start the site, and open `/demo/shop`. Do every step at **360 px** and at **1280 px** wide (browser developer tools, device toolbar), then repeat the keyboard steps with the mouse set aside.

1. **No sideways scroll.** At 360 px, scroll the home page, the listing, a product page and the 404 page (`/demo/shop/products/nope`): the page never scrolls sideways, no text runs off the edge, and long product names wrap.
2. **Header.** At 1280 px: brand, Shop link, search box on one row, no menu button. Below 720 px: brand and a **Menu** button; the button opens the Shop link, and Escape or a click outside closes it. No empty gap where Cart and Sign in will go (they are empty slots in this batch).
3. **Home.** The hero shows the organization name and a line (neutral text unless the organization has a `shop_tagline` setting); category tiles are two across at 360 px and four across at 1280 px; New arrivals shows the newest products; with no photos every card shows the soft placeholder box with a small outline mark, not a broken image.
4. **Listing at 1280 px.** Filters on the left (Category tree with counts, Brand, Price, attributes), results on the right, sort box above. Choose a category, a brand and a sort: the page reloads each time (the address changes and carries the state). Type a price range and press Enter or **Apply filters**: it does not reload while you tab between the two boxes. Chips appear for each filter; clicking one removes only that filter; **Clear all filters** removes them all. Copy the address into a new tab: the same list appears.
5. **Listing at 360 px.** Filters are behind a **Filters** button. It opens a panel from the left with a dark backdrop; Tab stays inside it; Escape, **Close** and the backdrop close it and put the focus back on the Filters button. The page behind does not scroll while it is open.
6. **Without JavaScript** (developer tools, disable JavaScript, reload): the filters sit above the results, **Apply** appears next to the sort box, the product page shows every photo stacked, **Show this option** appears under the variants, and the menu links are visible without the button.
7. **Bad addresses.** Try `/demo/shop/products?category_id=abc`, `?min_price=abc`, `?sort=bogus`, `?page=0`, `?page=9999`, `?brand_id[]=x`: each gives a normal page (a notice or a red message under the price box), never an error page.
8. **Search.** Search for part of a product name in the header: the result page says Search results, shows a chip, and keeps your text in the box. Search for text that matches nothing: the empty state offers **Show all products**.
9. **Product page.** Price, "Prices include VAT." (once), SKU, availability (a word only: In stock or Out of stock, never a number), the Details table and the description. Pick another variant: the price, SKU, availability and quantity step change at once without a reload, and the address gains `?variant=`; reload keeps the choice. The quantity box accepts steps of 0.5 for a weight variant. **Add to cart** is greyed out and the note says the cart opens soon.
10. **Photos** (upload two or three in Admin first): the primary photo is first; thumbnails below; clicking or pressing Enter on one shows that photo; with a thumbnail focused the Left, Right, Home and End keys move between them. With JavaScript off, all photos are visible one under the other.
11. **Signed-in prices.** The customer sign-in page arrives in Batch 6.2, so the signed-in view cannot be reached in a browser yet; it is covered only by the automated test (`testTierPricesAppearOnlyForASignedInCustomerWhoQualifies`). As a guest, check that no **Your prices** table ever appears on a product page.
12. **Keyboard only.** From the top of any page, Tab reaches **Skip to the main content** first and it works; then the header, search, filters, cards and pagination in a sensible order; every link, button, checkbox, radio and select shows a clear focus ring; nothing needs a mouse; no focus trap except the open filter drawer.
13. **Look.** Headings are in Source Serif 4, everything else in Inter; no font or script is fetched from another host (developer tools, Network tab: only your own host). If the organization has a `primary` branding color set, buttons, links, chips and the selected variant use it; nothing stays the default teal.
14. **Touch targets.** At 360 px every link and button you would press with a thumb is at least 48 px high (nav links, chips, pagination, filter choices, breadcrumb links, the Filters button).
