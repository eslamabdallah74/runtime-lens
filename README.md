# Runtime Lens

**See what your Laravel code did at runtime right next to the line that did it**: query counts, N+1s, slow queries, slow or failed HTTP calls, exceptions and method timings, in VS Code, Cursor, Antigravity or VSCodium.

```php
public function index()                         ⏱ avg 340ms per request (12) · DB 120ms · N+1 on 1 line
{
    $courses = Course::all();                   1 query · 3ms
    foreach ($courses as $course) {
        $names[] = $course->teacher->name;      ⚠ N+1 · 10× in one request · 6.5ms
    }
    DB::select('select sleep(0.3)');            🐢 slow query · 301ms
    Http::get($partnerUrl);                     🌐 slow HTTP · 1219ms
```
<img width="981" height="401" alt="image" src="https://github.com/user-attachments/assets/8057eec1-7747-49c5-8357-ec95da6d2811" />

No browser tab to dig through and no mapping SQL back to code by hand: use your app (or run your tests), then look at your code.

## Install

**1. In your Laravel project (11, 12 or 13; PHP 8.2+):**

```bash
composer config repositories.runtime-lens vcs https://github.com/eslamabdallah74/runtime-lens
composer require --dev runtime-lens/laravel:^1.0
php artisan runtime-lens:install
```

`runtime-lens:install` checks your setup. It can also turn recording on when your local `APP_ENV` isn't `local`, and recommend the editor extension to your team. It never changes anything without asking.

The first command can be dropped once the package is on Packagist.

