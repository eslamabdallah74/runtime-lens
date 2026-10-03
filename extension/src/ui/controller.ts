import { existsSync, readdirSync, rmSync, truncateSync, unwatchFile, watchFile } from 'node:fs';
import { isAbsolute, join, relative } from 'node:path';
import * as vscode from 'vscode';
import { anchorFile, type AnchoredLines } from '../core/anchor';
import { buildLineIndex, type LineIndex } from '../core/lineIndex';
import { buildMethodStats, type MethodStats } from '../core/methodIndex';
import { BatchStore, latestRunPerName } from '../core/store';
import { BatchFileTail } from '../core/tail';
import { CURRENT_FILE, ROTATED_FILE, type ParsedLines } from '../core/types';
import { debounce } from './debounce';
import type { LensSettings } from './settings';

const STORAGE_DIR = join('storage', 'runtime-lens');
const POLL_INTERVAL_MS = 500;
const REBUILD_DELAY_MS = 250;
const SKIPPED_FOLDERS = new Set(['node_modules', 'vendor', 'storage']);

export interface AnchoredFile extends AnchoredLines {
  relativeFile: string;
}

interface CachedAnchors {
  version: number;
  indexVersion: number;
  anchored: AnchoredFile | null;
}

export class RuntimeLensController implements vscode.Disposable {
  private readonly updateEmitter = new vscode.EventEmitter<void>();
  readonly onDidUpdate = this.updateEmitter.event;

  private readonly store: BatchStore;
  private readonly rebuild = debounce(() => this.rebuildIndexes(), REBUILD_DELAY_MS);
  private readonly anchorCache = new Map<string, CachedAnchors>();
  private projectRoot: string | null = null;
  private tail: BatchFileTail | null = null;
  private lineIndex: LineIndex = new Map();
  private methods = new Map<string, MethodStats>();
  private indexVersion = 0;
  private unknownVersionReported = false;
  private readError: string | null = null;

  constructor(
    private currentSettings: LensSettings,
    private readonly output: vscode.OutputChannel,
  ) {
    this.store = new BatchStore(currentSettings.window);
  }

  start(): void {
    if (!this.currentSettings.enabled) {
      this.fireUpdate();

      return;
    }

    this.projectRoot = this.resolveProjectRoot();

    if (this.projectRoot === null) {
      this.output.appendLine('No Laravel project (artisan file) found in this workspace. Set runtimeLens.projectRoot if it lives in a subfolder.');
      this.fireUpdate();

      return;
    }

    this.tail = new BatchFileTail(join(this.projectRoot, STORAGE_DIR));
    this.output.appendLine(`Reading ${this.tail.currentFile()}`);
    this.replaceAll();
    watchFile(this.tail.currentFile(), { interval: POLL_INTERVAL_MS }, () => this.readAppended());
  }

  applySettings(settings: LensSettings): void {
    const restart = settings.enabled !== this.currentSettings.enabled || settings.projectRoot !== this.currentSettings.projectRoot;
    const windowGrew = settings.window > this.currentSettings.window;

    this.currentSettings = settings;
    this.store.resize(settings.window);

    if (restart) {
      this.stop();
      this.start();

      return;
    }

    if (windowGrew && this.tail !== null) {
      this.replaceAll();

      return;
    }

    this.rebuildIndexes();
  }

  settings(): LensSettings {
    return this.currentSettings;
  }

  batchStore(): BatchStore {
    return this.store;
  }

  index(): LineIndex {
    return this.lineIndex;
  }

  methodStats(): Map<string, MethodStats> {
    return this.methods;
  }

  root(): string | null {
    return this.projectRoot;
  }

  readProblem(): string | null {
    return this.readError;
  }

  focus(batchId: string | null): void {
    this.store.focus(batchId);
    this.rebuildIndexes();
  }

  clearData(): void {
    if (this.projectRoot === null || this.tail === null) {
      return;
    }

    const storageDir = join(this.projectRoot, STORAGE_DIR);
    const currentFile = join(storageDir, CURRENT_FILE);

    if (existsSync(currentFile)) {
      truncateSync(currentFile);
    }

    rmSync(join(storageDir, ROTATED_FILE), { force: true });
    this.replaceAll();
  }

  relativePathOf(uri: vscode.Uri): string | null {
    if (this.projectRoot === null || uri.scheme !== 'file') {
      return null;
    }

    const relativeFile = relative(this.projectRoot, uri.fsPath);

    return relativeFile.startsWith('..') || isAbsolute(relativeFile) ? null : relativeFile.split('\\').join('/');
  }

