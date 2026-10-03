import { describe, expect, it } from 'vitest';
import { buildLineIndex } from '../src/core/lineIndex';
import { buildMethodStats, ioForRange, slowestMethods } from '../src/core/methodIndex';
import { DEFAULT_THRESHOLDS } from '../src/core/settings';
import { batch, httpCall, query, times } from './helpers';

const controller = 'app/Http/Controllers/CourseController.php';
const entry = { file: controller, line: 20, function: 'App\\Http\\Controllers\\CourseController::index' };

describe('buildMethodStats', () => {
  it('averages entry point durations across runs', () => {
    const stats = buildMethodStats([batch({ entry, duration_ms: 100 }), batch({ entry, duration_ms: 300 })]);

    expect(stats.get(`${controller}|${entry.function}`)?.entry).toEqual({ count: 2, avgMs: 200, maxMs: 300 });
  });

  it('averages profile timings and their share of the run', () => {
    const profiled = batch({
      duration_ms: 200,
      profile: { period_ms: 1, lines: [], functions: [{ file: 'app/Cpu.php', function: 'App\\Cpu::work', total_ms: 100, self_ms: 40 }] },
    });

    expect(buildMethodStats([profiled]).get('app/Cpu.php|App\\Cpu::work')?.profile).toEqual({ count: 1, avgTotalMs: 100, avgSelfMs: 40, avgPercent: 50 });
  });

  it('keeps different route closures in one file apart', () => {
    const closure = (line: number) => ({ file: 'routes/web.php', line, function: '{closure}' });
    const stats = buildMethodStats([batch({ entry: closure(5) }), batch({ entry: closure(9) })]);

    expect(stats.size).toBe(2);
  });
});

describe('slowestMethods', () => {
  it('prefers profile timings, then entry timings, slowest first', () => {
    const stats = buildMethodStats([
      batch({ entry, duration_ms: 50 }),
      batch({
        entry: { file: 'app/Jobs/Sync.php', line: 10, function: 'App\\Jobs\\Sync::handle' },
        duration_ms: 400,
      }),
      batch({ duration_ms: 1000, profile: { period_ms: 1, lines: [], functions: [{ file: 'app/Cpu.php', function: 'App\\Cpu::work', total_ms: 900, self_ms: 900 }] } }),
    ]);

    expect(slowestMethods(stats, 10).map((method) => [method.name, method.measure])).toEqual([
      ['App\\Cpu::work', 'profile'],
      ['App\\Jobs\\Sync::handle', 'entry'],
      ['App\\Http\\Controllers\\CourseController::index', 'entry'],
    ]);
  });
});

describe('ioForRange', () => {
  it('sums DB and HTTP time and counts N+1 lines inside the range only', () => {
    const index = buildLineIndex([
      batch({
        queries: [...times(3, () => query({ ms: 2 })), query({ line: 40, snippet: 'outside();', fp: 'outside', ms: 50 })],
        http: [httpCall({ file: controller, line: 31, snippet: 'Http::get();', ms: 80 })],
      }),
    ]);
    const byLine = new Map([...index.get(controller)!.values()].map((stats) => [stats.line, stats]));

    expect(ioForRange(byLine, 25, 35, DEFAULT_THRESHOLDS)).toEqual({ dbMs: 6, httpMs: 80, nPlusOneLines: 1 });
  });
});
