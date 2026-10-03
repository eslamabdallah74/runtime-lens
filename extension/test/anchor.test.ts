import { describe, expect, it } from 'vitest';
import { anchorFile, anchorMatch, findMethod } from '../src/core/anchor';
import { buildLineIndex } from '../src/core/lineIndex';
import { batch, query, times } from './helpers';

const lines = (count: number, overrides: Record<number, string>) =>
  Array.from({ length: count }, (_, index) => overrides[index + 1] ?? `// line ${index + 1}`);

describe('anchorMatch', () => {
  it('keeps the recorded line when the code is unchanged', () => {
    expect(anchorMatch(lines(40, { 30: '    run();' }), 30, 'run();')).toEqual({ line: 29, exact: true });
  });

  it('follows code that moved by up to 20 lines', () => {
    expect(anchorMatch(lines(60, { 45: 'run();' }), 30, 'run();')).toEqual({ line: 44, exact: false });
  });

  it('prefers the line below when two matches are equally far', () => {
    expect(anchorMatch(lines(40, { 28: 'run();', 32: 'run();' }), 30, 'run();')).toEqual({ line: 31, exact: false });
  });

  it('treats edited or far-moved code as stale', () => {
    expect(anchorMatch(lines(40, { 30: 'changed();' }), 30, 'run();')).toBeNull();
    expect(anchorMatch(lines(80, { 70: 'run();' }), 30, 'run();')).toBeNull();
  });

  it('treats a missing snippet as stale', () => {
    expect(anchorMatch(lines(40, {}), 30, null)).toBeNull();
  });

  it('matches long lines by their first 200 characters', () => {
    const long = 'x'.repeat(260);

    expect(anchorMatch(lines(40, { 30: long }), 30, 'x'.repeat(200))).toEqual({ line: 29, exact: true });
  });
});

describe('anchorFile', () => {
  const file = 'app/X.php';
  const teacher = '$t = $c->teacher->name;';
  const posts = '$posts = Post::all();';

  it('lets new data on the exact line win over old data that moved there', () => {
    const index = buildLineIndex([
      batch({ name: 'GET /a', queries: times(10, () => query({ file, line: 30, snippet: teacher, fp: 'teacher' })) }),
      batch({ name: 'GET /b', queries: [query({ file, line: 30, snippet: posts, fp: 'posts' }), query({ file, line: 32, snippet: teacher, fp: 'teacher' })] }),
    ]);

    const anchored = anchorFile(index.get(file)!, lines(40, { 30: posts, 32: teacher }));

    expect(anchored.lines.get(29)?.snippet).toBe(posts);
    expect(anchored.lines.get(31)?.queries.get('teacher')?.executions).toBe(1);
    expect(anchored.lines.size).toBe(2);
  });

  it('lets the newest run win when two records match the same line exactly', () => {
    const index = buildLineIndex([
      batch({ name: 'GET /a', queries: [query({ file, line: 30, snippet: teacher, fp: 'a' })] }),
      batch({ name: 'GET /b', queries: [query({ file, line: 31, snippet: teacher, fp: 'b' })] }),
    ]);

    const anchored = anchorFile(index.get(file)!, lines(40, { 30: teacher, 31: teacher }));

    expect(anchored.lines.get(29)?.queries.has('a')).toBe(true);
    expect(anchored.lines.get(30)?.queries.has('b')).toBe(true);
  });

  it('keeps file-level (Blade) data separate', () => {
    const index = buildLineIndex([batch({ queries: [query({ file: 'resources/views/a.blade.php', line: null, snippet: null })] })]);

    expect(anchorFile(index.get('resources/views/a.blade.php')!, ['<div>']).fileLevel).toBeDefined();
  });
});

describe('findMethod', () => {
  const symbols = [
    { className: 'CourseController', methodName: 'index', startLine: 10, endLine: 20 },
    { className: 'OtherController', methodName: 'index', startLine: 30, endLine: 40 },
    { className: null, methodName: 'helper', startLine: 50, endLine: 55 },
  ];

  it('matches a qualified method by short class name', () => {
    expect(findMethod(symbols, 'App\\Http\\Controllers\\OtherController::index')?.startLine).toBe(30);
  });

  it('matches plain functions', () => {
    expect(findMethod(symbols, 'helper')?.startLine).toBe(50);
  });

  it('returns null when nothing matches', () => {
    expect(findMethod(symbols, 'App\\Missing::index')).toBeNull();
  });
});
