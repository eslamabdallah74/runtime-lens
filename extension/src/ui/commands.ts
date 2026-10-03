import { join } from 'node:path';
import * as vscode from 'vscode';
import { findMethod, type MethodSymbol } from '../core/anchor';
import { formatMs, lineFindings } from '../core/findings';
import type { FileLines } from '../core/lineIndex';
import { ioForRange, slowestMethods } from '../core/methodIndex';
import type { Thresholds } from '../core/settings';
import { oneBasedLines } from './codeLens';
import type { RuntimeLensController } from './controller';
import { SECTION } from './settings';
import { methodSymbolsFor } from './symbols';

const RECENT_LIMIT = 50;
const SLOWEST_LIMIT = 50;
const FILES_TO_SCAN = 20;
const INSTALL_COMMAND = 'composer require --dev runtime-lens/laravel && php artisan runtime-lens:install';
const WALKTHROUGH_ID = 'runtime-lens.runtime-lens#runtimeLens.getStarted';

interface RequestItem extends vscode.QuickPickItem {
  batchId: string | null;
}

interface MethodItem extends vscode.QuickPickItem {
  file: string;
  line: number | null;
  name: string;
  ms: number;
}

export function registerCommands(controller: RuntimeLensController): vscode.Disposable[] {
  return [
    vscode.commands.registerCommand('runtimeLens.recentRequests', () => showRecentRequests(controller)),
    vscode.commands.registerCommand('runtimeLens.slowestMethods', () => showSlowestMethods(controller)),
    vscode.commands.registerCommand('runtimeLens.toggleInline', () => toggleInline()),
    vscode.commands.registerCommand('runtimeLens.clearData', () => clearData(controller)),
    vscode.commands.registerCommand('runtimeLens.copyInstallCommand', () => copyInstallCommand()),
    vscode.commands.registerCommand('runtimeLens.getStarted', () => vscode.commands.executeCommand('workbench.action.openWalkthrough', WALKTHROUGH_ID, false)),
  ];
}

async function showRecentRequests(controller: RuntimeLensController): Promise<void> {
  const items: RequestItem[] = [
    { label: 'All requests', description: 'Show data from every recorded request', batchId: null },
    ...controller.batchStore().recent(RECENT_LIMIT).map((batch) => ({
      label: `${batch.source === 'tests' ? '[test] ' : ''}${batch.name}`,
      description: `${batch.status ?? '–'} · ${Math.round(batch.duration_ms)}ms · ${batch.queries.length + batch.dropped_queries} queries`,
      batchId: batch.id,
    })),
  ];

  const picked = await vscode.window.showQuickPick(items, { placeHolder: 'Focus Runtime Lens on one request' });

  if (picked !== undefined) {
    controller.focus(picked.batchId);
  }
}

async function showSlowestMethods(controller: RuntimeLensController): Promise<void> {
  const root = controller.root();

  if (root === null) {
    void vscode.window.showInformationMessage('Runtime Lens has no Laravel project data yet.');

    return;
  }

  const ranked: MethodItem[] = slowestMethods(controller.methodStats(), SLOWEST_LIMIT).map((method) => ({
    label: method.name,
    description: `avg ${formatMs(method.ms)}ms · ${method.measure} (${method.count})`,
    detail: method.file,
    file: method.file,
    line: method.line,
    name: method.name,
    ms: method.ms,
  }));

  const ioMethods = await vscode.window.withProgress(
    { location: vscode.ProgressLocation.Notification, title: 'Runtime Lens: ranking methods' },
    () => ioRankedMethods(controller, root, ranked),
  );

  ranked.push(...ioMethods);
  ranked.sort((left, right) => right.ms - left.ms);

  const picked = await vscode.window.showQuickPick(ranked.slice(0, SLOWEST_LIMIT), { placeHolder: 'Slowest methods (average per request)' });

  if (picked !== undefined) {
    await openMethod(root, picked);
  }
}

