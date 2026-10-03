import type { FileLines, LineStats, LinesByNumber } from './lineIndex';
import { FILE_LEVEL } from './types';

const SEARCH_RADIUS = 20;

const SNIPPET_LENGTH = 200;

export interface MethodSymbol {
  className: string | null;
  methodName: string;
  startLine: number;
  endLine: number;
}

export interface AnchorMatch {
  line: number;
  exact: boolean;
}

export interface AnchoredLines {
  lines: LinesByNumber;
  fileLevel: LineStats | undefined;
}

export function anchorFile(fileLines: FileLines, documentLines: string[]): AnchoredLines {
  const anchored: AnchoredLines = { lines: new Map(), fileLevel: undefined };
  const candidates: Array<{ match: AnchorMatch; stats: LineStats }> = [];

  for (const stats of fileLines.values()) {
    if (stats.line === FILE_LEVEL) {
      anchored.fileLevel = stats;
      continue;
    }

    const match = anchorMatch(documentLines, stats.line, stats.snippet);

    if (match !== null) {
      candidates.push({ match, stats });
    }
  }

  candidates.sort((left, right) => Number(right.match.exact) - Number(left.match.exact) || right.stats.newestBatchOrder - left.stats.newestBatchOrder);

  for (const { match, stats } of candidates) {
    if (!anchored.lines.has(match.line)) {
      anchored.lines.set(match.line, stats);
    }
  }

  return anchored;
}

export function anchorMatch(lines: string[], recordedLine: number, snippet: string | null): AnchorMatch | null {
  const recordedIndex = recordedLine - 1;

  if (snippet === null) {
    return null;
  }

  if (matches(lines[recordedIndex], snippet)) {
    return { line: recordedIndex, exact: true };
  }

  for (let distance = 1; distance <= SEARCH_RADIUS; distance++) {
    if (matches(lines[recordedIndex + distance], snippet)) {
      return { line: recordedIndex + distance, exact: false };
    }

    if (matches(lines[recordedIndex - distance], snippet)) {
      return { line: recordedIndex - distance, exact: false };
    }
  }

  return null;
}

export function findMethod(symbols: MethodSymbol[], qualifiedName: string): MethodSymbol | null {
  const separator = qualifiedName.lastIndexOf('::');
  const methodName = separator === -1 ? qualifiedName : qualifiedName.slice(separator + 2);
  const className = separator === -1 ? null : qualifiedName.slice(0, separator).split('\\').pop() ?? null;

  return symbols.find((symbol) => symbol.methodName === methodName && (className === null || symbol.className === className)) ?? null;
}

function matches(line: string | undefined, snippet: string): boolean {
  return line !== undefined && [...line.trim()].slice(0, SNIPPET_LENGTH).join('') === snippet;
}
