import { describe, expect, it } from 'vitest';
import { BatchStore, latestRunPerName } from '../src/core/store';
import { batch, times } from './helpers';

describe('BatchStore', () => {
  it('keeps only the newest batches that fit the window', () => {
    const store = new BatchStore(2);
    const batches = times(3, () => batch());

    store.replace(batches);

    expect(store.inScope()).toEqual(batches.slice(1));
  });

  it('lists recent batches newest first', () => {
    const store = new BatchStore(10);
    const batches = times(3, () => batch());

    store.replace(batches);

    expect(store.recent(2)).toEqual([batches[2], batches[1]]);
  });

  it('focuses on one batch and back on all of them', () => {
    const store = new BatchStore(10);
    const batches = times(3, () => batch());
    store.replace(batches);

    store.focus(batches[1]!.id);
    expect(store.inScope()).toEqual([batches[1]]);

    store.focus(null);
    expect(store.inScope()).toEqual(batches);
  });

  it('drops the focus when the focused batch leaves the window', () => {
    const store = new BatchStore(2);
    const first = batch();
    store.replace([first, batch()]);
    store.focus(first.id);

    store.append([batch()]);

    expect(store.focusedBatch()).toBeNull();
  });

  it('accepts hundreds of thousands of batches in one append', () => {
    const store = new BatchStore(1000);

    store.replace(times(200_000, () => batch()));

    expect(store.size()).toBe(1000);
  });

  it('can grow its window', () => {
    const store = new BatchStore(1);
    store.replace([batch(), batch()]);
    store.resize(5);

    expect(store.size()).toBe(1);
  });
});

describe('latestRunPerName', () => {
  it('keeps only the newest run of each request, job or command name', () => {
    const oldCourses = batch({ name: 'GET /courses' });
    const users = batch({ name: 'GET /users' });
    const newCourses = batch({ name: 'GET /courses' });
    const job = batch({ name: 'GET /courses', kind: 'job' });

    expect(latestRunPerName([oldCourses, users, newCourses, job])).toEqual([users, newCourses, job]);
  });
});
