import { closeSync, openSync, readSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { mergeParsed, parseBatchLines } from './parse';
import { CURRENT_FILE, ROTATED_FILE, type ParsedLines } from './types';

const NEWLINE = 0x0a;

interface CompleteLines {
  text: string;
  consumedUpTo: number;
  inode: number | null;
}

export class BatchFileTail {
  private consumedUpTo = 0;
  private inode: number | null = null;

  constructor(private readonly storageDir: string) {}

  readInitial(): ParsedLines {
    const rotated = this.completeLinesFrom(join(this.storageDir, ROTATED_FILE), 0);
    const current = this.completeLinesFrom(this.currentFile(), 0);

    this.consumedUpTo = current.consumedUpTo;
    this.inode = current.inode;

    return mergeParsed(parseBatchLines(rotated.text), parseBatchLines(current.text));
  }

  readNew(): ParsedLines | 'reset' {
    const stats = statOrNull(this.currentFile());

    if (stats === null) {
      return this.consumedUpTo > 0 ? 'reset' : emptyParsed();
    }

    if ((this.inode !== null && stats.ino !== this.inode) || stats.size < this.consumedUpTo) {
      return 'reset';
    }

    const appended = this.completeLinesFrom(this.currentFile(), this.consumedUpTo);

    this.consumedUpTo = appended.consumedUpTo;
    this.inode = appended.inode;

    return parseBatchLines(appended.text);
  }

  currentFile(): string {
    return join(this.storageDir, CURRENT_FILE);
  }

  private completeLinesFrom(file: string, offset: number): CompleteLines {
    const stats = statOrNull(file);

    if (stats === null || stats.size <= offset) {
      return { text: '', consumedUpTo: offset, inode: stats?.ino ?? null };
    }

    const bytes = readBytes(file, offset, stats.size - offset);
    const lastNewline = bytes.lastIndexOf(NEWLINE);

    if (lastNewline === -1) {
      return { text: '', consumedUpTo: offset, inode: stats.ino };
    }

    return {
      text: bytes.subarray(0, lastNewline + 1).toString('utf8'),
      consumedUpTo: offset + lastNewline + 1,
      inode: stats.ino,
    };
  }
}

function emptyParsed(): ParsedLines {
  return { batches: [], malformed: 0, unknownVersion: 0 };
}

function statOrNull(file: string): { size: number; ino: number } | null {
  try {
    const stats = statSync(file);

    return { size: stats.size, ino: stats.ino };
  } catch {
    return null;
  }
}

function readBytes(file: string, offset: number, length: number): Buffer {
  const buffer = Buffer.alloc(length);
  const descriptor = openSync(file, 'r');

  try {
    const bytesRead = readSync(descriptor, buffer, 0, length, offset);

    return buffer.subarray(0, bytesRead);
  } finally {
    closeSync(descriptor);
  }
}
