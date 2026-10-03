# Changelog

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
