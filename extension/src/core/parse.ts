import type { BatchRecord, ParsedLines } from './types';

type LineResult = BatchRecord | 'malformed' | 'unknown-version';

export function parseBatchLines(text: string): ParsedLines {
  const result: ParsedLines = { batches: [], malformed: 0, unknownVersion: 0 };

  for (const line of text.split('\n')) {
    if (line.trim() === '') {
      continue;
    }

    const parsed = parseLine(line);

    if (parsed === 'malformed') {
      result.malformed++;
    } else if (parsed === 'unknown-version') {
      result.unknownVersion++;
    } else {
      result.batches.push(parsed);
    }
  }

  return result;
}

export function mergeParsed(first: ParsedLines, second: ParsedLines): ParsedLines {
  return {
    batches: [...first.batches, ...second.batches],
    malformed: first.malformed + second.malformed,
    unknownVersion: first.unknownVersion + second.unknownVersion,
  };
}

function parseLine(line: string): LineResult {
  let value: unknown;

  try {
    value = JSON.parse(line);
  } catch {
    return 'malformed';
  }

  if (!isObject(value)) {
    return 'malformed';
  }

  if (typeof value.v === 'number' && value.v !== 1) {
    return 'unknown-version';
  }

  return hasRequiredFields(value) ? withDefaults(value) : 'malformed';
}

function isObject(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function hasRequiredFields(value: Record<string, unknown>): boolean {
  return value.v === 1
    && typeof value.id === 'string'
    && typeof value.kind === 'string'
    && typeof value.name === 'string'
    && Array.isArray(value.queries)
    && Array.isArray(value.http)
    && Array.isArray(value.exceptions);
}

function withDefaults(value: Record<string, unknown>): BatchRecord {
  return {
    source: 'app',
    status: null,
    started_at: '',
    duration_ms: 0,
    entry: null,
    dropped_queries: 0,
    ...value,
  } as BatchRecord;
}
