import * as vscode from 'vscode';
import { LensCodeLensProvider } from './ui/codeLens';
import { registerCommands } from './ui/commands';
import { RuntimeLensController } from './ui/controller';
import { InlineDecorations } from './ui/decorations';
import { LensDiagnostics } from './ui/diagnostics';
import { LensHoverProvider } from './ui/hover';
import { readSettings, SECTION } from './ui/settings';
import { LensStatusBar } from './ui/statusBar';

const DOCUMENT_SELECTOR: vscode.DocumentSelector = [{ language: 'php' }, { language: 'blade' }, { pattern: '**/*.blade.php' }];

export function activate(context: vscode.ExtensionContext): void {
  const output = vscode.window.createOutputChannel('Runtime Lens');
  const controller = new RuntimeLensController(readSettings(), output);
  const codeLenses = new LensCodeLensProvider(controller);

  context.subscriptions.push(
    output,
    controller,
    new InlineDecorations(controller),
    new LensDiagnostics(controller),
    new LensStatusBar(controller),
    codeLenses,
    vscode.languages.registerHoverProvider(DOCUMENT_SELECTOR, new LensHoverProvider(controller)),
    vscode.languages.registerCodeLensProvider(DOCUMENT_SELECTOR, codeLenses),
    ...registerCommands(controller),
    vscode.workspace.onDidCloseTextDocument((document) => controller.forgetDocument(document)),
    vscode.workspace.onDidChangeConfiguration((event) => {
      if (event.affectsConfiguration(SECTION)) {
        controller.applySettings(readSettings());
      }
    }),
  );

  controller.start();
}

export function deactivate(): void {}
