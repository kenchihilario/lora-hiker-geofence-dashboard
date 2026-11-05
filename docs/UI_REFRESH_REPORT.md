# Dashboard UI refresh report

Branch: `codex/ui-refresh` (no commit created).

## Changed for this refresh

- `public/index.php`: reorganized the storm controls into a danger panel, merged the LIVE API failure copy into one visible error area, added map and hiker empty-state guidance, and added the packet-log loading skeleton. Existing control IDs and data hooks remain in place.
- `public/safe-zone.php`: split the editor controls into polygon and GPX cards, added an empty-map hint, and retained the existing input IDs and form behavior.
- `public/settings.php`: updated the stylesheet cache version and added a presentation-only settings layout hook.
- `public/assets/css/dashboard.css`: added the dark field-console palette, typography and control sizing, responsive dashboard/editor layouts, empty/loading states, focus indicators, and reduced-motion handling. No remote fonts, scripts, styles, or map assets are referenced.
- `public/assets/js/dashboard.js`: updates empty-state copy and presentation-only alert glyphs; disables the existing storm controls while the serial bridge is not reporting.
- `public/assets/js/safe-zone.js`: toggles the editor’s empty-map hint and loading indicator while retaining the existing editing and save behavior.

The design reference was the supplied home-screen redesign PDF and screenshots. The existing dashboard business logic, API responses, SQL, migrations, site configuration, and bridge behavior were not intentionally changed for this refresh.

## Follow-up from screenshot review

The supplied screenshots showed the settings cards compressed into a narrow column on desktop and supporting text too small. Settings now uses a two-column card layout on wider screens, returns to one column below 900 px, and increases form/help text sizing. Section and field order remains the same. CSS cache version is `20261105-4` on Settings.

The dashboard screenshots also showed two large empty areas created by putting the storm panel in its own grid row. Recent alerts and storm controls are now grouped in a single right-hand stack beside the map; the hiker and packet panels follow beneath it. Their existing IDs, handlers, and button state are unchanged. The dashboard and safe-zone pages also use stylesheet cache version `20261105-4`.

## Verification

- PHP syntax checks passed for `public/index.php`, `public/safe-zone.php`, and `public/settings.php`.
- Node syntax checks passed for `public/assets/js/dashboard.js`, `public/assets/js/safe-zone.js`, `tests/live_empty_dashboard_test.js`, and `tests/dashboard_map_labels_test.js`.
- `git diff --check` passed.
- Static scan of the refreshed CSS and page assets found no external URLs, `@import`, remote `url(...)`, or tile-layer references.
- The scratch suite was attempted before and after the changes. Both attempts stopped before tests ran because MariaDB refused the connection (`SQLSTATE[HY000] [2002]`). Suite cleanup also could not connect; no scratch database was created by either attempt.
- `tools/live_check.php` was attempted before and after as a read-only check; both attempts stopped at the same MariaDB connection refusal. No LIVE database write was performed.
- Browser rendering at 1280, 1440, and 1920 widths could not be verified in this session because the local database service was unavailable. Responsive rules are present, but a visual browser review remains outstanding.

## Follow-up

Start the local MariaDB service, then rerun `php tests/run_scratch_suite.php` and the read-only `php tools/live_check.php`. Review the dashboard, safe-zone editor, and settings page at 1280, 1440, and 1920 pixel widths after the pages load successfully.
