import { readFileSync, watchFile } from 'node:fs';
import { dirname, join } from 'node:path';
import { anchorFile } from '../core/anchor';
import { hasProblem, inlineLabel, lineFindings } from '../core/findings';
import { buildLineIndex, type LineIndex } from '../core/lineIndex';
import { buildMethodStats, slowestMethods } from '../core/methodIndex';
import { DEFAULT_THRESHOLDS, type Thresholds } from '../core/settings';
import { BatchStore, latestRunPerName } from '../core/store';
import { BatchFileTail } from '../core/tail';
import type { BatchRecord, ParsedLines } from '../core/types';

const POLL_INTERVAL_MS = 500;
const SLOWEST_LIMIT = 10;

const args = process.argv.slice(2);
const storageDir = args.find((argument) => !argument.startsWith('--') && !isOptionValue(argument));

if (storageDir === undefined) {
  console.error('usage: node report.cjs <storage/runtime-lens dir> [--watch] [--all] [--anchor <file>] [--n1=3] [--slow=100] [--http=1000] [--hot=10]');
  process.exit(2);
}

const thresholds = thresholdsFromArgs();
const tail = new BatchFileTail(storageDir);
const initial = tail.readInitial();
const store = new BatchStore(numericOption('--window', 1000));

store.replace(initial.batches);

const index = buildLineIndex(latestRunPerName(store.inScope()));

console.log(`${summaryLine(initial)} · in window ${store.size()}`);

const anchorTarget = optionValue('--anchor');

if (anchorTarget !== undefined) {
  printAnchoring(index, anchorTarget);
} else {
  printFlaggedLines(index);
  printSlowestMethods(store.inScope());
}

if (args.includes('--watch')) {
  watchFile(tail.currentFile(), { interval: POLL_INTERVAL_MS }, () => reportChange(tail));
}

function isOptionValue(argument: string): boolean {
  const position = args.indexOf(argument);

  return position > 0 && args[position - 1] === '--anchor';
}

function optionValue(name: string): string | undefined {
  const position = args.indexOf(name);

  return position === -1 ? undefined : args[position + 1];
}

function thresholdsFromArgs(): Thresholds {
  return {
    nPlusOneThreshold: numericOption('--n1', DEFAULT_THRESHOLDS.nPlusOneThreshold),
    slowQueryMs: numericOption('--slow', DEFAULT_THRESHOLDS.slowQueryMs),
    slowHttpMs: numericOption('--http', DEFAULT_THRESHOLDS.slowHttpMs),
    hotLinePercent: numericOption('--hot', DEFAULT_THRESHOLDS.hotLinePercent),
  };
}

function numericOption(name: string, fallback: number): number {
  const option = args.find((argument) => argument.startsWith(`${name}=`));
  const value = option === undefined ? Number.NaN : Number(option.slice(name.length + 1));

  return Number.isFinite(value) ? value : fallback;
}

function summaryLine(parsed: ParsedLines): string {
  const fromTests = parsed.batches.filter((batch) => batch.source === 'tests').length;
  const fromApp = parsed.batches.length - fromTests;

  return `Batches: ${parsed.batches.length} (app ${fromApp}, tests ${fromTests}) · malformed ${parsed.malformed} · unknown version ${parsed.unknownVersion}`;
}

function printFlaggedLines(lineIndex: LineIndex): void {
  const showAll = args.includes('--all');

  console.log('Flagged lines:');

  for (const file of [...lineIndex.keys()].sort()) {
    const lineStats = [...lineIndex.get(file)!.values()].sort((left, right) => left.line - right.line);

    for (const stats of lineStats) {
      const findings = lineFindings(stats, thresholds);
      const label = inlineLabel(findings);

      if (label !== null && (showAll || hasProblem(findings))) {
        console.log(`  ${file}:${stats.line}  ${label}`);
      }
    }
  }
}

function printSlowestMethods(batches: BatchRecord[]): void {
  console.log('Slowest methods:');

  for (const method of slowestMethods(buildMethodStats(batches), SLOWEST_LIMIT)) {
    console.log(`  ${method.name}  avg ${Math.round(method.ms)}ms (${method.measure}, ${method.count})`);
  }
}

function printAnchoring(lineIndex: LineIndex, relativeFile: string): void {
  const projectRoot = dirname(dirname(storageDir!));
  const lines = readFileSync(join(projectRoot, relativeFile), 'utf8').split('\n');
  const fileLines = lineIndex.get(relativeFile) ?? new Map();
  const anchored = anchorFile(fileLines, lines);
  const shown = new Set(anchored.lines.values());

  console.log(`Anchoring ${relativeFile}:`);

  for (const [line, stats] of [...anchored.lines].sort(([left], [right]) => left - right)) {
    console.log(`  ${stats.line} → ${line + 1}  ${inlineLabel(lineFindings(stats, thresholds)) ?? ''}`);
  }

  for (const stats of fileLines.values()) {
    if (stats.line !== 0 && !shown.has(stats)) {
      console.log(`  ${stats.line} → hidden (stale or superseded)`);
    }
  }
}

function reportChange(fileTail: BatchFileTail): void {
  const appended = fileTail.readNew();

  if (appended === 'reset') {
    console.log(`reset (${fileTail.readInitial().batches.length} batches)`);

    return;
  }

  if (appended.batches.length > 0 || appended.malformed > 0 || appended.unknownVersion > 0) {
    console.log(`+${appended.batches.length} batches (malformed ${appended.malformed})`);
  }
}
