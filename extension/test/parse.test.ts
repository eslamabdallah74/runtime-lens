import { describe, expect, it } from 'vitest';
import { mergeParsed, parseBatchLines } from '../src/core/parse';

const valid = '{"v":1,"id":"a","kind":"request","name":"GET /","queries":[],"http":[],"exceptions":[]}';

describe('parseBatchLines', () => {
  it('parses valid lines and fills optional fields with defaults', () => {
    const parsed = parseBatchLines(`${valid}\n`);

    expect(parsed.malformed).toBe(0);
    expect(parsed.batches[0]).toMatchObject({ id: 'a', source: 'app', status: null, duration_ms: 0, entry: null, dropped_queries: 0 });
  });

  it('counts lines that are not JSON or miss required fields as malformed', () => {
    const parsed = parseBatchLines(['not json', '{"v":1,"id":"a"}', '[1,2]', '{"v":1,"id":5,"kind":"request","name":"x","queries":[],"http":[],"exceptions":[]}'].join('\n'));

    expect(parsed).toMatchObject({ batches: [], malformed: 4, unknownVersion: 0 });
  });

  it('counts a newer format version separately instead of as malformed', () => {
    expect(parseBatchLines('{"v":2,"id":"y"}')).toMatchObject({ malformed: 0, unknownVersion: 1 });
  });

  it('ignores blank lines', () => {
    expect(parseBatchLines(`\n\n${valid}\n\n`).batches).toHaveLength(1);
  });

  it('merges two parse results', () => {
    const merged = mergeParsed(parseBatchLines(valid), parseBatchLines(`${valid}\nbroken`));

    expect(merged.batches).toHaveLength(2);
    expect(merged.malformed).toBe(1);
  });
});