async function ioRankedMethods(controller: RuntimeLensController, root: string, alreadyRanked: MethodItem[]): Promise<MethodItem[]> {
  const thresholds = controller.settings().thresholds;
  const items: MethodItem[] = [];

  for (const relativeFile of busiestFiles(controller.index(), thresholds)) {
    const uri = vscode.Uri.file(join(root, relativeFile));
    const document = await openDocument(uri);
    const anchored = document === null ? null : controller.anchoredFor(document);

    if (anchored === null) {
      continue;
    }

    const ioLines = oneBasedLines(anchored.lines);

    for (const symbol of await methodSymbolsFor(uri)) {
      if (isRanked(alreadyRanked, relativeFile, symbol)) {
        continue;
      }

      const io = ioForRange(ioLines, symbol.startLine + 1, symbol.endLine + 1, thresholds);
      const ms = io.dbMs + io.httpMs;
      const name = symbol.className === null ? symbol.methodName : `${symbol.className}::${symbol.methodName}`;

      if (ms > 0) {
        items.push({ label: name, description: `avg ${formatMs(ms)}ms · DB + HTTP`, detail: relativeFile, file: relativeFile, line: symbol.startLine + 1, name, ms });
      }
    }
  }

  return items;
}

function busiestFiles(index: Map<string, FileLines>, thresholds: Thresholds): string[] {
  return [...index]
    .filter(([relativeFile]) => !relativeFile.endsWith('.blade.php'))
    .map(([relativeFile, fileLines]) => ({ relativeFile, ms: totalIoMs(fileLines, thresholds) }))
    .sort((left, right) => right.ms - left.ms)
    .slice(0, FILES_TO_SCAN)
    .map(({ relativeFile }) => relativeFile);
}

function totalIoMs(fileLines: FileLines, thresholds: Thresholds): number {
  let total = 0;

  for (const stats of fileLines.values()) {
    const findings = lineFindings(stats, thresholds);
    total += (findings.normal?.ms ?? 0) + findings.httpMsPerBatch;
  }

  return total;
}

function isRanked(ranked: MethodItem[], relativeFile: string, symbol: MethodSymbol): boolean {
  return ranked.some((item) => item.file === relativeFile && findMethod([symbol], item.name) !== null);
}

async function openDocument(uri: vscode.Uri): Promise<vscode.TextDocument | null> {
  try {
    return await vscode.workspace.openTextDocument(uri);
  } catch {
    return null;
  }
}

async function openMethod(root: string, item: MethodItem): Promise<void> {
  const uri = vscode.Uri.file(join(root, item.file));
  const document = await openDocument(uri);

  if (document === null) {
    return;
  }

  const symbol = findMethod(await methodSymbolsFor(uri), item.name);
  const line = symbol?.startLine ?? Math.max(0, (item.line ?? 1) - 1);
  const position = new vscode.Position(line, 0);
  const editor = await vscode.window.showTextDocument(document);

  editor.selection = new vscode.Selection(position, position);
  editor.revealRange(new vscode.Range(position, position), vscode.TextEditorRevealType.InCenter);
}

async function toggleInline(): Promise<void> {
  const configuration = vscode.workspace.getConfiguration(SECTION);
  const inspected = configuration.inspect<boolean>('inline');
  const target = inspected?.workspaceValue !== undefined ? vscode.ConfigurationTarget.Workspace : vscode.ConfigurationTarget.Global;

  await configuration.update('inline', !configuration.get<boolean>('inline', true), target);
}

function clearData(controller: RuntimeLensController): void {
  try {
    controller.clearData();
  } catch (error) {
    const reason = error instanceof Error ? error.message : String(error);

    void vscode.window.showErrorMessage(`Runtime Lens could not clear its data (${reason}). If the file belongs to another user, for example a Docker container, delete storage/runtime-lens/batches*.jsonl there.`);
  }
}

async function copyInstallCommand(): Promise<void> {
  await vscode.env.clipboard.writeText(INSTALL_COMMAND);
  void vscode.window.showInformationMessage('Copied. Run it in your Laravel project folder.');
}
