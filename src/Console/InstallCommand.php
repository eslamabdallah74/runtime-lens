<?php

namespace RuntimeLens\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use RuntimeLens\Activation;
use Throwable;

final class InstallCommand extends Command
{
    private const EXTENSION_ID = 'runtime-lens.runtime-lens';

    private const ENVIRONMENTS_VARIABLE = 'RUNTIME_LENS_ENVIRONMENTS';

    protected $signature = 'runtime-lens:install';

    protected $description = 'Check that Runtime Lens can record in this project and help set it up';

    public function handle(Activation $activation, Filesystem $files): int
    {
        $this->components->info('Runtime Lens');

        $this->reportRecording($activation);
        $this->offerEnvironmentSetup($activation, $files);
        $this->reportConfigCache();
        $this->reportStorage($files);
        $this->reportProfiler();
        $this->offerExtensionRecommendation($files);
        $this->printNextSteps();

        return self::SUCCESS;
    }

    private function reportRecording(Activation $activation): void
    {
        $offReason = $activation->offReason();

        $this->components->twoColumnDetail('Recording', $offReason === null ? '<fg=green>on</>' : "<fg=yellow>off</> · {$offReason}");
    }

    private function offerEnvironmentSetup(Activation $activation, Filesystem $files): void
    {
        $environment = (string) $this->laravel->environment();
        $allowed = $activation->allowedEnvironments();

        if ($environment === 'production' || in_array($environment, $allowed, true)) {
            return;
        }

        $value = implode(',', [...$allowed, $environment]);

        if (! $this->confirmed("Record this project while APP_ENV is \"{$environment}\"? This adds ".self::ENVIRONMENTS_VARIABLE."={$value} to .env")) {
            $this->components->twoColumnDetail('.env', "<fg=yellow>to record APP_ENV={$environment}</> · add ".self::ENVIRONMENTS_VARIABLE."={$value}");

            return;
        }

        $this->writeEnvironmentVariable($files, $this->laravel->environmentFilePath(), $value);
        $this->components->twoColumnDetail('.env', '<fg=green>'.self::ENVIRONMENTS_VARIABLE."={$value}</>");
    }

    private function writeEnvironmentVariable(Filesystem $files, string $environmentFile, string $value): void
    {
        $line = self::ENVIRONMENTS_VARIABLE.'='.$value;
        $contents = $files->exists($environmentFile) ? $files->get($environmentFile) : '';
        $pattern = '/^'.self::ENVIRONMENTS_VARIABLE.'=.*$/m';

        $updated = preg_match($pattern, $contents) === 1
            ? preg_replace($pattern, $line, $contents)
            : rtrim($contents, "\n").($contents === '' ? '' : "\n\n").$line."\n";

        $files->put($environmentFile, $updated);
    }

    private function reportConfigCache(): void
    {
        if (! $this->laravel->configurationIsCached()) {
            return;
        }

        $this->components->twoColumnDetail('Config cache', '<fg=yellow>cached</> · run php artisan config:clear (or config:cache again) after changing Runtime Lens settings');
    }

    private function reportStorage(Filesystem $files): void
    {
        $directory = $this->laravel->storagePath('runtime-lens');

        $this->components->twoColumnDetail('Storage', $this->storageIsWritable($files, $directory)
            ? "<fg=green>writable</> · {$directory}"
            : "<fg=red>not writable</> · {$directory}");
    }

    private function storageIsWritable(Filesystem $files, string $directory): bool
    {
        try {
            $files->ensureDirectoryExists($directory, 0775);
            $probe = $directory.DIRECTORY_SEPARATOR.'.install-probe';
            $files->put($probe, 'ok');
            $files->delete($probe);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function reportProfiler(): void
    {
        $this->components->twoColumnDetail('Excimer profiler', extension_loaded('excimer')
            ? '<fg=green>loaded</> · per-method timings on'
            : 'not loaded · optional, everything else works');
    }

    private function offerExtensionRecommendation(Filesystem $files): void
    {
        $file = $this->laravel->basePath('.vscode/extensions.json');
        $recommendations = $this->readRecommendations($files, $file);

        if ($recommendations === null) {
            $this->components->twoColumnDetail('.vscode/extensions.json', '<fg=yellow>not plain JSON</> · add "'.self::EXTENSION_ID.'" to recommendations by hand');

            return;
        }

        if (in_array(self::EXTENSION_ID, $recommendations['recommendations'] ?? [], true)) {
            $this->components->twoColumnDetail('.vscode/extensions.json', '<fg=green>recommends Runtime Lens</>');

            return;
        }

        if (! $this->confirmed('Recommend the Runtime Lens VS Code extension to your team in .vscode/extensions.json?')) {
            return;
        }

        $recommendations['recommendations'] = [...($recommendations['recommendations'] ?? []), self::EXTENSION_ID];

        $files->ensureDirectoryExists(dirname($file));
        $files->put($file, json_encode($recommendations, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        $this->components->twoColumnDetail('.vscode/extensions.json', '<fg=green>recommends Runtime Lens</>');
    }

    private function confirmed(string $question): bool
    {
        return $this->input->isInteractive() && $this->confirm($question, false);
    }

    private function readRecommendations(Filesystem $files, string $file): ?array
    {
        if (! $files->exists($file)) {
            return [];
        }

        $decoded = json_decode($files->get($file), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function printNextSteps(): void
    {
        $this->newLine();
        $this->line('  Next steps:');
        $this->line('  1. Install the editor extension: search "Runtime Lens" in VS Code, Cursor or Antigravity, or run');
        $this->line('     <fg=cyan>code --install-extension '.self::EXTENSION_ID.'</>');
        $this->line('  2. Use your app. Labels appear next to the code that ran within about a second.');
        $this->line('  3. Record the whole test suite: <fg=cyan>RUNTIME_LENS_RECORD_TESTS=true php artisan test</>');
        $this->line('  4. Restart running queue workers once.');
        $this->newLine();
    }
}
