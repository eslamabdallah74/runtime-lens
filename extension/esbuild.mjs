import { existsSync } from 'node:fs';
import { build } from 'esbuild';

const shared = { bundle: true, platform: 'node', target: 'node18', format: 'cjs', logLevel: 'warning' };

const entries = [{ entryPoints: ['src/cli/report.ts'], outfile: 'dist/report.cjs' }];

if (existsSync('src/extension.ts')) {
  entries.push({ entryPoints: ['src/extension.ts'], outfile: 'dist/extension.js', external: ['vscode'] });
}

await Promise.all(entries.map((entry) => build({ ...shared, ...entry })));
