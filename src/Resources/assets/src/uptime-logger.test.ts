import { afterEach, describe, expect, it, vi } from 'vitest';
import { clearBundleLoggerForTest, createBundleLogger, getLogger, setBundleLogger } from './uptime-logger';

describe('uptime-logger', () => {
  afterEach(() => {
    clearBundleLoggerForTest();
    vi.restoreAllMocks();
  });

  it('scriptLoaded uses colored prefix and build id', () => {
    const logSpy = vi.spyOn(console, 'log').mockImplementation(() => {});
    createBundleLogger('uptime', { buildTime: '2026-01-01T00:00:00.000Z', alwaysLog: true }).scriptLoaded();

    expect(logSpy).toHaveBeenCalledWith(
      expect.stringContaining('%c📦 [uptime] script loaded, build: %c'),
      'color:#0ea5e9;font-weight:bold',
      'color:#059669',
    );
  });

  it('info uses blue styled label', () => {
    const infoSpy = vi.spyOn(console, 'info').mockImplementation(() => {});
    const log = createBundleLogger('uptime', { alwaysLog: true });
    log.info('Mercure connected.', { topic: '/uptime/main' });

    expect(infoSpy).toHaveBeenCalledWith(
      '%cℹ️ [uptime]',
      'color:#2563eb',
      'Mercure connected.',
      '{"topic":"/uptime/main"}',
    );
  });

  it('getLogger returns no-op before setBundleLogger', () => {
    const debugSpy = vi.spyOn(console, 'debug').mockImplementation(() => {});
    getLogger().debug('hidden');
    expect(debugSpy).not.toHaveBeenCalled();
  });

  it('setBundleLogger registers instance', () => {
    const log = createBundleLogger('uptime', { alwaysLog: true });
    setBundleLogger(log);
    const warnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});
    getLogger().warn('test');
    expect(warnSpy).toHaveBeenCalledWith('%c⚠️ [uptime]', 'color:#d97706', 'test');
  });

  it('logs without build id or arguments, and stays silent unless alwaysLog', () => {
    const logSpy = vi.spyOn(console, 'log').mockImplementation(() => {});
    const errorSpy = vi.spyOn(console, 'error').mockImplementation(() => {});
    const loud = createBundleLogger('uptime', { alwaysLog: true });
    loud.scriptLoaded();
    loud.setDebug(true);
    loud.error();
    loud.error(new Error('x'), 'plain');
    expect(logSpy).toHaveBeenCalledWith('%c📦 [uptime] script loaded', 'color:#0ea5e9;font-weight:bold');
    expect(errorSpy).toHaveBeenCalledWith('%c❌ [uptime]', 'color:#dc2626;font-weight:bold');
    expect(errorSpy).toHaveBeenCalledTimes(2);

    createBundleLogger('uptime').error('quiet');
    expect(errorSpy).toHaveBeenCalledTimes(2);
  });

  it('the default logger is a no-op for every method', () => {
    const log = getLogger();
    expect(() => {
      log.scriptLoaded();
      log.setDebug(true);
      log.info('x');
      log.warn('x');
      log.error('x');
    }).not.toThrow();
  });
});
