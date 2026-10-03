import { FILE_LEVEL, type BatchKind, type BatchRecord, type HttpRecord, type QueryRecord } from './types';

export interface WorstBatch {
  name: string;
  kind: BatchKind;
  count: number;
  ms: number;
}

export interface QueryStats {
  sample: QueryRecord;
  executions: number;
  totalMs: number;
  maxMs: number;
  worstBatch: WorstBatch;
  batchNames: Set<string>;
}

export interface HttpStats {
  sample: HttpRecord;
  calls: number;
  totalMs: number;
  maxMs: number;
  failures: number;
  batchNames: Set<string>;
}

export interface ExceptionStats {
  count: number;
  lastMessage: string;
  lastSeen: string;
  batchNames: Set<string>;
}

export interface ProfileLineStats {
  batches: number;
  totalMs: number;
  percentSum: number;
}

export interface LineStats {
  line: number;
  snippet: string | null;
  newestBatchOrder: number;
  queryBatchIds: Set<string>;
  httpBatchIds: Set<string>;
  queries: Map<string, QueryStats>;
  http: Map<string, HttpStats>;
  exceptions: Map<string, ExceptionStats>;
  profile: ProfileLineStats | null;
}

export type FileLines = Map<string, LineStats>;

export type LinesByNumber = Map<number, LineStats>;

export type LineIndex = Map<string, FileLines>;

export function buildLineIndex(batches: BatchRecord[]): LineIndex {
  const index: LineIndex = new Map();

  batches.forEach((batch, order) => {
    addQueries(index, batch, order);
    addHttpCalls(index, batch, order);
    addExceptions(index, batch, order);
    addProfileLines(index, batch, order);
  });

  return index;
}

function addQueries(index: LineIndex, batch: BatchRecord, order: number): void {
  const countsInBatch = new Map<QueryStats, { count: number; ms: number }>();

  for (const query of batch.queries) {
    if (query.file === null) {
      continue;
    }

    const lineStats = statsAt(index, query.file, query.line, query.snippet, order);
    const queryStats = lineStats.queries.get(query.fp) ?? newQueryStats(query);

    lineStats.queryBatchIds.add(batch.id);
    lineStats.queries.set(query.fp, queryStats);
    queryStats.sample = query;
    queryStats.executions++;
    queryStats.totalMs += query.ms;
    queryStats.maxMs = Math.max(queryStats.maxMs, query.ms);
    queryStats.batchNames.add(batch.name);

    const inBatch = countsInBatch.get(queryStats) ?? { count: 0, ms: 0 };
    inBatch.count++;
    inBatch.ms += query.ms;
    countsInBatch.set(queryStats, inBatch);
  }

  for (const [queryStats, inBatch] of countsInBatch) {
    if (inBatch.count > queryStats.worstBatch.count) {
      queryStats.worstBatch = { name: batch.name, kind: batch.kind, count: inBatch.count, ms: inBatch.ms };
    }
  }
}

function newQueryStats(query: QueryRecord): QueryStats {
  return {
    sample: query,
    executions: 0,
    totalMs: 0,
    maxMs: 0,
    worstBatch: { name: '', kind: 'request', count: 0, ms: 0 },
    batchNames: new Set(),
  };
}

function addHttpCalls(index: LineIndex, batch: BatchRecord, order: number): void {
  for (const call of batch.http) {
    if (call.file === null) {
      continue;
    }

    const lineStats = statsAt(index, call.file, call.line, call.snippet, order);
    const key = `${call.method} ${call.url}`;
    const httpStats = lineStats.http.get(key) ?? { sample: call, calls: 0, totalMs: 0, maxMs: 0, failures: 0, batchNames: new Set<string>() };

    lineStats.httpBatchIds.add(batch.id);
    lineStats.http.set(key, httpStats);
    httpStats.sample = call;
    httpStats.calls++;
    httpStats.totalMs += call.ms ?? 0;
    httpStats.maxMs = Math.max(httpStats.maxMs, call.ms ?? 0);
    httpStats.failures += call.failed ? 1 : 0;
    httpStats.batchNames.add(batch.name);
  }
}

function addExceptions(index: LineIndex, batch: BatchRecord, order: number): void {
  for (const exception of batch.exceptions) {
    if (exception.file === null) {
      continue;
    }

    const lineStats = statsAt(index, exception.file, exception.line, exception.snippet, order);
    const exceptionStats = lineStats.exceptions.get(exception.class) ?? { count: 0, lastMessage: '', lastSeen: '', batchNames: new Set<string>() };

    lineStats.exceptions.set(exception.class, exceptionStats);
    exceptionStats.count++;
    exceptionStats.lastMessage = exception.message;
    exceptionStats.lastSeen = batch.started_at;
    exceptionStats.batchNames.add(batch.name);
  }
}

function addProfileLines(index: LineIndex, batch: BatchRecord, order: number): void {
  for (const line of batch.profile?.lines ?? []) {
    const lineStats = statsAt(index, line.file, line.line, line.snippet, order);
    const profile = lineStats.profile ?? { batches: 0, totalMs: 0, percentSum: 0 };

    lineStats.profile = profile;
    profile.batches++;
    profile.totalMs += line.total_ms;
    profile.percentSum += batch.duration_ms > 0 ? (line.total_ms / batch.duration_ms) * 100 : 0;
  }
}

function statsAt(index: LineIndex, file: string, line: number | null, snippet: string | null, order: number): LineStats {
  const fileLines: FileLines = index.get(file) ?? new Map();
  const recordedLine = line ?? FILE_LEVEL;
  const key = `${recordedLine}|${snippet ?? ''}`;
  const lineStats = fileLines.get(key) ?? emptyLineStats(recordedLine, snippet);

  index.set(file, fileLines);
  fileLines.set(key, lineStats);
  lineStats.newestBatchOrder = Math.max(lineStats.newestBatchOrder, order);

  return lineStats;
}

function emptyLineStats(line: number, snippet: string | null): LineStats {
  return {
    line,
    snippet,
    newestBatchOrder: 0,
    queryBatchIds: new Set(),
    httpBatchIds: new Set(),
    queries: new Map(),
    http: new Map(),
    exceptions: new Map(),
    profile: null,
  };
}
