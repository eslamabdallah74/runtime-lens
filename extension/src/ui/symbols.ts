import * as vscode from 'vscode';
import type { MethodSymbol } from '../core/anchor';

const CONTAINER_KINDS = new Set([
  vscode.SymbolKind.Class,
  vscode.SymbolKind.Interface,
  vscode.SymbolKind.Enum,
  vscode.SymbolKind.Struct,
  vscode.SymbolKind.Namespace,
  vscode.SymbolKind.Module,
]);

const CALLABLE_KINDS = new Set([vscode.SymbolKind.Method, vscode.SymbolKind.Function, vscode.SymbolKind.Constructor]);

export async function methodSymbolsFor(uri: vscode.Uri): Promise<MethodSymbol[]> {
  let symbols: Array<vscode.DocumentSymbol | vscode.SymbolInformation> | undefined;

  try {
    symbols = await vscode.commands.executeCommand('vscode.executeDocumentSymbolProvider', uri);
  } catch {
    return [];
  }

  if (symbols === undefined || symbols.length === 0) {
    return [];
  }

  const methods: MethodSymbol[] = [];

  for (const symbol of symbols) {
    if ('children' in symbol) {
      collectDocumentSymbol(symbol, null, methods);
    } else {
      collectSymbolInformation(symbol, methods);
    }
  }

  return methods;
}

function collectDocumentSymbol(symbol: vscode.DocumentSymbol, className: string | null, methods: MethodSymbol[]): void {
  if (CALLABLE_KINDS.has(symbol.kind)) {
    methods.push({ className, methodName: cleanName(symbol.name), startLine: symbol.range.start.line, endLine: symbol.range.end.line });

    return;
  }

  const childClassName = isClassLike(symbol) ? cleanName(symbol.name) : className;

  for (const child of symbol.children) {
    collectDocumentSymbol(child, childClassName, methods);
  }
}

function collectSymbolInformation(symbol: vscode.SymbolInformation, methods: MethodSymbol[]): void {
  if (!CALLABLE_KINDS.has(symbol.kind)) {
    return;
  }

  const className = symbol.containerName === '' ? null : cleanName(symbol.containerName);
  const { start, end } = symbol.location.range;

  methods.push({ className, methodName: cleanName(symbol.name), startLine: start.line, endLine: end.line });
}

function isClassLike(symbol: vscode.DocumentSymbol): boolean {
  return CONTAINER_KINDS.has(symbol.kind) && symbol.kind !== vscode.SymbolKind.Namespace && symbol.kind !== vscode.SymbolKind.Module;
}

function cleanName(name: string): string {
  const withoutParameters = name.split('(')[0] ?? name;

  return (withoutParameters.split('\\').pop() ?? withoutParameters).trim();
}
