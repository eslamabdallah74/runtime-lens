import { readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import * as vscode from 'vscode';
import { diagnosticsFor, lineFindings, type Diagnostic } from '../core/findings';
import type { LineStats } from '../core/lineIndex';
import type { Thresholds } from '../core/settings';
import type { AnchoredFile, RuntimeLensController } from './controller';
import { debounce } from './debounce';

const REANCHOR_DELAY_MS = 300;

const SEVERITIES: Record<Diagnostic['severity'], vscode.DiagnosticSeverity> = {
  error: vscode.DiagnosticSeverity.Error,
  warning: vscode.DiagnosticSeverity.Warning,
  info: vscode.DiagnosticSeverity.Information,
};

interface CachedText {
  modifiedAt: number;
  text: string;
}

export class LensDiagnostics implements vscode.Disposable {
  private readonly collection = vscode.languages.createDiagnosticCollection('runtime-lens');
  private readonly diskTexts = new Map<string, CachedText>();
  private readonly changedDocuments = new Set<vscode.TextDocument>();
  private readonly reanchor = debounce(() => this.refreshChangedDocuments(), REANCHOR_DELAY_MS);
  private readonly subscriptions: vscode.Disposable[];

  constructor(private readonly controller: RuntimeLensController) {
    this.subscriptions = [
      controller.onDidUpdate(() => this.refreshAll()),
      vscode.workspace.onDidChangeTextDocument((event) => this.scheduleDocument(event.document)),
    ];
  }

  refreshAll(): void {
    this.collection.clear();

    const root = this.controller.root();

    if (root === null || !this.controller.settings().enabled) {
      return;
    }

    for (const relativeFile of this.controller.index().keys()) {
      this.refreshFile(vscode.Uri.file(join(root, relativeFile)), relativeFile);
    }
  }

  dispose(): void {
    this.reanchor.cancel();
    this.subscriptions.forEach((subscription) => subscription.dispose());
    this.collection.dispose();
  }

  private scheduleDocument(document: vscode.TextDocument): void {
    if (!this.controller.hasDataFor(document)) {
      return;
    }

    this.changedDocuments.add(document);
    this.reanchor.schedule();
  }

  private refreshChangedDocuments(): void {
    for (const document of this.changedDocuments) {
      const relativeFile = this.controller.relativePathOf(document.uri);

      if (relativeFile !== null) {
        this.refreshFile(document.uri, relativeFile);
      }
    }

    this.changedDocuments.clear();
  }

  private refreshFile(uri: vscode.Uri, relativeFile: string): void {
    const anchored = this.anchoredFile(uri, relativeFile);

    this.collection.set(uri, anchored === null ? [] : this.diagnosticsOf(anchored, this.controller.settings().thresholds));
  }

  private anchoredFile(uri: vscode.Uri, relativeFile: string): AnchoredFile | null {
    const openDocument = vscode.workspace.textDocuments.find((document) => document.uri.toString() === uri.toString());

    if (openDocument !== undefined) {
      return this.controller.anchoredFor(openDocument);
    }

    const text = this.diskText(uri.fsPath);

    return text === null ? null : this.controller.anchoredForText(relativeFile, text);
  }

  private diskText(file: string): string | null {
    try {
      const modifiedAt = statSync(file).mtimeMs;
      const cached = this.diskTexts.get(file);

      if (cached !== undefined && cached.modifiedAt === modifiedAt) {
        return cached.text;
      }

      const text = readFileSync(file, 'utf8');
      this.diskTexts.set(file, { modifiedAt, text });

      return text;
    } catch {
      return null;
    }
  }

  private diagnosticsOf(anchored: AnchoredFile, thresholds: Thresholds): vscode.Diagnostic[] {
    const diagnostics: vscode.Diagnostic[] = [];

    if (anchored.fileLevel !== undefined) {
      diagnostics.push(...this.lineDiagnostics(anchored.fileLevel, 0, thresholds));
    }

    for (const [line, stats] of anchored.lines) {
      diagnostics.push(...this.lineDiagnostics(stats, line, thresholds));
    }

    return diagnostics;
  }

  private lineDiagnostics(stats: LineStats, line: number, thresholds: Thresholds): vscode.Diagnostic[] {
    return diagnosticsFor(lineFindings(stats, thresholds), thresholds).map((finding) => {
      const diagnostic = new vscode.Diagnostic(new vscode.Range(line, 0, line, Number.MAX_SAFE_INTEGER), finding.message, SEVERITIES[finding.severity]);
      diagnostic.source = 'Runtime Lens';

      return diagnostic;
    });
  }
}