**2. In your editor (VS Code, Cursor, Antigravity, VSCodium):**
1. Download [`runtime-lens-1.0.0.vsix`](https://github.com/eslamabdallah74/runtime-lens/releases/download/v1.0.0/runtime-lens-1.0.0.vsix). You can also find it on the [Releases page](https://github.com/eslamabdallah74/runtime-lens/releases/latest) or in the [`releases/`](releases) folder.
2. Open the Extensions view → `…` menu → **Install from VSIX…** → pick the file. From a terminal: `code --install-extension runtime-lens-1.0.0.vsix`.
3. Reload the window.

Once it's published to the VS Code Marketplace and Open VSX, you'll be able to search for **Runtime Lens** in the Extensions view instead.

That's it: no config files and nothing to start.

## What you see

| Where | What |
|---|---|
| End of a line | The most important thing that line did: `✖ Exception ×n`, `⚠ N+1`, `🐢 slow query`, `🌐 slow/failed HTTP`, `🔥 hot line`, or `n queries · ms` |
| Hover | The SQL with bindings, how often it ran, avg/max time, the latest runs that hit it, exception messages |
| Above a method | `⏱ avg ms per request` for controller actions, job and command `handle()` methods, plus DB and HTTP time inside the method and `N+1 on k lines` |
| Top of a Blade view | The queries the view triggered (lazy loading in `@foreach`) |
| Problems panel | Exceptions (error), N+1s and slow/failed HTTP calls (warning), slow queries (info) |
| Status bar | `Lens: N requests`; click to focus everything on one request |

Commands (`Ctrl+Shift+P`): **Runtime Lens: Recent Requests**, **Slowest Methods**, **Toggle Inline Annotations**, **Clear Data**, **Get Started**.

Method summaries need a PHP extension that provides document symbols, such as Intelephense. Everything else works without one.

## How you use it

1. Run your app as usual (`artisan serve`, Sail, Docker, Valet, Herd, PHP-FPM, FrankenPHP).
2. Use it from the browser, Postman or your frontend. Each request adds one line to `storage/runtime-lens/batches.jsonl`.
3. Look at your code. Labels appear within about a second.

Labels always show the **latest run** of each request, job or command. Fix an N+1, hit the endpoint again, and the warning disappears. Method timings average every recorded run. To look at one specific run, use **Recent Requests**.

**Cover the whole app in one go** by recording your feature tests:

```bash
RUNTIME_LENS_RECORD_TESTS=true php artisan test
```

Every HTTP request your tests make is recorded and tagged `[test]`. Test setup (factories, seeders, migrations) is not.

GraphQL requests are named after their operation, e.g. `POST /graphql (studentSubjects)`.

### Optional: per-method CPU timing

If the [Excimer](https://github.com/wikimedia/php-excimer) PHP extension happens to be loaded, Runtime Lens also samples where time is spent and shows `🔥` hot lines and per-method timing. There's nothing to configure, and everything else works the same without it.

## Configuration

Everything works with the defaults. Environment variables work without publishing the config (`php artisan vendor:publish --tag=runtime-lens-config`).

| Key | Env | Default | Meaning |
|---|---|---|---|
| `enabled` | `RUNTIME_LENS_ENABLED` | `true` | Record while the app runs |
| `environments` | `RUNTIME_LENS_ENVIRONMENTS` (comma-separated) | `local` | `APP_ENV` values where the app is recorded |
| `record_tests` | `RUNTIME_LENS_RECORD_TESTS` | `false` | Record during the test suite |
| `capture_bindings` | `RUNTIME_LENS_CAPTURE_BINDINGS` | `true` | Store query binding values |
| `max_queries_per_batch` | `RUNTIME_LENS_MAX_QUERIES` | `1000` | Extra queries are only counted |
| `max_file_mb` | `RUNTIME_LENS_MAX_FILE_MB` | `20` | Rotate the data file above this size |
| `ignore_paths` | — | `up`, telescope, horizon, debugbar, ignition | Requests not recorded |
| `record_commands` | `RUNTIME_LENS_RECORD_COMMANDS` | `false` | Record artisan commands |
| `ignore_commands` | — | long-running ones (`queue:work`, `serve`, …) | Commands never recorded |
| `profile` | `RUNTIME_LENS_PROFILE` | `true` | Use Excimer when it is loaded |
| `profile_period_ms` | `RUNTIME_LENS_PROFILE_PERIOD_MS` | `1` | Excimer sampling period |

The editor settings live under `runtimeLens.*`:
- `enabled` (default `true`)
- `projectRoot` (default `""`; set it when the Laravel app is in a subfolder of your workspace)
- `window` (default `1000`)
- `nPlusOneThreshold` (default `3`)
- `slowQueryMs` (default `100`)
- `slowHttpMs` (default `1000`)
- `hotLinePercent` (default `10`)
- `inline` (default `true`)

## Safety and side effects

| Area | Effect |
|---|---|
| Production | Never runs. It's a `--dev` dependency, and it refuses `APP_ENV=production` whatever the config says. |
| Octane / FrankenPHP worker mode | Detected and switched off (not supported yet), so it never records wrong data. Classic PHP-FPM, `artisan serve` and FrankenPHP classic mode are supported. |
| Speed | Measured on a Ryzen 7 PRO 4750U, PHP 8.4, MySQL: a light request goes from 14.8 to 17.6 ms (+2.8 ms), a 14-query request +4.7 ms, a 1,200-query request +34 ms (about 28 µs per query). A recorded test run showed no measurable difference. |
| Disk | Up to about 40 MB in `storage/runtime-lens/` (current file + one rotated). The folder writes its own `.gitignore`, so it is never committed. |
| Data | The local file contains SQL binding values, exception messages and source line text. It never leaves your machine. Turn bindings off with `RUNTIME_LENS_CAPTURE_BINDINGS=false`. Query strings are removed from recorded HTTP URLs. |
| Your app | Responses and error reporting are unchanged. Any failure inside the recorder is swallowed and logged at most once an hour at `debug` level, so it can't break a request or a test. |
| Other tools | Works alongside Telescope, Debugbar and Xdebug. |

## Known limits

- It only sees code that actually runs. Code paths nobody hits show nothing.
- Small local databases can hide N+1s on loops of 1–2 rows; the N+1 threshold defaults to 3 for that reason.
- Work started purely inside packages (e.g. Lighthouse `@hasMany`, Nova internals) is counted in the request but has no line in your code to show it on.
- Only Laravel's `Http` client is recorded, not raw Guzzle or cURL.

## Troubleshooting

Start with `php artisan runtime-lens:install`. It explains why recording is off, if it is.

| Symptom | Check |
|---|---|
| Status bar says `Lens: no data` | `APP_ENV` is `local` or listed in `RUNTIME_LENS_ENVIRONMENTS`; `storage/` is writable; for tests, `RUNTIME_LENS_RECORD_TESTS=true`. |
| Settings changes don't apply | You use `php artisan config:cache`; re-run it, or `config:clear`. |
| No labels on a file | The line was edited after it was recorded, or a long-running queue worker still runs older code; hit the endpoint again or restart the worker. |
| Jobs not recorded | Restart your queue workers once after installing. Jobs on the `sync` connection belong to the request that dispatched them. |
| Laravel app in a subfolder | Set `runtimeLens.projectRoot` to that folder. |

## Contributing

```bash
composer install && vendor/bin/pest                     # package tests (SQLite by default)
cd extension && npm ci && npm run build && npm test     # editor extension tests
node extension/dist/report.cjs path/to/app/storage/runtime-lens --all   # the extension's analysis, in a terminal
```

To run the package tests against MySQL instead, set `DB_CONNECTION=mysql` plus `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD`.

## Releasing (maintainers)

1. Bump `extension/package.json` `version`, and add a `CHANGELOG.md` entry.
2. Tag and push: `git tag v1.2.3 && git push --tags`. The **Release** workflow then:
   - tests and packages the extension;
   - publishes it to the VS Code Marketplace (secret `VSCE_PAT`) and Open VSX (secret `OVSX_PAT`);
   - attaches the `.vsix` to a GitHub release.
3. Packagist picks up the tag through its GitHub hook.

## License

MIT © 2026 Eslam Abdallah
