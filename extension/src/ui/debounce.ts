export function debounce(work: () => void, delayMs: number): { schedule: () => void; cancel: () => void } {
  let timer: NodeJS.Timeout | undefined;

  return {
    schedule: () => {
      clearTimeout(timer);
      timer = setTimeout(work, delayMs);
    },
    cancel: () => clearTimeout(timer),
  };
}
