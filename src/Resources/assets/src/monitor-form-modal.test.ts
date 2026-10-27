import { afterEach, describe, expect, it, vi } from 'vitest';
import { initMonitorFormModal, initMonitorFormTypeToggle } from './monitor-form-modal';

const FORM_HTML = `
<form data-monitor-form="1" action="/main/monitors/new" method="post">
  <select data-monitor-type-select><option value="http">http</option><option value="dns" selected>dns</option></select>
  <fieldset data-monitor-fields="http"></fieldset>
  <fieldset data-monitor-fields="dns"></fieldset>
  <button type="submit">Save</button>
</form>`;

/** A fresh <body>: initMonitorFormModal binds its opener listener on document.body. */
function freshBody(): void {
  document.documentElement.replaceChild(document.createElement('body'), document.body);
}

function mountDialog(): HTMLDialogElement {
  freshBody();
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
    freshBody();
    document.body.innerHTML = '<a href="/x" data-monitor-form-open data-monitor-form-url="/x">x</a>';

    expect(() => initMonitorFormModal(document)).not.toThrow();
  });

  it('posts the form: JSON success closes the dialog, flashes and refreshes the dashboard', async () => {
    const dialog = mountDialog();
    dialog.showModal = vi.fn(function (this: HTMLDialogElement) { this.setAttribute('open', ''); });
    dialog.close = vi.fn();
    document.body.insertAdjacentHTML('afterbegin', '<main id="nowo-ui-content"></main><div id="uptime-dashboard-root" data-layout-fragment=""></div>');
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(new Response(FORM_HTML, { headers: { 'Content-Type': 'text/html' } }))
      .mockResolvedValueOnce(new Response(JSON.stringify({ ok: true, message: 'Saved.' }), { headers: { 'Content-Type': 'application/json' } }));
    vi.stubGlobal('fetch', fetchMock);

    initMonitorFormModal(document);
    document.querySelector<HTMLElement>('[data-monitor-form-open]')?.click();
    await flush();
    dialog.querySelector('form')?.dispatchEvent(new Event('submit', { cancelable: true }));
    await vi.waitFor(() => expect(dialog.close).toHaveBeenCalled());

    expect(fetchMock).toHaveBeenLastCalledWith(expect.stringContaining('/main/monitors/new'), expect.objectContaining({ method: 'POST' }));
    expect(document.querySelector('#nowo-ui-content .nowo-ui-flash--success')?.textContent).toBe('Saved.');
  });

  it('reloads the page when the server asks to return to the detail page', async () => {
    const dialog = mountDialog();
    dialog.showModal = vi.fn();
    dialog.close = vi.fn();
    const reload = vi.fn();
    vi.stubGlobal('location', { ...window.location, reload });
    vi.stubGlobal('fetch', vi.fn()
      .mockResolvedValueOnce(new Response(FORM_HTML, { headers: { 'Content-Type': 'text/html' } }))
      .mockResolvedValueOnce(new Response(JSON.stringify({ ok: true, return: 'show' }), { headers: { 'Content-Type': 'application/json' } })));

    initMonitorFormModal(document);
    document.querySelector<HTMLElement>('[data-monitor-form-open]')?.click();
    await flush();
    dialog.querySelector('form')?.dispatchEvent(new Event('submit', { cancelable: true }));
    await vi.waitFor(() => expect(reload).toHaveBeenCalledOnce());
  });

  it('re-renders the form on validation errors and flashes on failures', async () => {
    const dialog = mountDialog();
    dialog.showModal = vi.fn();
    document.body.insertAdjacentHTML('afterbegin', '<main id="nowo-ui-content"></main>');
    const invalid = FORM_HTML.replace('Save', 'Save again');
    vi.stubGlobal('fetch', vi.fn()
      .mockResolvedValueOnce(new Response(FORM_HTML, { headers: { 'Content-Type': 'text/html' } }))
      .mockResolvedValueOnce(new Response(invalid, { status: 422, headers: { 'Content-Type': 'text/html' } }))
      .mockResolvedValueOnce(new Response('oops', { status: 500, headers: { 'Content-Type': 'text/plain' } }))
      .mockRejectedValueOnce(new Error('offline')));

    initMonitorFormModal(document);
    document.querySelector<HTMLElement>('[data-monitor-form-open]')?.click();
    await flush();
    dialog.querySelector('form')?.dispatchEvent(new Event('submit', { cancelable: true }));
    await vi.waitFor(() => expect(dialog.textContent).toContain('Save again'));

    dialog.querySelector('form')?.dispatchEvent(new Event('submit', { cancelable: true }));
    await vi.waitFor(() => expect(document.querySelectorAll('.nowo-ui-flash--error')).toHaveLength(1));

    dialog.querySelector('form')?.dispatchEvent(new Event('submit', { cancelable: true }));
    await vi.waitFor(() => expect(document.querySelectorAll('.nowo-ui-flash--error')).toHaveLength(2));
    expect(dialog.querySelector<HTMLButtonElement>('button[type="submit"]')?.disabled).toBe(false);
  });

  it('shows an error when the form cannot be loaded and ignores openers without URL', async () => {
    const dialog = mountDialog();
    dialog.showModal = vi.fn(function (this: HTMLDialogElement) { this.setAttribute('open', ''); });
    const fetchMock = vi.fn().mockResolvedValue(new Response('', { status: 500 }));
    vi.stubGlobal('fetch', fetchMock);

    initMonitorFormModal(document);
    const opener = document.querySelector<HTMLElement>('[data-monitor-form-open]') as HTMLElement;
    opener.dataset.monitorFormTitle = 'Explicit';
    opener.dataset.monitorFormReturn = 'reload';
    opener.click();
    await flush();
    expect(dialog.querySelector('[data-monitor-form-body] .nowo-ui-flash--error')?.textContent).toBe('Could not load monitor form.');
    expect(dialog.querySelector('[data-monitor-form-title]')?.textContent).toBe('Explicit');

    opener.click();
    await flush();
    expect(dialog.showModal).toHaveBeenCalledOnce();

    opener.dataset.monitorFormUrl = '';
    opener.click();
    await flush();
    expect(fetchMock).toHaveBeenCalledTimes(2);
  });

  it('keeps the dashboard edit button in sync with the events filter', () => {
    mountDialog();
    document.body.insertAdjacentHTML('beforeend', `
      <div id="uptime-dashboard-root" data-events-filter="" data-monitor-form-url-template="/main/monitors/__ID__/edit">
        <button type="button" data-events-edit-monitor data-monitor-form-open></button>
      </div>`);
    const root = document.getElementById('uptime-dashboard-root') as HTMLElement;
    const button = root.querySelector<HTMLElement>('[data-events-edit-monitor]') as HTMLElement;

    initMonitorFormModal(document);
    expect(button.hidden).toBe(true);

    root.dataset.eventsFilter = '4';
    root.dispatchEvent(new CustomEvent('uptime:events-filter-changed'));
    expect(button.hidden).toBe(false);
    expect(button.dataset.monitorFormUrl).toBe('/main/monitors/4/edit');

    root.dataset.monitorFormUrlTemplate = '';
    root.dispatchEvent(new CustomEvent('uptime:events-filter-changed'));
    expect(button.hidden).toBe(true);

    button.remove();
    expect(() => root.dispatchEvent(new CustomEvent('uptime:events-filter-changed'))).not.toThrow();
  });

  it('ignores an element that is not a native dialog and clicks outside openers', () => {
    freshBody();
    document.body.innerHTML = '<div data-uptime-monitor-form-modal><div data-monitor-form-body></div></div>';
    expect(() => initMonitorFormModal(document)).not.toThrow();
    const dialog = mountDialog();
    dialog.showModal = vi.fn();
    initMonitorFormModal(document);
    document.body.click();
    dialog.click();
    expect(dialog.showModal).not.toHaveBeenCalled();
  });
});
