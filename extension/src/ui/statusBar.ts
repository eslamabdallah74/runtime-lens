import * as vscode from 'vscode';
import type { RuntimeLensController } from './controller';

const NO_DATA_TOOLTIP = 'No Runtime Lens data yet. Click to see how to set it up: `composer require --dev runtime-lens/laravel`, then `php artisan runtime-lens:install`.';

export class LensStatusBar implements vscode.Disposable {
  private readonly item = vscode.window.createStatusBarItem(vscode.StatusBarAlignment.Left, 0);
  private readonly subscription: vscode.Disposable;

  constructor(private readonly controller: RuntimeLensController) {
    this.item.command = 'runtimeLens.recentRequests';
    this.subscription = controller.onDidUpdate(() => this.refresh());
    this.refresh();
  }

  refresh(): void {
    if (!this.controller.settings().enabled) {
      this.item.hide();

      return;
    }

    const store = this.controller.batchStore();
    const focused = store.focusedBatch();
    const readProblem = this.controller.readProblem();

    if (this.controller.root() === null) {
      this.showProblem('Lens: no Laravel app', 'No artisan file found in this workspace. If your Laravel app is in a subfolder, set runtimeLens.projectRoot.', {
        title: 'Open settings',
        command: 'workbench.action.openSettings',
        arguments: ['runtimeLens.projectRoot'],
      });

      return;
    }

    if (readProblem !== null) {
      this.showProblem('Lens: can\'t read data', `Runtime Lens can't read storage/runtime-lens/batches.jsonl: ${readProblem}. Make sure your editor's user can read it (for example, the PHP container writes it with a strict umask).`, 'runtimeLens.getStarted');

      return;
    }

    if (focused !== null) {
      this.item.text = `Lens: ▶ ${focused.name}`;
      this.item.tooltip = 'Showing one request only. Click to pick another or go back to all requests.';
    } else if (store.size() === 0) {
      this.item.text = 'Lens: no data';
      this.item.tooltip = new vscode.MarkdownString(NO_DATA_TOOLTIP);
      this.item.command = 'runtimeLens.getStarted';
      this.item.show();

      return;
    } else {
      const profiling = store.inScope().some((batch) => batch.profile !== undefined);
      this.item.text = `Lens: ${store.size()} requests${profiling ? ' · profiling' : ''}`;
      this.item.tooltip = 'Click to focus on one request.';
    }

    this.item.command = 'runtimeLens.recentRequests';
    this.item.show();
  }

  private showProblem(text: string, tooltip: string, command: string | vscode.Command): void {
    this.item.text = text;
    this.item.tooltip = tooltip;
    this.item.command = command;
    this.item.show();
  }

  dispose(): void {
    this.subscription.dispose();
    this.item.dispose();
  }
}
