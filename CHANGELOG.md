# Changelog

## 1.0.1 — 2026-10-03

### Fixed

- **The recorder can no longer throw into your app:** this used to be possible when its failure marker couldn't be checked, for example under `open_basedir`. Failures are now logged before the marker is written.
- **Odd binding values no longer lose the whole batch:** this covered resource bindings and `NAN`/`INF` values.
- **Your project's git status stays clean:** `storage/runtime-lens/.gitignore` now ignores itself, and existing installs are corrected automatically.
- **`runtime-lens:install` improvements:**
  - warns when Octane is installed
  - points to `RUNTIME_LENS_RECORD_TESTS` instead of offering to allow `APP_ENV=testing`
  - leaves unusual `.vscode/extensions.json` files alone
- **Editor extension:**
  - **"Latest run" is now per route:** `/courses/17` and `/courses/18` count as one endpoint, using the new `group` field.
  - **No crash on an unreadable data file.** The status bar explains the problem instead.
  - **Laravel app in a subfolder:** a single one is picked up automatically, and a missing one is explained in the status bar.
  - **Memory:** the diagnostics text cache no longer keeps files that have no data.
- **Release workflow:** re-runs are safe and missing publishing tokens show a warning.

## 1.0.0 — 2026-10-03

First public release.

### Package

- Records every request, queued job and (optionally) artisan command into `storage/runtime-lens/batches.jsonl`. It captures:
  - queries, with the file, line and code snippet that ran them
  - outgoing `Http` client calls
  - reported exceptions
  - the controller, job or command method that handled the run
- Records the feature test suite with `RUNTIME_LENS_RECORD_TESTS=true`.
- Optional per-method and per-line timing when the Excimer extension is loaded.
- `php artisan runtime-lens:install`: checks the setup and offers to enable a custom `APP_ENV` and recommend the editor extension.
- Never runs in production; switches off under Octane / FrankenPHP worker mode.

### Editor extension

- Inline labels for N+1s, slow queries, slow or failed HTTP calls, exceptions and hot lines.
- Hovers with SQL and bindings, method summaries above methods, Problems panel entries.
- Recent Requests, Slowest Methods, Toggle Inline Annotations, Clear Data, and a Get Started walkthrough.
- Labels follow edited code and always reflect the latest run of each request.
