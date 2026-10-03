import { appendFileSync, mkdtempSync, renameSync, rmSync, truncateSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { BatchFileTail } from '../src/core/tail';

const line = (id: string) => `{"v":1,"id":"${id}","kind":"request","name":"GET /${id}","queries":[],"http":[],"exceptions":[]}\n`;

describe('BatchFileTail', () => {
  let directory: string;
  let current: string;

  beforeEach(() => {
    directory = mkdtempSync(join(tmpdir(), 'runtime-lens-tail-'));
    current = join(directory, 'batches.jsonl');
  });

  afterEach(() => rmSync(directory, { recursive: true, force: true }));

  it('reads the rotated file first, then the current file', () => {
    writeFileSync(join(directory, 'batches.1.jsonl'), line('old'));
    writeFileSync(current, line('new'));

    expect(new BatchFileTail(directory).readInitial().batches.map((batch) => batch.id)).toEqual(['old', 'new']);
  });

  it('waits for a partial line to be completed instead of counting it as malformed', () => {
    writeFileSync(current, line('a'));
    const tail = new BatchFileTail(directory);
    tail.readInitial();

    appendFileSync(current, '{"v":1,"id":"b","kind":"request","name":"x"');
    expect(tail.readNew()).toEqual({ batches: [], malformed: 0, unknownVersion: 0 });

    appendFileSync(current, ',"queries":[],"http":[],"exceptions":[]}\n');
    const appended = tail.readNew();

    expect(appended).not.toBe('reset');
    expect(appended !== 'reset' && appended.batches.map((batch) => batch.id)).toEqual(['b']);
  });

  it('asks for a reset when the file is truncated', () => {
    writeFileSync(current, line('a'));
    const tail = new BatchFileTail(directory);
    tail.readInitial();

    truncateSync(current);

    expect(tail.readNew()).toBe('reset');
  });

  it('asks for a reset when the file is rotated', () => {
    writeFileSync(current, line('a') + line('b'));
    const tail = new BatchFileTail(directory);
    tail.readInitial();

    renameSync(current, join(directory, 'batches.1.jsonl'));
    writeFileSync(current, line('c') + line('d') + line('e'));

    expect(tail.readNew()).toBe('reset');
  });

  it('starts empty when there is no file yet and picks it up once created', () => {
    const tail = new BatchFileTail(directory);

    expect(tail.readInitial().batches).toEqual([]);

    writeFileSync(current, line('first'));
    const appended = tail.readNew();

    expect(appended !== 'reset' && appended.batches.map((batch) => batch.id)).toEqual(['first']);
  });

  it('asks for a reset when the file is deleted after being read', () => {
    writeFileSync(current, line('a'));
    const tail = new BatchFileTail(directory);
    tail.readInitial();

    rmSync(current);

    expect(tail.readNew()).toBe('reset');
  });
});
