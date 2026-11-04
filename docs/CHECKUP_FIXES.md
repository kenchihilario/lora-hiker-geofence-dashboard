# Checkup fixes

Scope: the five FAIL items in `docs/CHECKUP_AUDIT.md` and the two README omissions. No LIVE mode/database was used. Before changes, the existing PHP, Node, API, and PowerShell tests from the audit all passed; the baseline API and site smoke tests also passed.

| Task | Status | Evidence | Test result before / after |
|---|---|---|---|
| #15 Clear demo data safely | DONE | `src/Services/ApiService.php:21-38` clears flagged rows and reports legacy `positions`/`alerts` kept; `public/api/index.php:47-51` backs up first and returns the counts; `public/assets/js/site-settings.js:15` shows the count. | Before: no test of preservation. After: `php tests/clear_demo_scratch_test.php` PASS on a dropped scratch DB; API smoke confirmation guard returns HTTP 400 unless exact `CLEAR`. The positive clear route was not invoked against the configured demo DB to avoid clearing its existing demo data. |
| #19 Record-aware dashboard banner | DONE | `public/assets/js/source-flags.js:6-14` classifies all three flags; `public/assets/js/dashboard.js:54-59` adds record flags to the banner and displays the database row count; `public/index.php:55` supplies the check element. | Before: audit found banner mode-only and missing dashboard check. After: `node tests/dashboard_packet_log_contract_test.js` PASS, including all three source flags and red LIVE error styling. No LIVE request was made. |
| #20 Shared research eligibility | DONE | `src/Services/ApiService.php:160-166` uses `research_flags_eligible_sql()` for packet counts and exclusions, matching the event/command filter construction. | Before: research test passed while packet SQL had a duplicate predicate. After: `php tests/research_summary_test.php` PASS, including events/commands linked to a packet whose JSON `test_mode` flag is set despite unflagged denormalized rows. |
| #33 Polygon fixture cleanup | DONE | Dedicated test DB now has one active PUP zone named `DEMO SAMPLE DATA - PUP illustrative corridor`; `tests/api_smoke.php:48-49` and `tests/site_safe_zone_smoke.ps1:29-31,65-66` assert polygon history counts return to their initial values. | Before: read-only query found 27 PUP zones, including 26 `DEMO TEST polygon` rows. After cleanup: query found one active sample and zero `DEMO TEST polygon` rows. API smoke PASS and restores PUP history count to 1; site smoke PASS and restores Balagbag count to 0. |
| #34 Restore tracked placeholder | DONE | Restored empty `tools/New Text Document.txt` from commit `1879ec4` because the deletion was already committed in HEAD; restoration commit `9c2d370`. | Before: path was deleted in HEAD. After: tracked empty file exists; restoration commit reports one zero-byte file added. |
| README shortcuts and API/bridge notes | DONE | `README.md:28-30` documents Launch/Restart/Stop shortcuts; `README.md:83-105` restores the prior endpoint list and logical reception/route/timing notes, checked against `git show ab6974c^:README.md`. | Before: shortcuts and endpoint catalog were absent. After: README content manually checked against history; no dedicated README test. The historical README specified no numeric receive-window threshold, so none is claimed. |

## Test run summary

- **Before:** all then-existing tests passed: PHP unit/scratch tests, API smoke, Node tests, and PowerShell site smoke.
- **After:** all passed: 8 PHP test scripts including API smoke, 3 Node tests, the PowerShell site smoke, PHP lint (27 discovered PHP files plus local `config.php`), and `node --check` on 9 JavaScript files.
- The database backup was created before the polygon cleanup at `backups/20261104_031703/hiker_dashboard_test_codex.sql`. Scratch databases were dropped. The configured test DB remains in DEMO mode.
- The required baseline API smoke ran before the first task backup and wrote its normal test fixtures to the dedicated test DB; the backup captured that post-baseline state before the deliberate polygon cleanup. The post-fix smoke restored polygon history to its starting count.

## Could not verify / commit note

- The clear-data operation's shared deletion helper was tested on a scratch DB, but the successful HTTP action and its backup display were not run against the configured demo DB because that would clear its existing demo records. The confirmation-rejection path was exercised.
- No LIVE request or LIVE database write/test was performed.
- Commits are `9c2d370` (#34), `7c109bb` (#15 plus the initial #19 implementation and related assertions), `1b07210` (#20), `2f194d7` (#33), `1344c56` (#19 follow-up), and the README/report commit. The initial #15/#19 changes landed together because they were staged together; history was not rewritten.
