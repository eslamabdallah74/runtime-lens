export interface Thresholds {
  nPlusOneThreshold: number;
  slowQueryMs: number;
  slowHttpMs: number;
  hotLinePercent: number;
}

export const DEFAULT_THRESHOLDS: Thresholds = {
  nPlusOneThreshold: 3,
  slowQueryMs: 100,
  slowHttpMs: 1000,
  hotLinePercent: 10,
};
