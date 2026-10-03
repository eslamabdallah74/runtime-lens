import * as vscode from 'vscode';
import { DEFAULT_THRESHOLDS, type Thresholds } from '../core/settings';

export const SECTION = 'runtimeLens';

export interface LensSettings {
  enabled: boolean;
  projectRoot: string;
  window: number;
  inline: boolean;
  thresholds: Thresholds;
}

export function readSettings(): LensSettings {
  const configuration = vscode.workspace.getConfiguration(SECTION);

  return {
    enabled: configuration.get<boolean>('enabled', true),
    projectRoot: configuration.get<string>('projectRoot', ''),
    window: configuration.get<number>('window', 1000),
    inline: configuration.get<boolean>('inline', true),
    thresholds: {
      nPlusOneThreshold: configuration.get<number>('nPlusOneThreshold', DEFAULT_THRESHOLDS.nPlusOneThreshold),
      slowQueryMs: configuration.get<number>('slowQueryMs', DEFAULT_THRESHOLDS.slowQueryMs),
      slowHttpMs: configuration.get<number>('slowHttpMs', DEFAULT_THRESHOLDS.slowHttpMs),
      hotLinePercent: configuration.get<number>('hotLinePercent', DEFAULT_THRESHOLDS.hotLinePercent),
    },
  };
}
