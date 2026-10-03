import { describe, expect, it } from 'vitest';
import { diagnosticsFor, formatMs, inlineLabel, lineFindings } from '../src/core/findings';
import { buildLineIndex, type LineStats } from '../src/core/lineIndex';
import { DEFAULT_THRESHOLDS } from '../src/core/settings';
import type { BatchRecord } from '../src/core/types';
import { batch, exception, httpCall, query, times } from './helpers';

function statsFor(batches: BatchRecord[], file = 'app/Http/Controllers/CourseController.php', line = 30): LineStats {
  const stats = [...(buildLineIndex(batches).get(file)?.values() ?? [])].find((candidate) => candidate.line === line);

  if (stats === undefined) {
    throw new Error(`no stats for ${file}:${line}`);
  }

  return stats;
}

function labelFor(batches: BatchRecord[], file?: string, line?: number): string | null {
  return inlineLabel(lineFindings(statsFor(batches, file, line), DEFAULT_THRESHOLDS));
}

describe('N+1 detection', () => {
  it('flags the same query repeated from one line at the threshold', () => {
    expect(labelFor([batch({ queries: times(3, () => query()) })])).toBe('⚠ N+1 · 3× in one request · 3ms');
  });

  it('does not flag two repeats', () => {
    expect(labelFor([batch({ queries: times(2, () => query()) })])).toBe('2 queries · 2ms');
  });

  it('names the kind of run in the label and the diagnostic', () => {
    const stats = statsFor([batch({ kind: 'job', name: 'App\\Jobs\\Sync', queries: times(4, () => query()) })]);
    const findings = lineFindings(stats, DEFAULT_THRESHOLDS);

    expect(inlineLabel(findings)).toBe('⚠ N+1 · 4× in one job · 4ms');
    expect(diagnosticsFor(findings, DEFAULT_THRESHOLDS)).toContainEqual({
      severity: 'warning',
      message: 'Possible N+1: the same query ran 4 times from this line in one job (App\\Jobs\\Sync). Eager load the relation with ->with().',
    });
  });

  it('counts repeats per run, not across runs', () => {
    expect(labelFor([batch({ queries: [query(), query()] }), batch({ queries: [query(), query()] })])).toBe('2 queries · 2ms');
  });
});

describe('labels', () => {
  it('shows a slow query with an info diagnostic', () => {
    const findings = lineFindings(statsFor([batch({ queries: [query({ ms: 312.4 })] })]), DEFAULT_THRESHOLDS);

    expect(inlineLabel(findings)).toBe('🐢 slow query · 312ms');
    expect(diagnosticsFor(findings, DEFAULT_THRESHOLDS)).toEqual([{ severity: 'info', message: 'Slow query: 312ms (threshold 100ms).' }]);
  });

  it('shows slow and failed HTTP calls', () => {
    expect(labelFor([batch({ http: [httpCall({ ms: 1500 })] })], 'app/Services/Partner.php', 12)).toBe('🌐 slow HTTP · 1500ms');
    expect(labelFor([batch({ http: [httpCall({ ms: null, status: null, failed: true })] })], 'app/Services/Partner.php', 12)).toBe('🌐 HTTP failed ×1');
  });

  it('shows exceptions first, with an error diagnostic', () => {
    const stats = statsFor([batch({ exceptions: [exception(), exception()] })], 'app/Services/Quota.php', 8);
    const findings = lineFindings(stats, DEFAULT_THRESHOLDS);

    expect(inlineLabel(findings)).toBe('✖ QuotaExceeded ×2');
    expect(diagnosticsFor(findings, DEFAULT_THRESHOLDS)[0]).toMatchObject({ severity: 'error' });
    expect(diagnosticsFor(findings, DEFAULT_THRESHOLDS)[0]!.message).toMatch(/^App\\Exceptions\\QuotaExceeded: Daily quota reached \(×2, last at \d\d:\d\d\)$/);
  });

  it('prefers the exception over an N+1 on the same line', () => {
    const same = { file: 'app/X.php', line: 5, snippet: 'run();' };

    expect(labelFor([batch({ queries: times(5, () => query(same)), exceptions: [exception(same)] })], 'app/X.php', 5)).toBe('✖ QuotaExceeded ×1');
  });

  it('shows hot lines from profile data', () => {
    const profiled = batch({
      duration_ms: 200,
      profile: { period_ms: 1, functions: [], lines: [{ file: 'app/Cpu.php', line: 9, snippet: 'heavy();', total_ms: 120 }] },
    });

    expect(labelFor([profiled], 'app/Cpu.php', 9)).toBe('🔥 120ms · 60% of request');
  });

  it('shows a plain cost label with the right plural', () => {
    expect(labelFor([batch({ queries: [query({ ms: 2.54 })] })])).toBe('1 query · 2.5ms');
  });

  it('formats milliseconds', () => {
    expect([formatMs(0.04), formatMs(9.96), formatMs(10.4), formatMs(1234.5)]).toEqual(['0', '10', '10', '1235']);
  });
});

describe('buildLineIndex', () => {
  it('keeps Blade (file-level) records under line 0', () => {
    const index = buildLineIndex([batch({ queries: [query({ file: 'resources/views/list.blade.php', line: null, snippet: null })] })]);

    expect([...index.get('resources/views/list.blade.php')!.values()][0]!.line).toBe(0);
  });

  it('keeps different code recorded at the same line number apart', () => {
    const index = buildLineIndex([
      batch({ name: 'GET /a', queries: [query({ snippet: 'old();', fp: 'old' })] }),
      batch({ name: 'GET /b', queries: [query({ snippet: 'new();', fp: 'new' })] }),
    ]);

    expect([...index.get('app/Http/Controllers/CourseController.php')!.values()].map((stats) => stats.snippet)).toEqual(['old();', 'new();']);
  });

  it('ignores records without a file', () => {
    expect(buildLineIndex([batch({ queries: [query({ file: null, line: null })] })]).size).toBe(0);
  });
});
