import * as vscode from 'vscode';
import { inlineLabel, lineFindings } from '../core/findings';
import type { RuntimeLensController } from './controller';
import { debounce } from './debounce';

const REANCHOR_DELAY_MS = 300;

export class InlineDecorations implements vscode.Disposable {
  private readonly decorationType = vscode.window.createTextEditorDecorationType({
    after: { margin: '0 0 0 2em', color: new vscode.ThemeColor('editorCodeLens.foreground') },
    rangeBehavior: vscode.DecorationRangeBehavior.ClosedClosed,
  });

  private readonly reanchor = debounce(() => this.refreshAll(), REANCHOR_DELAY_MS);
  private readonly subscriptions: vscode.Disposable[];

  constructor(private readonly controller: RuntimeLensController) {
    this.subscriptions = [
      controller.onDidUpdate(() => this.refreshAll()),
      vscode.window.onDidChangeVisibleTextEditors(() => this.refreshAll()),
      vscode.workspace.onDidChangeTextDocument((event) => {
        if (controller.hasDataFor(event.document)) {
          this.reanchor.schedule();
        }
      }),
    ];
  }

  refreshAll(): void {
    for (const editor of vscode.window.visibleTextEditors) {
      this.refresh(editor);
    }
  }

  dispose(): void {
    this.reanchor.cancel();
    this.subscriptions.forEach((subscription) => subscription.dispose());
    this.decorationType.dispose();
  }

  private refresh(editor: vscode.TextEditor): void {
    const settings = this.controller.settings();
    const anchored = settings.enabled && settings.inline ? this.controller.anchoredFor(editor.document) : null;
    const decorations: vscode.DecorationOptions[] = [];

    for (const [line, stats] of anchored?.lines ?? []) {
      const label = inlineLabel(lineFindings(stats, settings.thresholds));

      if (label === null || line >= editor.document.lineCount) {
        continue;
      }

      const end = editor.document.lineAt(line).range.end;
      decorations.push({ range: new vscode.Range(end, end), renderOptions: { after: { contentText: label } } });
    }

    editor.setDecorations(this.decorationType, decorations);
  }
}
