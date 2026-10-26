import { afterEach, describe, expect, it, vi } from 'vitest';
import { initMonitorFormModal, initMonitorFormTypeToggle } from './monitor-form-modal';

const FORM_HTML = `
<form data-monitor-form="1" action="/main/monitors/new" method="post">
  <select data-monitor-type-select><option value="http">http</option><option value="dns" selected>dns</option></select>
  <fieldset data-monitor-fields="http"></fieldset>
  <fieldset data-monitor-fields="dns"></fieldset>
  <button type="submit">Save</button>
</form>`;

function mountDialog(): HTMLDialogElement {
  document.body.innerHTML = `
    <a href="/main/monitors/new" data-monitor-form-open data-monitor-form-url="/main/monitors/new" data-monitor-form-return="dashboard">New monitor</a>
    <dialog id="uptime-monitor-form-modal" data-uptime-monitor-form-modal>
      <header><h2 data-monitor-form-title>Edit monitor</h2><button type="button" data-nowo-ui-dialog-close>×</button></header>
      <div data-monitor-form-body><p>Loading form…</p></div>
    </dialog>`;

  return document.querySelector('dialog') as HTMLDialogElement;
}

async function flush(): Promise<void> {
  for (let i = 0; i < 5; i += 1) {
    await Promise.resolve();
  }
}

describe('monitor form dialog', () => {
  afterEach(() => {
    vi.restoreAllMocks();
    document.body.innerHTML = '';
  });

  it('toggles the fieldsets of the selected monitor type', () => {
    document.body.innerHTML = FORM_HTML;
    initMonitorFormTypeToggle(document);

    expect(document.querySelector<HTMLElement>('[data-monitor-fields="http"]')?.hidden).toBe(true);
    expect(document.querySelector<HTMLElement>('[data-monitor-fields="dns"]')?.hidden).toBe(false);
  });

  it('opens the native dialog, loads the form fragment and takes the opener label as title', async () => {
    const dialog = mountDialog();
    const showModal = vi.fn(function (this: HTMLDialogElement) {
      this.setAttribute('open', '');
    });
    dialog.showModal = showModal;
    dialog.close = vi.fn(function (this: HTMLDialogElement) {
      this.removeAttribute('open');
    });
    const fetchMock = vi.fn().mockResolvedValue(new Response(FORM_HTML, { status: 200, headers: { 'Content-Type': 'text/html' } }));
    vi.stubGlobal('fetch', fetchMock);

    initMonitorFormModal(document);
    document.querySelector<HTMLElement>('[data-monitor-form-open]')?.click();
    await flush();

    expect(showModal).toHaveBeenCalledOnce();
    expect(fetchMock).toHaveBeenCalledWith('/main/monitors/new', expect.objectContaining({ headers: expect.objectContaining({ 'X-Uptime-Modal': '1', 'X-Uptime-Return': 'dashboard' }) }));
    expect(document.querySelector('[data-monitor-form-title]')?.textContent).toBe('New monitor');
    expect(dialog.querySelector('[data-monitor-form-body] form[data-monitor-form]')).not.toBeNull();

    dialog.querySelector<HTMLElement>('[data-nowo-ui-dialog-close]')?.click();
    expect(dialog.close).toHaveBeenCalled();
  });

  it('does nothing without the dialog', () => {
    document.body.innerHTML = '<a href="/x" data-monitor-form-open data-monitor-form-url="/x">x</a>';

    expect(() => initMonitorFormModal(document)).not.toThrow();
  });
});
