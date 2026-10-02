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
| `Support/` | Shared owners: site-restricted user lifecycle, `CommandRunner`, `CustomerArchiveContract`, `DisposableCraftProject` |
| `Fixtures/Project/run.php` | Entry point that runs the suite inside one disposable Craft project (standalone CI/Act) and its lifecycle probe |

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

- Inside the workspace: `composer test` against the DDEV project, then
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
