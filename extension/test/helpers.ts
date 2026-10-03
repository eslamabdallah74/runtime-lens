import type { BatchRecord, ExceptionRecord, HttpRecord, QueryRecord } from '../src/core/types';

let sequence = 0;

export function batch(overrides: Partial<BatchRecord> = {}): BatchRecord {
  sequence++;

  return {
    v: 1,
    id: `b${sequence}`,
    source: 'app',
    kind: 'request',
    name: 'GET /courses',
    status: 200,
    started_at: '2026-10-03T10:00:00.000Z',
    duration_ms: 100,
    entry: null,
    dropped_queries: 0,
    queries: [],
    http: [],
    exceptions: [],
    ...overrides,
  };
}

export function query(overrides: Partial<QueryRecord> = {}): QueryRecord {
  return {
    sql: 'select * from teachers where id = ?',
    bindings: [1],
    ms: 1,
    connection: 'mysql',
    fp: 'teacher',
    file: 'app/Http/Controllers/CourseController.php',
    line: 30,
    snippet: '$name = $course->teacher->name;',
    ...overrides,
  };
}

export function httpCall(overrides: Partial<HttpRecord> = {}): HttpRecord {
  return {
    method: 'GET',
    url: 'https://partner.example/api',
    status: 200,
    ms: 50,
    failed: false,
    file: 'app/Services/Partner.php',
    line: 12,
    snippet: '$response = Http::get($url);',
    ...overrides,
  };
}

export function exception(overrides: Partial<ExceptionRecord> = {}): ExceptionRecord {
  return {
    class: 'App\\Exceptions\\QuotaExceeded',
    message: 'Daily quota reached',
    file: 'app/Services/Quota.php',
    line: 8,
    snippet: "throw new QuotaExceeded('Daily quota reached');",
    ...overrides,
  };
}

export function times<T>(count: number, make: () => T): T[] {
  return Array.from({ length: count }, make);
}
