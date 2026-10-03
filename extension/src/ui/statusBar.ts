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

  dispose(): void {
    this.subscription.dispose();
    this.item.dispose();
  }
}
