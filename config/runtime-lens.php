<?php

return [

    'enabled' => env('RUNTIME_LENS_ENABLED', true),

    'environments' => array_map('trim', explode(',', (string) env('RUNTIME_LENS_ENVIRONMENTS', 'local'))),

    'record_tests' => env('RUNTIME_LENS_RECORD_TESTS', false),

    'capture_bindings' => env('RUNTIME_LENS_CAPTURE_BINDINGS', true),

    'max_queries_per_batch' => (int) env('RUNTIME_LENS_MAX_QUERIES', 1000),

    'max_file_mb' => (int) env('RUNTIME_LENS_MAX_FILE_MB', 20),

    'ignore_paths' => ['up', 'telescope*', 'horizon*', '_debugbar*', '_ignition*'],

    'record_commands' => env('RUNTIME_LENS_RECORD_COMMANDS', false),

    'ignore_commands' => ['queue:work', 'queue:listen', 'horizon', 'horizon:work', 'schedule:work', 'serve', 'tinker', 'test'],

    'profile' => env('RUNTIME_LENS_PROFILE', true),

    'profile_period_ms' => (int) env('RUNTIME_LENS_PROFILE_PERIOD_MS', 1),

];