  hasDataFor(document: vscode.TextDocument): boolean {
    const relativeFile = this.relativePathOf(document.uri);

    return relativeFile !== null && this.lineIndex.has(relativeFile);
  }

  anchoredFor(document: vscode.TextDocument): AnchoredFile | null {
    const relativeFile = this.relativePathOf(document.uri);

    if (relativeFile === null) {
      return null;
    }

    const key = document.uri.toString();
    const cached = this.anchorCache.get(key);

    if (cached !== undefined && cached.version === document.version && cached.indexVersion === this.indexVersion) {
      return cached.anchored;
    }

    const anchored = this.anchoredForText(relativeFile, document.getText());
    this.anchorCache.set(key, { version: document.version, indexVersion: this.indexVersion, anchored });

    return anchored;
  }

  anchoredForText(relativeFile: string, text: string): AnchoredFile | null {
    const fileLines = this.lineIndex.get(relativeFile);

    if (fileLines === undefined) {
      return null;
    }

    return { relativeFile, ...anchorFile(fileLines, text.split(/\r?\n/)) };
  }

  forgetDocument(document: vscode.TextDocument): void {
    this.anchorCache.delete(document.uri.toString());
  }

  dispose(): void {
    this.stop();
    this.updateEmitter.dispose();
  }

  private resolveProjectRoot(): string | null {
    const folders = vscode.workspace.workspaceFolders ?? [];

    if (this.currentSettings.projectRoot !== '' && folders[0] !== undefined) {
      return join(folders[0].uri.fsPath, this.currentSettings.projectRoot);
    }

    const paths = folders.map((folder) => folder.uri.fsPath);
    const atRoot = paths.find((folder) => existsSync(join(folder, 'artisan')));

    if (atRoot !== undefined) {
      return atRoot;
    }

    const nested = paths.flatMap((folder) => nestedLaravelApps(folder));

    return nested.length === 1 ? nested[0]! : null;
  }

  private replaceAll(): void {
    try {
      const parsed = this.tail!.readInitial();

      this.readError = null;
      this.logSkipped(parsed);
      this.store.replace(parsed.batches);
    } catch (error) {
      this.reportReadError(error);
      this.store.replace([]);
    }

    this.rebuildIndexes();
  }

  private readAppended(): void {
    if (this.tail === null) {
      return;
    }

    try {
      this.applyAppended(this.tail.readNew());
    } catch (error) {
      this.reportReadError(error);
      this.fireUpdate();
    }
  }

  private applyAppended(appended: ParsedLines | 'reset'): void {
    if (appended === 'reset') {
      this.replaceAll();

      return;
    }

    this.readError = null;
    this.logSkipped(appended);

    if (appended.batches.length > 0) {
      this.store.append(appended.batches);
      this.rebuild.schedule();
    }
  }

  private reportReadError(error: unknown): void {
    const message = error instanceof Error ? error.message : String(error);

    if (message !== this.readError) {
      this.output.appendLine(`Could not read the data file: ${message}`);
    }

    this.readError = message;
  }

  private logSkipped(parsed: ParsedLines): void {
    if (parsed.malformed > 0) {
      this.output.appendLine(`Skipped ${parsed.malformed} malformed line(s).`);
    }

    if (parsed.unknownVersion > 0 && !this.unknownVersionReported) {
      this.unknownVersionReported = true;
      this.output.appendLine('Some lines use a newer data format than this extension understands; update the extension.');
    }
  }

  private rebuildIndexes(): void {
    const batches = this.store.inScope();

    this.lineIndex = buildLineIndex(latestRunPerName(batches));
    this.methods = buildMethodStats(batches);
    this.indexVersion++;
    this.anchorCache.clear();
    this.fireUpdate();
  }

  private fireUpdate(): void {
    this.updateEmitter.fire();
  }

  private stop(): void {
    this.rebuild.cancel();

    if (this.tail !== null) {
      unwatchFile(this.tail.currentFile());
    }

    this.tail = null;
    this.projectRoot = null;
    this.store.replace([]);
    this.lineIndex = new Map();
    this.methods = new Map();
    this.anchorCache.clear();
  }
}

function nestedLaravelApps(folder: string): string[] {
  try {
    return readdirSync(folder, { withFileTypes: true })
      .filter((entry) => entry.isDirectory() && !entry.name.startsWith('.') && !SKIPPED_FOLDERS.has(entry.name))
      .map((entry) => join(folder, entry.name))
      .filter((directory) => existsSync(join(directory, 'artisan')));
  } catch {
    return [];
  }
}
