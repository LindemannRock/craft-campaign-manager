# Campaign Manager Tests

Integration tests run against a real Craft install with Formie, Logging
Library, SMS Manager, the Formie rating field, and Campaign Manager installed.
There is no transactional rollback: every fixture is recorded by exact identity
when it is created and removed by that identity in teardown (see
`TestCase.php` and `Support/SiteRestrictedUserTestCase.php`).

## Layout

| Path | Purpose |
|---|---|
| `Integration/` | One behavioral family per class |
| `Stubs/` | Recording and failing service/controller/request doubles, plus the PHPStan-only `BaseTestingBootstrap.php` declaration |
| `Support/` | Shared owners: site-restricted user lifecycle, invitation delivery capture (`InvitationDeliveryTestCase`), a second database lock holder (`SeparateConnectionMutex`), `CommandRunner`, `CustomerArchiveContract`, `DisposableCraftProject` |
| `Fixtures/Project/run.php` | Entry point that runs the suite inside one disposable Craft project (standalone CI/Act) and its lifecycle probe |
| `Fixtures/Templates/` | Site templates the invitation delivery tests pin for their duration, so an invitation email can be built in any project |

## Invitation delivery tests

Tests extending `Support/InvitationDeliveryTestCase.php` run batches through
the real send services. SMS are captured where they are handed to SMS Manager
and emails at the application mailer, so nothing is sent. These tests need
the application mutex to be backed by the database (Craft's default); with
any other driver they fail instead of being skipped, because overlapping
workers could not be told apart.

Craft compiles every template it renders into the project's
`storage/runtime/compiled_templates` directory. The email layout these tests
render is a fixture of this package, so for the duration of a test the site
Twig environment keeps its compiled templates in memory instead; the cache
setting is put back in teardown, which also fails the test if the fixture was
compiled to disk anyway. Only the fixture's own compiled file, located by its
exact Twig cache key, is ever removed; no other compiled template is touched.

## MPP transport isolation

`TransportIsolation` is installed before Craft application bootstrap in both the
suite and disposable project. It replaces Guzzle transport with synthetic
responses, refuses other HTTP delivery, rejects mailer transport and queue-worker
execution, and reports intercepted versus real requests. Disposable PHP children
also disable socket/cURL/mail functions and URL streams. No test starts a worker;
batches are invoked directly and only their owned queue payloads are inspected.

`MppCampaignTestCase` runs the real SMS Manager result/guard path with a synthetic
account and endpoint. It requires a disposable database, owns exact guard scope
keys, restores logging/config/component swaps and removes its rows. Campaign
page tests own their session, template cache and published-asset directories;
release-history rows are removed by their owned actor and IDs. The console page
fixture temporarily hides SMS Manager’s unrelated global sidebar entry (which
requires a web-user component), restoring the property immediately; campaign
controllers, templates, notice rendering and permission checks run unchanged. Fixture lifecycle
probes check project/database removal on success, failure, shutdown and SIGTERM.

## Commands

| Command | What it proves |
|---|---|
| `composer ci` | PHPStan and ECS over `src/` and `tests/` (read-only; also what pre-commit runs through DDEV) |
| `composer ci:full` | `composer ci` plus the PHPUnit suite against the current Craft project |
| `composer quality-gate` | The complete package authority used by GitHub Actions and Act: package validation, platform check, Composer security audit, `composer ci`, PHPUnit plus the disposable-runner lifecycle probe, the customer archive boundary, and the CI workflow contract |
| `scripts/act-quality-gates` | Runs the CI `quality-gates` job locally with Act, after checking that the job delegates to `composer quality-gate` |

Inside the workspace run them through DDEV, for example
`ddev exec "cd plugins/campaign-manager && composer quality-gate"`.

PHP 8.2–8.5 runtime compatibility is a separate release checkpoint and is not
part of the quality gate, CI, Act, or pre-commit.

## Disposable project runner

`scripts/run-tests` decides how PHPUnit runs:

- Inside the workspace: `composer test` uses the shared vendor tree in an isolated disposable project and database, then
  `tests/Fixtures/Project/run.php --lifecycle-probe`, which proves that a
  disposable project and database are removed on ordinary failure, on process
  shutdown, and on `SIGTERM`.
- Standalone (CI/Act): `tests/Fixtures/Project/run.php` creates one database
  `cm_qg_<run id>` and one project `campaign-manager-fixture-<run id>` under
  the system temp directory, installs Craft and the plugins, runs the suite,
  and removes both; then the lifecycle probe runs.

Environment variables read by the runner:

| Variable | Default | Meaning |
|---|---|---|
| `CAMPAIGN_MANAGER_FIXTURE_SOURCE_VENDOR_ROOT` | set by `scripts/run-tests` | Vendor tree the disposable project links to |
| `CAMPAIGN_MANAGER_FIXTURE_DB_HOST` / `_PORT` / `_USER` / `_PASSWORD` | `db` / `3306` / `root` / `root` | MySQL admin access used to create and drop the run-owned database |
| `CAMPAIGN_MANAGER_TEST_PROJECT_ROOT` | unset | Set by the runner so `tests/bootstrap.php` boots the disposable project instead of the workspace |

## Archive boundary

`Support/CustomerArchiveContract.php` is the single definition of what a
customer archive must and must not contain. `scripts/check-customer-archive.php`
runs it as the `customer-archive` quality-gate constituent;
`Integration/CustomerArchiveBoundaryTest.php` asserts the same contract and its
cleanup from PHPUnit. Both build the real Git and Composer archives in an owned
temporary directory and inspect the member lists.
