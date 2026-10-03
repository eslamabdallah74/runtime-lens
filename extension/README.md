# Runtime Lens for Laravel

See what your Laravel code did at runtime **right next to the line that did it**: query counts, N+1s, slow queries, slow or failed HTTP calls, exceptions and method timings.

```php
public function index()                         ⏱ avg 340ms per request (12) · DB 120ms · N+1 on 1 line
{
    $courses = Course::all();                   1 query · 3ms
    foreach ($courses as $course) {
        $names[] = $course->teacher->name;      ⚠ N+1 · 10× in one request · 6.5ms
    }
    Http::get($partnerUrl);                     🌐 slow HTTP · 1219ms
```

<img width="981" height="401" alt="Runtime Lens labels in VS Code: an N+1 warning and per-line query counts" src="https://github.com/user-attachments/assets/8057eec1-7747-49c5-8357-ec95da6d2811" />

## Setup

This extension shows data recorded by a small dev-only Composer package. In your Laravel project (Laravel 11, 12 or 13):

```bash
composer require --dev runtime-lens/laravel
php artisan runtime-lens:install
```

Then use your app, or record your whole test suite with `RUNTIME_LENS_RECORD_TESTS=true php artisan test`. Labels appear within about a second.

The **Runtime Lens: Get Started** walkthrough guides you through it.

## Features

- **Inline labels:** the most important thing each line did, such as an N+1, a slow query, a slow or failed HTTP call, an exception or a hot line.
- **Hovers:** the SQL with its bindings, run counts, average and maximum time, and the latest runs that hit the line.
- **Method summaries:** time per request for controller actions and job or command handlers, plus DB and HTTP time inside each method.
- **Problems panel:** N+1s, slow calls and exceptions alongside your other warnings.
- **Recent Requests:** focus every label on one request.
- **Slowest Methods:** methods ranked by time per request.
- Labels follow your edits and always show the **latest run**, so a fixed N+1 disappears after you re-run the request.
- Works with Docker, Sail, Remote-SSH and Dev Containers. The data is read from your project folder and never leaves your machine.

Method summaries need a PHP extension that provides document symbols, such as Intelephense.

## Settings

| Setting | Default | Meaning |
|---|---|---|
| `runtimeLens.enabled` | `true` | Show Runtime Lens data |
| `runtimeLens.projectRoot` | `""` | The Laravel folder, if it's not the workspace root |
| `runtimeLens.window` | `1000` | How many recent runs to keep |
| `runtimeLens.nPlusOneThreshold` | `3` | Repeats from one line that count as an N+1 |
| `runtimeLens.slowQueryMs` | `100` | Slow query threshold |
| `runtimeLens.slowHttpMs` | `1000` | Slow HTTP call threshold |
| `runtimeLens.hotLinePercent` | `10` | Hot-line threshold (with the Excimer profiler) |
| `runtimeLens.inline` | `true` | Show labels at the end of lines |

Full documentation, configuration and source: [github.com/eslamabdallah74/runtime-lens](https://github.com/eslamabdallah74/runtime-lens)

MIT licensed.
