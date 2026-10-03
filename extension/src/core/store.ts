import type { BatchRecord } from './types';

export class BatchStore {
  private batches: BatchRecord[] = [];
  private focusedId: string | null = null;

  constructor(private windowSize: number) {}

  replace(batches: BatchRecord[]): void {
    this.batches = [];
    this.append(batches);
  }

  append(batches: BatchRecord[]): void {
    for (const batch of batches) {
      this.batches.push(batch);
    }

    this.trimToWindow();
  }

  resize(windowSize: number): void {
    this.windowSize = windowSize;
    this.trimToWindow();
  }

  focus(batchId: string | null): void {
    this.focusedId = batchId;
  }

  focusedBatch(): BatchRecord | null {
    return this.batches.find((batch) => batch.id === this.focusedId) ?? null;
  }

  inScope(): BatchRecord[] {
    const focused = this.focusedBatch();

    return focused === null ? this.batches : [focused];
  }

  recent(limit: number): BatchRecord[] {
    return this.batches.slice(-limit).reverse();
  }

  size(): number {
    return this.batches.length;
  }

  private trimToWindow(): void {
    if (this.batches.length > this.windowSize) {
      this.batches = this.batches.slice(this.batches.length - this.windowSize);
    }

    if (this.focusedId !== null && this.focusedBatch() === null) {
      this.focusedId = null;
    }
  }
}

export function latestRunPerName(batches: BatchRecord[]): BatchRecord[] {
  const latest = new Map<string, BatchRecord>();

  for (const batch of batches) {
    latest.delete(`${batch.kind}|${batch.name}`);
    latest.set(`${batch.kind}|${batch.name}`, batch);
  }

  return [...latest.values()];
}
