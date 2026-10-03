import type { LineStats } from './lineIndex';
import type { Thresholds } from './settings';
import type { BatchKind } from './types';

export interface ExceptionFinding {
  className: string;
  count: number;
  message: string;
  lastSeen: string;
}

export interface NPlusOneFinding {
  count: number;
  ms: number;
  batchName: string;
  kind: BatchKind;
}

export interface SlowHttpFinding {
  kind: 'slow' | 'failed';
  maxMs: number;
  failures: number;
  method: string;
  url: string;
}

export interface LineFindings {
  exception?: ExceptionFinding;
  nPlusOne?: NPlusOneFinding;
  slowQuery?: { maxMs: number };
  slowHttp?: SlowHttpFinding;
  hot?: { avgMs: number; percent: number };
  normal: { queries: number; ms: number } | null;
  httpMsPerBatch: number;
}

export interface Diagnostic {
  severity: 'error' | 'warning' | 'info';
  message: string;
}

export function lineFindings(stats: LineStats, thresholds: Thresholds): LineFindings {
  return {
    exception: exceptionFinding(stats),
    nPlusOne: nPlusOneFinding(stats, thresholds),
    slowQuery: slowQueryFinding(stats, thresholds),
    slowHttp: slowHttpFinding(stats, thresholds),
    hot: hotFinding(stats, thresholds),
    normal: normalFinding(stats),
    httpMsPerBatch: httpMsPerBatch(stats),
  };
}

export function inlineLabel(findings: LineFindings): string | null {
  if (findings.exception) {
    return `✖ ${shortClassName(findings.exception.className)} ×${findings.exception.count}`;
  }

  if (findings.nPlusOne) {
    return `⚠ N+1 · ${findings.nPlusOne.count}× in one ${findings.nPlusOne.kind} · ${formatMs(findings.nPlusOne.ms)}ms`;
  }

  if (findings.slowQuery) {
    return `🐢 slow query · ${formatMs(findings.slowQuery.maxMs)}ms`;
  }

  if (findings.slowHttp) {
    return findings.slowHttp.kind === 'slow'
      ? `🌐 slow HTTP · ${formatMs(findings.slowHttp.maxMs)}ms`
      : `🌐 HTTP failed ×${findings.slowHttp.failures}`;
  }

  if (findings.hot) {
    return `🔥 ${formatMs(findings.hot.avgMs)}ms · ${Math.round(findings.hot.percent)}% of request`;
  }

  if (findings.normal) {
    const queries = Math.max(1, Math.round(findings.normal.queries));

    return `${queries} ${queries === 1 ? 'query' : 'queries'} · ${formatMs(findings.normal.ms)}ms`;
  }

  return null;
}

export function diagnosticsFor(findings: LineFindings, thresholds: Thresholds): Diagnostic[] {
  const diagnostics: Diagnostic[] = [];

  if (findings.exception) {
    const { className, message, count, lastSeen } = findings.exception;
    diagnostics.push({ severity: 'error', message: `${className}: ${message} (×${count}, last at ${clockTime(lastSeen)})` });
  }

  if (findings.nPlusOne) {
    diagnostics.push({
      severity: 'warning',
      message: `Possible N+1: the same query ran ${findings.nPlusOne.count} times from this line in one ${findings.nPlusOne.kind} (${findings.nPlusOne.batchName}). Eager load the relation with ->with().`,
    });
  }

  if (findings.slowHttp) {
    const { kind, method, url, maxMs, failures } = findings.slowHttp;
    diagnostics.push({
      severity: 'warning',
      message: kind === 'slow'
        ? `Slow HTTP call: ${method} ${url} took ${formatMs(maxMs)}ms (threshold ${thresholds.slowHttpMs}ms).`
        : `HTTP call failed: ${method} ${url} (×${failures}).`,
    });
  }

  if (findings.slowQuery) {
    diagnostics.push({ severity: 'info', message: `Slow query: ${formatMs(findings.slowQuery.maxMs)}ms (threshold ${thresholds.slowQueryMs}ms).` });
  }

  return diagnostics;
}

export function hasProblem(findings: LineFindings): boolean {
  return Boolean(findings.exception || findings.nPlusOne || findings.slowQuery || findings.slowHttp || findings.hot);
}

export function formatMs(milliseconds: number): string {
  return milliseconds >= 10 ? String(Math.round(milliseconds)) : String(Math.round(milliseconds * 10) / 10);
}

export function shortClassName(className: string): string {
  return className.split('\\').pop() ?? className;
}

function exceptionFinding(stats: LineStats): ExceptionFinding | undefined {
  let worst: ExceptionFinding | undefined;

  for (const [className, exception] of stats.exceptions) {
    if (worst === undefined || exception.count > worst.count) {
      worst = { className, count: exception.count, message: exception.lastMessage, lastSeen: exception.lastSeen };
    }
  }

  return worst;
}

function nPlusOneFinding(stats: LineStats, thresholds: Thresholds): NPlusOneFinding | undefined {
  let worst: NPlusOneFinding | undefined;

  for (const query of stats.queries.values()) {
    const { count, ms, name, kind } = query.worstBatch;

    if (count >= thresholds.nPlusOneThreshold && (worst === undefined || count > worst.count)) {
      worst = { count, ms, batchName: name, kind };
    }
  }

  return worst;
}

function slowQueryFinding(stats: LineStats, thresholds: Thresholds): { maxMs: number } | undefined {
  const maxMs = Math.max(0, ...[...stats.queries.values()].map((query) => query.maxMs));

  return maxMs >= thresholds.slowQueryMs ? { maxMs } : undefined;
}

function slowHttpFinding(stats: LineStats, thresholds: Thresholds): SlowHttpFinding | undefined {
  let worst: SlowHttpFinding | undefined;

  for (const call of stats.http.values()) {
    const isSlow = call.maxMs >= thresholds.slowHttpMs;

    if (!isSlow && call.failures === 0) {
      continue;
    }

    if (worst === undefined || call.maxMs > worst.maxMs) {
      worst = { kind: isSlow ? 'slow' : 'failed', maxMs: call.maxMs, failures: call.failures, method: call.sample.method, url: call.sample.url };
    }
  }

  return worst;
}

function hotFinding(stats: LineStats, thresholds: Thresholds): { avgMs: number; percent: number } | undefined {
  if (stats.profile === null || stats.profile.batches === 0) {
    return undefined;
  }

  const percent = stats.profile.percentSum / stats.profile.batches;

  return percent >= thresholds.hotLinePercent ? { avgMs: stats.profile.totalMs / stats.profile.batches, percent } : undefined;
}

function normalFinding(stats: LineStats): { queries: number; ms: number } | null {
  const batches = stats.queryBatchIds.size;

  if (batches === 0) {
    return null;
  }

  let executions = 0;
  let totalMs = 0;

  for (const query of stats.queries.values()) {
    executions += query.executions;
    totalMs += query.totalMs;
  }

  return { queries: executions / batches, ms: totalMs / batches };
}

function httpMsPerBatch(stats: LineStats): number {
  const batches = stats.httpBatchIds.size;

  if (batches === 0) {
    return 0;
  }

  let totalMs = 0;

  for (const call of stats.http.values()) {
    totalMs += call.totalMs;
  }

  return totalMs / batches;
}

function clockTime(isoTime: string): string {
  const time = new Date(isoTime);

  if (Number.isNaN(time.getTime())) {
    return '--:--';
  }

  return `${String(time.getHours()).padStart(2, '0')}:${String(time.getMinutes()).padStart(2, '0')}`;
}
