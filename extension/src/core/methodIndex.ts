import { lineFindings } from './findings';
import type { LinesByNumber } from './lineIndex';
import type { Thresholds } from './settings';
import { FILE_LEVEL, type BatchRecord } from './types';

export interface MethodStats {
  file: string;
  line: number | null;
  name: string;
  entry?: { count: number; avgMs: number; maxMs: number };
  profile?: { count: number; avgTotalMs: number; avgSelfMs: number; avgPercent: number };
}

export interface MethodIo {
  dbMs: number;
  httpMs: number;
  nPlusOneLines: number;
}

export interface SlowMethod {
  name: string;
  file: string;
  line: number | null;
  ms: number;
  count: number;
  measure: 'profile' | 'entry';
}

interface Totals {
  count: number;
  sum: number;
  max: number;
  selfSum: number;
  percentSum: number;
}

export function buildMethodStats(batches: BatchRecord[]): Map<string, MethodStats> {
  const methods = new Map<string, MethodStats>();
  const entryTotals = new Map<string, Totals>();
  const profileTotals = new Map<string, Totals>();

  for (const batch of batches) {
    if (batch.entry !== null) {
      const key = methodKey(batch.entry.file, batch.entry.function, batch.entry.line);
      methodAt(methods, key, batch.entry.file, batch.entry.line, batch.entry.function);
      addTotals(entryTotals, key, batch.duration_ms, 0, 0);
    }

    for (const fn of batch.profile?.functions ?? []) {
      const key = methodKey(fn.file, fn.function, null);
      const percent = batch.duration_ms > 0 ? (fn.total_ms / batch.duration_ms) * 100 : 0;
      methodAt(methods, key, fn.file, null, fn.function);
      addTotals(profileTotals, key, fn.total_ms, fn.self_ms, percent);
    }
  }

  for (const [key, totals] of entryTotals) {
    methods.get(key)!.entry = { count: totals.count, avgMs: totals.sum / totals.count, maxMs: totals.max };
  }

  for (const [key, totals] of profileTotals) {
    methods.get(key)!.profile = {
      count: totals.count,
      avgTotalMs: totals.sum / totals.count,
      avgSelfMs: totals.selfSum / totals.count,
      avgPercent: totals.percentSum / totals.count,
    };
  }

  return methods;
}

export function ioForRange(fileLines: LinesByNumber, startLine: number, endLine: number, thresholds: Thresholds): MethodIo {
  const io: MethodIo = { dbMs: 0, httpMs: 0, nPlusOneLines: 0 };

  for (const [line, stats] of fileLines) {
    if (line === FILE_LEVEL || line < startLine || line > endLine) {
      continue;
    }

    const findings = lineFindings(stats, thresholds);
    io.dbMs += findings.normal?.ms ?? 0;
    io.httpMs += findings.httpMsPerBatch;
    io.nPlusOneLines += findings.nPlusOne ? 1 : 0;
  }

  return io;
}

export function slowestMethods(methods: Map<string, MethodStats>, limit: number): SlowMethod[] {
  const ranked: SlowMethod[] = [];

  for (const method of methods.values()) {
    if (method.profile) {
      ranked.push({ name: method.name, file: method.file, line: method.line, ms: method.profile.avgTotalMs, count: method.profile.count, measure: 'profile' });
    } else if (method.entry) {
      ranked.push({ name: method.name, file: method.file, line: method.line, ms: method.entry.avgMs, count: method.entry.count, measure: 'entry' });
    }
  }

  return ranked.sort((left, right) => right.ms - left.ms).slice(0, limit);
}

function methodKey(file: string, name: string, line: number | null): string {
  return name === '{closure}' && line !== null ? `${file}|${name}@${line}` : `${file}|${name}`;
}

function methodAt(methods: Map<string, MethodStats>, key: string, file: string, line: number | null, name: string): MethodStats {
  const existing = methods.get(key);

  if (existing !== undefined) {
    existing.line ??= line;

    return existing;
  }

  const created: MethodStats = { file, line, name };
  methods.set(key, created);

  return created;
}

function addTotals(totals: Map<string, Totals>, key: string, value: number, selfValue: number, percent: number): void {
  const current = totals.get(key) ?? { count: 0, sum: 0, max: 0, selfSum: 0, percentSum: 0 };

  current.count++;
  current.sum += value;
  current.max = Math.max(current.max, value);
  current.selfSum += selfValue;
  current.percentSum += percent;
  totals.set(key, current);
}
