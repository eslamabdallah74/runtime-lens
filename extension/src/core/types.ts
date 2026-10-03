export const FILE_LEVEL = 0;

export const CURRENT_FILE = 'batches.jsonl';

export const ROTATED_FILE = 'batches.1.jsonl';

export type BatchSource = 'app' | 'tests';

export type BatchKind = 'request' | 'job' | 'command';

export interface SourceLocationRecord {
  file: string | null;
  line: number | null;
  snippet: string | null;
}

export interface QueryRecord extends SourceLocationRecord {
  sql: string;
  bindings?: unknown[];
  ms: number;
  connection: string;
  fp: string;
}

export interface HttpRecord extends SourceLocationRecord {
  method: string;
  url: string;
  status: number | null;
  ms: number | null;
  failed: boolean;
}

export interface ExceptionRecord extends SourceLocationRecord {
  class: string;
  message: string;
}

export interface EntryPointRecord {
  file: string;
  line: number;
  function: string;
}

export interface ProfileFunctionRecord {
  file: string;
  function: string;
  total_ms: number;
  self_ms: number;
}

export interface ProfileLineRecord {
  file: string;
  line: number;
  snippet: string | null;
  total_ms: number;
}

export interface ProfileRecord {
  period_ms: number;
  functions: ProfileFunctionRecord[];
  lines: ProfileLineRecord[];
}

export interface BatchRecord {
  v: 1;
  id: string;
  source: BatchSource;
  kind: BatchKind;
  name: string;
  status: number | string | null;
  started_at: string;
  duration_ms: number;
  entry: EntryPointRecord | null;
  dropped_queries: number;
  queries: QueryRecord[];
  http: HttpRecord[];
  exceptions: ExceptionRecord[];
  profile?: ProfileRecord;
}

export interface ParsedLines {
  batches: BatchRecord[];
  malformed: number;
  unknownVersion: number;
}
