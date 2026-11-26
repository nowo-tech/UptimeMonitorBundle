/**
 * Bundle logger (same API/colors as TwigInspectorBundle {@link ../TwigInspectorBundle logger.ts}).
 */

export type BundleLoggerOptions = {
  buildTime?: string;
  /** When true, debug/info/warn/error always output. */
  alwaysLog?: boolean;
};

export type BundleLogger = {
  scriptLoaded: () => void;
  setDebug: (enabled: boolean) => void;
  debug: (...args: unknown[]) => void;
  info: (...args: unknown[]) => void;
  warn: (...args: unknown[]) => void;
  error: (...args: unknown[]) => void;
};

/** True when the page asked for a silent console (`<html data-nowo-console="quiet">` or `globalThis.NOWO_CONSOLE = 'quiet'`). */
function isQuiet(): boolean {
  try {
    if ((globalThis as { NOWO_CONSOLE?: unknown }).NOWO_CONSOLE === 'quiet') return true;
    return typeof document !== 'undefined' && document.documentElement.dataset.nowoConsole === 'quiet';
  } catch {
    return false;
  }
}

const STYLES = {
  script: 'color:#0ea5e9;font-weight:bold',
  debug: 'color:#6b7280',
  info: 'color:#2563eb',
  warn: 'color:#d97706',
  error: 'color:#dc2626;font-weight:bold',
} as const;

const EMOJI = {
  script: '📦',
  debug: '🔍',
  info: 'ℹ️',
  warn: '⚠️',
  error: '❌',
} as const;

function formatArgs(args: unknown[]): unknown[] {
  return args.map((a) =>
    typeof a === 'object' && a !== null && !(a instanceof Error) ? JSON.stringify(a) : a,
  );
}

type ConsoleLevel = 'debug' | 'info' | 'warn' | 'error';

function logScriptLoaded(prefix: string, buildTime: string | undefined): void {
  if (buildTime !== undefined && buildTime !== '') {
    console.log(
      `%c${EMOJI.script} ${prefix} script loaded, build: %c${buildTime}`,
      STYLES.script,
      'color:#059669',
    );
    return;
  }
  console.log(`%c${EMOJI.script} ${prefix} script loaded`, STYLES.script);
}

function emitLevelLog(level: ConsoleLevel, prefix: string, args: unknown[]): void {
  const emoji = EMOJI[level];
  const style = STYLES[level];
  const label = `%c${emoji} ${prefix}`;
  const logFn = console[level] as (...fnArgs: unknown[]) => void;
  if (args.length > 0) {
    logFn(label, style, ...formatArgs(args));
    return;
  }
  logFn(label, style);
}

function makeLevelMethod(
  logAlways: boolean,
  prefix: string,
  level: ConsoleLevel,
): (...args: unknown[]) => void {
  return (...args: unknown[]): void => {
    if (!logAlways) {
      return;
    }
    emitLevelLog(level, prefix, args);
  };
}

function noop(): void {}

let instance: BundleLogger | null = null;

export function setBundleLogger(log: BundleLogger): void {
  instance = log;
}

export function clearBundleLoggerForTest(): void {
  instance = null;
}

export function getLogger(): BundleLogger {
  if (instance !== null) {
    return instance;
  }
  return {
    scriptLoaded: noop,
    setDebug: noop,
    debug: noop,
    info: noop,
    warn: noop,
    error: noop,
  };
}

export function createBundleLogger(name: string, options: BundleLoggerOptions = {}): BundleLogger {
  const prefix = `[${name}]`;
  const { buildTime, alwaysLog = false } = options;
  const logAlways = alwaysLog === true;

  return {
    scriptLoaded(): void {
      if (isQuiet()) return;
      if (alreadyAnnounced(name)) return;
      logScriptLoaded(prefix, buildTime);
    },

    setDebug(_enabled: boolean): void {
      /* no-op when alwaysLog */
    },

    debug: makeLevelMethod(logAlways, prefix, 'debug'),
    info: makeLevelMethod(logAlways, prefix, 'info'),
    warn: makeLevelMethod(logAlways, prefix, 'warn'),
    error: makeLevelMethod(logAlways, prefix, 'error'),
  };
}

/**
 * Records `name` in a page-wide `Set` on `globalThis` and tells whether it was announced before (REQ-ASSETS-005): a
 * script that is evaluated twice (Turbo, a fragment, two copies, two loggers with the same name) prints its
 * "script loaded" line once per page. Page HTML that clobbers the global (DOM named access) is replaced by a fresh set.
 *
 * @param name - Logger name in kebab-case, e.g. `icon-selector` or `ui-kit/dialog`.
 * @returns True when the line was already printed on this page.
 */
function alreadyAnnounced(name: string): boolean {
  const host = globalThis as { __nowoScriptBannerNames?: unknown };
  if (!(host.__nowoScriptBannerNames instanceof Set)) {
    host.__nowoScriptBannerNames = new Set<string>();
  }
  const seen = host.__nowoScriptBannerNames as Set<string>;
  if (seen.has(name)) {
    return true;
  }
  seen.add(name);

  return false;
}
