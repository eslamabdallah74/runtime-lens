import * as vscode from 'vscode';
import { findMethod, type MethodSymbol } from '../core/anchor';
import { formatMs, inlineLabel, lineFindings } from '../core/findings';
import type { LineStats, LinesByNumber } from '../core/lineIndex';
import { ioForRange, type MethodStats } from '../core/methodIndex';
import type { Thresholds } from '../core/settings';
import type { RuntimeLensController } from './controller';
import { debounce } from './debounce';
import { methodSymbolsFor } from './symbols';

const REANCHOR_DELAY_MS = 300;

const CLOSURE_START = /\bfunction\s*\(|\bfn\s*\(/;

export class LensCodeLensProvider implements vscode.CodeLensProvider, vscode.Disposable {
  private readonly changeEmitter = new vscode.EventEmitter<void>();
  readonly onDidChangeCodeLenses = this.changeEmitter.event;

  private readonly reanchor = debounce(() => this.changeEmitter.fire(), REANCHOR_DELAY_MS);
  private readonly subscriptions: vscode.Disposable[];

  constructor(private readonly controller: RuntimeLensController) {
    this.subscriptions = [
      controller.onDidUpdate(() => this.changeEmitter.fire()),
      vscode.workspace.onDidChangeTextDocument((event) => {
        if (controller.hasDataFor(event.document)) {
          this.reanchor.schedule();
        }
      }),
    ];
  }

  async provideCodeLenses(document: vscode.TextDocument): Promise<vscode.CodeLens[]> {
    const relativeFile = this.controller.relativePathOf(document.uri);

    if (relativeFile === null || !this.controller.settings().enabled) {
      return [];
    }

    const thresholds = this.controller.settings().thresholds;
    const anchored = this.controller.anchoredFor(document);
    const methodsInFile = [...this.controller.methodStats().values()].filter((method) => method.file === relativeFile);
    const lenses: vscode.CodeLens[] = [];

    if (anchored?.fileLevel !== undefined) {
      const label = inlineLabel(lineFindings(anchored.fileLevel, thresholds));

      if (label !== null) {
        lenses.push(labelLens(0, `This view: ${label}`));
      }
    }

    if (anchored === null && methodsInFile.length === 0) {
      return lenses;
    }

    const ioLines = oneBasedLines(anchored?.lines ?? new Map());

    for (const symbol of await methodSymbolsFor(document.uri)) {
      const parts = methodLensParts(symbol, methodsInFile, ioLines, thresholds);

      if (parts.length > 0) {
        lenses.push(labelLens(symbol.startLine, parts.join(' · ')));
      }
    }

    return [...lenses, ...closureLenses(document, methodsInFile)];
  }

  dispose(): void {
    this.reanchor.cancel();
    this.subscriptions.forEach((subscription) => subscription.dispose());
    this.changeEmitter.dispose();
  }
}

export function oneBasedLines(lines: Map<number, LineStats>): LinesByNumber {
  return new Map([...lines].map(([line, stats]) => [line + 1, stats]));
}

function methodLensParts(symbol: MethodSymbol, methodsInFile: MethodStats[], ioLines: LinesByNumber, thresholds: Thresholds): string[] {
  const matching = methodsInFile.filter((method) => findMethod([symbol], method.name) !== null);
  const entry = matching.find((method) => method.entry !== undefined)?.entry;
  const profile = matching.find((method) => method.profile !== undefined)?.profile;
  const io = ioForRange(ioLines, symbol.startLine + 1, symbol.endLine + 1, thresholds);
  const parts: string[] = [];

  if (entry !== undefined) {
    parts.push(`⏱ avg ${formatMs(entry.avgMs)}ms per request (${entry.count})`);
  }

  if (profile !== undefined) {
    parts.push(`⏱ ${formatMs(profile.avgTotalMs)}ms · ${Math.round(profile.avgPercent)}% of request`);
  }

  if (io.dbMs > 0) {
    parts.push(`DB ${formatMs(io.dbMs)}ms`);
  }

  if (io.httpMs > 0) {
    parts.push(`HTTP ${formatMs(io.httpMs)}ms`);
  }

  if (io.nPlusOneLines > 0) {
    parts.push(`N+1 on ${io.nPlusOneLines} ${io.nPlusOneLines === 1 ? 'line' : 'lines'}`);
  }

  return parts;
}

function closureLenses(document: vscode.TextDocument, methodsInFile: MethodStats[]): vscode.CodeLens[] {
  return methodsInFile
    .filter((method) => method.name === '{closure}' && method.entry !== undefined && method.line !== null && method.line <= document.lineCount)
    .filter((method) => CLOSURE_START.test(document.lineAt(method.line! - 1).text))
    .map((method) => labelLens(method.line! - 1, `⏱ avg ${formatMs(method.entry!.avgMs)}ms per request (${method.entry!.count})`));
}

function labelLens(line: number, title: string): vscode.CodeLens {
  return new vscode.CodeLens(new vscode.Range(line, 0, line, 0), { title, command: '' });
}
