import * as vscode from 'vscode';
import { formatMs, lineFindings } from '../core/findings';
import type { LineStats } from '../core/lineIndex';
import type { Thresholds } from '../core/settings';
import type { RuntimeLensController } from './controller';

const MAX_BATCH_NAMES = 5;

export class LensHoverProvider implements vscode.HoverProvider {
  constructor(private readonly controller: RuntimeLensController) {}

  provideHover(document: vscode.TextDocument, position: vscode.Position): vscode.Hover | null {
    if (!this.controller.settings().enabled) {
      return null;
    }

    const stats = this.controller.anchoredFor(document)?.lines.get(position.line);

    return stats === undefined ? null : new vscode.Hover(hoverMarkdown(stats, this.controller.settings().thresholds));
  }
}

function hoverMarkdown(stats: LineStats, thresholds: Thresholds): vscode.MarkdownString {
  const markdown = new vscode.MarkdownString('**Runtime Lens**\n\n');
  const findings = lineFindings(stats, thresholds);
  const batchNames = new Set<string>();

  for (const query of [...stats.queries.values()].sort((left, right) => right.totalMs - left.totalMs)) {
    const flags = [
      query.worstBatch.count >= thresholds.nPlusOneThreshold ? `⚠ N+1 (${query.worstBatch.count}× in ${query.worstBatch.kind} ${query.worstBatch.name})` : '',
      query.maxMs >= thresholds.slowQueryMs ? '🐢 slow' : '',
    ].filter(Boolean).join(' · ');

    markdown.appendCodeblock(query.sample.sql, 'sql');
    markdown.appendMarkdown(`${bindingsText(query.sample.bindings)}ran ${query.executions}× · avg ${formatMs(query.totalMs / query.executions)}ms · max ${formatMs(query.maxMs)}ms${flags ? ` · ${flags}` : ''}\n\n`);
    query.batchNames.forEach((name) => batchNames.add(name));
  }

  for (const call of stats.http.values()) {
    const average = call.calls > call.failures ? formatMs(call.totalMs / Math.max(1, call.calls - call.failures)) : '–';
    const flags = [call.maxMs >= thresholds.slowHttpMs ? '🌐 slow' : '', call.failures > 0 ? `${call.failures} failed` : ''].filter(Boolean).join(' · ');

    markdown.appendMarkdown(`🌐 \`${call.sample.method} ${call.sample.url}\` · status ${call.sample.status ?? 'failed'} · ${call.calls} call(s) · avg ${average}ms · max ${formatMs(call.maxMs)}ms${flags ? ` · ${flags}` : ''}\n\n`);
    call.batchNames.forEach((name) => batchNames.add(name));
  }

  for (const [className, exception] of stats.exceptions) {
    markdown.appendMarkdown(`✖ **${escapeMarkdown(className)}**: ${escapeMarkdown(exception.lastMessage)} (×${exception.count}, last ${exception.lastSeen})\n\n`);
    exception.batchNames.forEach((name) => batchNames.add(name));
  }

  if (stats.profile !== null && stats.profile.batches > 0) {
    markdown.appendMarkdown(`🔥 avg ${formatMs(stats.profile.totalMs / stats.profile.batches)}ms · ${Math.round(stats.profile.percentSum / stats.profile.batches)}% of request\n\n`);
  }

  if (findings.normal !== null) {
    markdown.appendMarkdown(`Per request: ${formatMs(findings.normal.queries)} queries · ${formatMs(findings.normal.ms)}ms\n\n`);
  }

  if (batchNames.size > 0) {
    markdown.appendMarkdown(`Recent runs: ${[...batchNames].slice(-MAX_BATCH_NAMES).reverse().map((name) => `\`${name}\``).join(', ')}`);
  }

  return markdown;
}

function bindingsText(bindings: unknown[] | undefined): string {
  return bindings === undefined || bindings.length === 0 ? '' : `bindings \`${JSON.stringify(bindings).replace(/`/g, "'")}\` · `;
}

function escapeMarkdown(text: string): string {
  return text.replace(/[\\`*_{}[\]()#+\-.!|<>]/g, '\\$&');
}
