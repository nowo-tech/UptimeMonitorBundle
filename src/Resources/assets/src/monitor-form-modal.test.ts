import { afterEach, describe, expect, it, vi } from 'vitest';
import { initMonitorFormModal, initMonitorFormTypeToggle } from './monitor-form-modal';

const FORM_HTML = `
<form data-monitor-form="1" action="/main/monitors/new" method="post">
  <select data-monitor-type-select><option value="http">http</option><option value="dns" selected>dns</option></select>
  <fieldset data-monitor-fields="http"></fieldset>
  <fieldset data-monitor-fields="dns"></fieldset>
  <button type="submit">Save</button>
</form>`;

/** The 1.19 fragment: an actions row the dialog moves into its footer (Cancel, then the primary Save). */
const FORM_WITH_ACTIONS = `
<form data-monitor-form="1" action="/main/monitors/new" method="post">
  <select data-monitor-type-select><option value="http" selected>http</option></select>
  <fieldset data-monitor-fields="http"><input name="url"></fieldset>
  <div class="uptime-form-actions" data-nowo-ui-dialog-actions>
    <button type="button" data-nowo-ui-dialog-close>Cancel</button>
    <button type="submit" form="uptime-monitor-form">Save</button>
  </div>
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
      <footer class="nowo-ui-dialog__footer" data-nowo-ui-dialog-footer hidden></footer>
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

  it('shows only the rows of the selected type and requires its target fields (1.18)', () => {
    document.body.innerHTML = `
      <select data-monitor-type-select>
        <option value="http">http</option><option value="push">push</option><option value="ping">ping</option>
        <option value="tcp">tcp</option><option value="dns">dns</option><option value="group">group</option>
      </select>
      <fieldset data-monitor-fields="check"><div data-monitor-fields="check-retry"></div><div data-monitor-fields="check-timeout"></div></fieldset>
      <details data-monitor-fields="http-advanced"></details><fieldset data-monitor-fields="http-options"></fieldset>
      <fieldset data-monitor-fields="tcp"><input data-required-for="tcp ping ssl" name="host"><div data-monitor-fields="tcp-port"></div></fieldset>
      <fieldset data-monitor-fields="push"></fieldset><fieldset data-monitor-fields="custom"></fieldset>
      <input data-required-for="http https" name="url"><input data-required-for="dns" name="hostname">`;
    const select = document.querySelector('select') as HTMLSelectElement;
    const hidden = (key: string): boolean | undefined => document.querySelector<HTMLElement>(`[data-monitor-fields="${key}"]`)?.hidden;
    const required = (name: string): boolean | undefined => document.querySelector<HTMLInputElement>(`[name="${name}"]`)?.required;
    initMonitorFormTypeToggle(document);

    select.value = 'push';
    select.dispatchEvent(new Event('change'));
    expect([hidden('check'), hidden('check-retry'), hidden('check-timeout'), hidden('push'), hidden('custom')]).toEqual([false, true, true, false, true]);
    expect([required('url'), required('host'), required('hostname')]).toEqual([false, false, false]);

    select.value = 'ping';
    select.dispatchEvent(new Event('change'));
    expect([hidden('tcp'), hidden('tcp-port'), hidden('check-timeout'), hidden('check-retry')]).toEqual([false, true, false, false]);
    expect(required('host')).toBe(true);

    select.value = 'tcp';
    select.dispatchEvent(new Event('change'));
    expect(hidden('tcp-port')).toBe(false);

    select.value = 'http';
    select.dispatchEvent(new Event('change'));
    expect([hidden('http-advanced'), hidden('http-options'), hidden('tcp'), required('url'), required('host')]).toEqual([false, false, true, true, false]);

    select.value = 'dns';
    select.dispatchEvent(new Event('change'));
    expect([hidden('check-timeout'), required('hostname')]).toEqual([true, true]);

    select.value = 'group';
    select.dispatchEvent(new Event('change'));
    expect([hidden('check'), hidden('check-retry')]).toEqual([true, true]);
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

  it('applies the rules of every type and opens the Advanced block on an invalid field (1.18)', () => {
    document.body.innerHTML = `
      <select data-monitor-type-select>
        <option value="https">https</option><option value="ssl">ssl</option><option value="http">http</option>
      </select>
      <fieldset data-monitor-fields="check"><div data-monitor-fields="check-timeout"></div></fieldset>
      <fieldset data-monitor-fields="http"></fieldset>
      <details data-monitor-fields="http-advanced"><input name="maxRedirects" type="number" max="20" value="99"></details>
      <fieldset data-monitor-fields="tcp"><input data-required-for="tcp ping ssl" name="host"><div data-monitor-fields="tcp-port"><input data-required-for="tcp ssl" name="port"></div></fieldset>
      <fieldset data-monitor-fields="ssl"></fieldset><fieldset data-monitor-fields="dns"></fieldset>`;
    const select = document.querySelector('select') as HTMLSelectElement;
    const hidden = (key: string): boolean | undefined => document.querySelector<HTMLElement>(`[data-monitor-fields="${key}"]`)?.hidden;
    const required = (name: string): boolean | undefined => document.querySelector<HTMLInputElement>(`[name="${name}"]`)?.required;
    initMonitorFormTypeToggle(document);

    expect([hidden('http'), hidden('http-advanced'), hidden('tcp'), hidden('ssl'), hidden('check-timeout')]).toEqual([false, false, true, true, false]);

    select.value = 'ssl';
    select.dispatchEvent(new Event('change'));
    expect([hidden('tcp'), hidden('tcp-port'), hidden('ssl'), hidden('http'), hidden('dns')]).toEqual([false, false, false, true, true]);
    expect([required('host'), required('port')]).toEqual([true, true]);

    const details = document.querySelector('details') as HTMLDetailsElement;
    expect(details.open).toBe(false);
    document.querySelector<HTMLInputElement>('[name="maxRedirects"]')?.dispatchEvent(new Event('invalid', { cancelable: true }));
    expect(details.open).toBe(true);
    document.querySelector<HTMLInputElement>('[name="maxRedirects"]')?.dispatchEvent(new Event('invalid', { cancelable: true }));
    expect(details.open).toBe(true);
  });

  it('shows the server text when a file-managed monitor is refused with 409 (1.18)', async () => {
    const dialog = mountDialog();
    dialog.showModal = vi.fn();
    document.body.insertAdjacentHTML('afterbegin', '<main id="nowo-ui-content"></main>');
    const managed = 'This monitor is managed from a file: change the file instead.';
    vi.stubGlobal('fetch', vi.fn()
      .mockResolvedValueOnce(new Response(managed, { status: 409, headers: { 'Content-Type': 'text/plain' } }))
      .mockResolvedValueOnce(new Response('', { status: 409 }))
      .mockResolvedValueOnce(new Response(FORM_HTML, { headers: { 'Content-Type': 'text/html' } }))
      .mockResolvedValueOnce(new Response(managed, { status: 409, headers: { 'Content-Type': 'text/plain' } }))
      .mockResolvedValueOnce(new Response('', { status: 409 })));

    initMonitorFormModal(document);
    const opener = document.querySelector<HTMLElement>('[data-monitor-form-open]') as HTMLElement;
    opener.click();
    await flush();
    expect(dialog.querySelector('[data-monitor-form-body] .nowo-ui-flash--error')?.textContent).toBe(managed);

    opener.click();
    await flush();
    expect(dialog.querySelector('[data-monitor-form-body] .nowo-ui-flash--error')?.textContent).toBe('Could not load monitor form.');

    opener.click();
    await flush();
    dialog.querySelector('form')?.dispatchEvent(new Event('submit', { cancelable: true }));
    await vi.waitFor(() => expect(document.querySelector('#nowo-ui-content .nowo-ui-flash--error')?.textContent).toBe(managed));
    dialog.querySelector('form')?.dispatchEvent(new Event('submit', { cancelable: true }));
    await vi.waitFor(() => expect(document.querySelectorAll('#nowo-ui-content .nowo-ui-flash--error')).toHaveLength(2));
    expect(document.querySelectorAll('#nowo-ui-content .nowo-ui-flash--error')[0]?.textContent).toBe('Could not save monitor.');
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
  it('moves the actions into the dialog footer, bound to the form, and disables Save while posting (1.19)', async () => {
    const dialog = mountDialog();
    dialog.showModal = vi.fn(function (this: HTMLDialogElement) { this.setAttribute('open', ''); });
    dialog.close = vi.fn(function (this: HTMLDialogElement) { this.removeAttribute('open'); });
    document.body.insertAdjacentHTML('afterbegin', '<main id="nowo-ui-content"></main>');
    let release: (response: Response) => void = () => undefined;
    const pending = new Promise<Response>((resolve) => { release = resolve; });
    const fetchMock = vi.fn()
      .mockResolvedValueOnce(new Response(FORM_WITH_ACTIONS.replace(' data-monitor-form="1"', ' id="uptime-monitor-form" data-monitor-form="1"'), { headers: { 'Content-Type': 'text/html' } }))
      .mockReturnValueOnce(pending)
      .mockResolvedValueOnce(new Response(FORM_WITH_ACTIONS, { status: 422, headers: { 'Content-Type': 'text/html' } }))
      .mockResolvedValueOnce(new Response('Managed from a file.', { status: 409, headers: { 'Content-Type': 'text/plain' } }));
    vi.stubGlobal('fetch', fetchMock);

    initMonitorFormModal(document);
    const opener = document.querySelector<HTMLElement>('[data-monitor-form-open]') as HTMLElement;
    opener.click();
    await flush();

    const footer = dialog.querySelector<HTMLElement>('[data-nowo-ui-dialog-footer]') as HTMLElement;
    const form = dialog.querySelector('form') as HTMLFormElement;
    expect(footer.hidden).toBe(false);
    expect(Array.from(footer.querySelectorAll('button')).map((button) => button.textContent)).toEqual(['Cancel', 'Save']);
    expect(Array.from(footer.querySelectorAll('button')).map((button) => button.getAttribute('form'))).toEqual(['uptime-monitor-form', 'uptime-monitor-form']);
    expect(form.querySelector('[data-nowo-ui-dialog-actions]')).toBeNull();

    // A 409 on save (the monitor became file-managed meanwhile) keeps the dialog and flashes the server text.
    form.dispatchEvent(new Event('submit', { cancelable: true }));
    const save = footer.querySelector<HTMLButtonElement>('button[type="submit"]') as HTMLButtonElement;
    expect(save.disabled).toBe(true);
    release(new Response('Managed from a file.', { status: 409, headers: { 'Content-Type': 'text/plain' } }));
    await vi.waitFor(() => expect(save.disabled).toBe(false));
    expect(document.querySelector('#nowo-ui-content .nowo-ui-flash--error')?.textContent).toBe('Managed from a file.');
    expect(dialog.close).not.toHaveBeenCalled();

    // 422: the re-rendered fragment (a form without id) gets an id and its actions move into the footer again.
    form.dispatchEvent(new Event('submit', { cancelable: true }));
    await vi.waitFor(() => expect(dialog.querySelector('form')).not.toBe(form));
    const next = dialog.querySelector('form') as HTMLFormElement;
    expect(next.id).toBe('uptime-monitor-form');
    expect(footer.querySelectorAll('button')).toHaveLength(2);

    // Reopened on a monitor the server refuses: the message shows and the footer is emptied and hidden.
    opener.click();
    await flush();
    expect(footer.hidden).toBe(true);
    expect(footer.childNodes).toHaveLength(0);
    expect(dialog.querySelector('[data-monitor-form-body] .nowo-ui-flash--error')?.textContent).toBe('Managed from a file.');
  });

  it('fills the footer with the idle Cancel on every step without actions (dialog rule v2)', async () => {
    const dialog = mountDialog();
    dialog.querySelector('[data-monitor-form-body]')?.insertAdjacentHTML(
      'beforeend',
      '<template data-monitor-form-footer-idle><button type="button" data-nowo-ui-dialog-close>Cancel</button></template>',
    );
    dialog.showModal = vi.fn(function (this: HTMLDialogElement) { this.setAttribute('open', ''); });
    dialog.close = vi.fn(function (this: HTMLDialogElement) { this.removeAttribute('open'); });
    let releaseLoad: (response: Response) => void = () => undefined;
    vi.stubGlobal('fetch', vi.fn()
      .mockReturnValueOnce(new Promise<Response>((resolve) => { releaseLoad = resolve; }))
      .mockResolvedValueOnce(new Response('Managed from a file.', { status: 409, headers: { 'Content-Type': 'text/plain' } })));

    initMonitorFormModal(document);
    const footer = dialog.querySelector<HTMLElement>('[data-nowo-ui-dialog-footer]') as HTMLElement;
    const body = dialog.querySelector<HTMLElement>('[data-monitor-form-body]') as HTMLElement;
    // The template leaves the body: the loading text stays the body's only content.
    expect(body.querySelector('template')).toBeNull();
    const opener = document.querySelector<HTMLElement>('[data-monitor-form-open]') as HTMLElement;

    // Loading: Cancel only, visible.
    opener.click();
    await flush();
    expect(body.textContent).toBe('Loading form…');
    expect(footer.hidden).toBe(false);
    expect(Array.from(footer.querySelectorAll('button')).map((button) => button.textContent)).toEqual(['Cancel']);

    // The form: its own actions replace the idle Cancel (one Cancel, then Save).
    releaseLoad(new Response(FORM_WITH_ACTIONS, { headers: { 'Content-Type': 'text/html' } }));
    await vi.waitFor(() => expect(dialog.querySelector('form')).not.toBeNull());
    expect(Array.from(footer.querySelectorAll('button')).map((button) => button.textContent)).toEqual(['Cancel', 'Save']);

    // A refused monitor (409): the message and Cancel again; Cancel closes the dialog.
    opener.click();
    await flush();
    expect(body.querySelector('.nowo-ui-flash--error')?.textContent).toBe('Managed from a file.');
    expect(footer.hidden).toBe(false);
    expect(Array.from(footer.querySelectorAll('button')).map((button) => button.textContent)).toEqual(['Cancel']);
    footer.querySelector<HTMLButtonElement>('button')?.click();
    expect(dialog.close).toHaveBeenCalled();
  });

  it('keeps the focus in the dialog when the footer under it is swapped (audit GJ-I2)', async () => {
    const dialog = mountDialog();
    dialog.querySelector('[data-monitor-form-body]')?.insertAdjacentHTML(
      'beforeend',
      '<template data-monitor-form-footer-idle><button type="button" data-nowo-ui-dialog-close>Cancel</button></template>',
    );
    dialog.showModal = vi.fn(function (this: HTMLDialogElement) { this.setAttribute('open', ''); });
    let releaseLoad: (response: Response) => void = () => undefined;
    let releaseRefused: (response: Response) => void = () => undefined;
    vi.stubGlobal('fetch', vi.fn()
      .mockReturnValueOnce(new Promise<Response>((resolve) => { releaseLoad = resolve; }))
      .mockReturnValueOnce(new Promise<Response>((resolve) => { releaseRefused = resolve; })));
    initMonitorFormModal(document);
    const footer = dialog.querySelector<HTMLElement>('[data-nowo-ui-dialog-footer]') as HTMLElement;
    const opener = document.querySelector<HTMLElement>('[data-monitor-form-open]') as HTMLElement;

    // Loading: the user tabs to the idle Cancel; the form arrives and replaces it → the first field gets the focus.
    opener.click();
    await flush();
    const idleCancel = footer.querySelector<HTMLButtonElement>('button') as HTMLButtonElement;
    idleCancel.focus();
    expect(document.activeElement).toBe(idleCancel);
    releaseLoad(new Response(FORM_WITH_ACTIONS, { headers: { 'Content-Type': 'text/html' } }));
    await vi.waitFor(() => expect(dialog.querySelector('form')).not.toBeNull());
    expect(document.activeElement).toBe(dialog.querySelector('select[data-monitor-type-select]'));

    // Focus on the form's Save, then a refused reload (no form): the new idle Cancel gets the focus.
    (footer.querySelector('button[type="submit"]') as HTMLButtonElement).focus();
    opener.click();
    await flush();
    expect(document.activeElement).toBe(footer.querySelector('[data-nowo-ui-dialog-close]'));
    releaseRefused(new Response('Managed from a file.', { status: 409, headers: { 'Content-Type': 'text/plain' } }));
    await vi.waitFor(() => expect(dialog.querySelector('.nowo-ui-flash--error')).not.toBeNull());
    expect(document.activeElement).toBe(footer.querySelector('[data-nowo-ui-dialog-close]'));

    // Focus elsewhere (not in the footer): a swap does not move it.
    opener.focus();
    vi.stubGlobal('fetch', vi.fn().mockResolvedValueOnce(new Response(FORM_WITH_ACTIONS, { headers: { 'Content-Type': 'text/html' } })));
    opener.click();
    await vi.waitFor(() => expect(dialog.querySelector('form')).not.toBeNull());
    expect(document.activeElement).toBe(opener);
  });

  it('leaves the actions in the form when the dialog has no footer target or the fragment no actions row', async () => {
    const dialog = mountDialog();
    dialog.querySelector('[data-nowo-ui-dialog-footer]')?.remove();
    dialog.showModal = vi.fn();
    vi.stubGlobal('fetch', vi.fn()
      .mockResolvedValueOnce(new Response(FORM_WITH_ACTIONS, { headers: { 'Content-Type': 'text/html' } })));

    initMonitorFormModal(document);
    document.querySelector<HTMLElement>('[data-monitor-form-open]')?.click();
    await flush();
    expect(dialog.querySelector('form [data-nowo-ui-dialog-actions]')).not.toBeNull();

    const withFooter = mountDialog();
    withFooter.showModal = vi.fn();
    vi.stubGlobal('fetch', vi.fn().mockResolvedValueOnce(new Response(FORM_HTML, { headers: { 'Content-Type': 'text/html' } })));
    initMonitorFormModal(document);
    document.querySelector<HTMLElement>('[data-monitor-form-open]')?.click();
    await flush();
    expect(withFooter.querySelector<HTMLElement>('[data-nowo-ui-dialog-footer]')?.hidden).toBe(true);
    expect(withFooter.querySelector('form button[type="submit"]')).not.toBeNull();
  });
  it('rebinds footer controls whose form="" does not resolve to the dialog form (1.19.1, FM-I1)', async () => {
    const dialog = mountDialog();
    dialog.showModal = vi.fn();
    // A host override with its own form id that copied the 1.19 Save (form="uptime-monitor-form").
    vi.stubGlobal('fetch', vi.fn()
      .mockResolvedValueOnce(new Response(FORM_WITH_ACTIONS.replace(' data-monitor-form="1"', ' id="host-form" data-monitor-form="1"'), { headers: { 'Content-Type': 'text/html' } })));
    initMonitorFormModal(document);
    document.querySelector<HTMLElement>('[data-monitor-form-open]')?.click();
    await flush();

    const form = dialog.querySelector('form') as HTMLFormElement;
    const save = dialog.querySelector<HTMLButtonElement>('[data-nowo-ui-dialog-footer] button[type="submit"]') as HTMLButtonElement;
    expect(form.id).toBe('host-form');
    expect(save.getAttribute('form')).toBe('host-form');
    expect(save.form).toBe(form);

    // The page already uses the default id elsewhere: the form gets a unique one and the controls follow it.
    const other = mountDialog();
    other.showModal = vi.fn();
    document.body.insertAdjacentHTML('afterbegin', '<div id="uptime-monitor-form"></div><div id="uptime-monitor-form-2"></div>');
    vi.stubGlobal('fetch', vi.fn().mockResolvedValueOnce(new Response(FORM_WITH_ACTIONS, { headers: { 'Content-Type': 'text/html' } })));
    initMonitorFormModal(document);
    document.querySelector<HTMLElement>('[data-monitor-form-open]')?.click();
    await flush();

    const unique = other.querySelector('form') as HTMLFormElement;
    expect(unique.id).toBe('uptime-monitor-form-3');
    other.querySelectorAll<HTMLButtonElement>('[data-nowo-ui-dialog-footer] button').forEach((button) => {
      expect(button.form).toBe(unique);
    });
  });

  it('ignores late responses of an earlier opening of the dialog (1.19.1, FM-I2)', async () => {
    const dialog = mountDialog();
    dialog.showModal = vi.fn(function (this: HTMLDialogElement) { this.setAttribute('open', ''); });
    dialog.close = vi.fn(function (this: HTMLDialogElement) { this.removeAttribute('open'); });
    document.body.insertAdjacentHTML('afterbegin', '<main id="nowo-ui-content"></main>');
    let releaseLoad: (response: Response) => void = () => undefined;
    let releasePost: (response: Response) => void = () => undefined;
    let releaseSuccess: (response: Response) => void = () => undefined;
    const fetchMock = vi.fn()
      // Opening 1: its load is slow and answers after opening 2's.
      .mockReturnValueOnce(new Promise<Response>((resolve) => { releaseLoad = resolve; }))
      .mockResolvedValueOnce(new Response(FORM_WITH_ACTIONS.replace('name="url"', 'name="url" value="second"'), { headers: { 'Content-Type': 'text/html' } }))
      // A POST of opening 2, still in flight when opening 3 starts.
      .mockReturnValueOnce(new Promise<Response>((resolve) => { releasePost = resolve; }))
      .mockResolvedValueOnce(new Response(FORM_WITH_ACTIONS.replace('name="url"', 'name="url" value="third"'), { headers: { 'Content-Type': 'text/html' } }))
      // A POST of opening 3, answered with success after opening 4.
      .mockReturnValueOnce(new Promise<Response>((resolve) => { releaseSuccess = resolve; }))
      .mockResolvedValueOnce(new Response(FORM_WITH_ACTIONS.replace('name="url"', 'name="url" value="fourth"'), { headers: { 'Content-Type': 'text/html' } }));
    vi.stubGlobal('fetch', fetchMock);
    initMonitorFormModal(document);
    const opener = document.querySelector<HTMLElement>('[data-monitor-form-open]') as HTMLElement;
    const url = (): string | undefined => dialog.querySelector<HTMLInputElement>('input[name="url"]')?.value;

    opener.click();
    opener.click();
    await flush();
    expect(url()).toBe('second');
    releaseLoad(new Response('<form data-monitor-form="1" action="/x"><input name="url" value="first"></form>', { headers: { 'Content-Type': 'text/html' } }));
    await flush();
    expect(url()).toBe('second');

    // A 422 of the earlier opening never replaces the form of the newer one.
    (dialog.querySelector('form') as HTMLFormElement).dispatchEvent(new Event('submit', { cancelable: true }));
    opener.click();
    await flush();
    expect(url()).toBe('third');
    releasePost(new Response('<form data-monitor-form="1" action="/x"><input name="url" value="stale"></form>', { status: 422, headers: { 'Content-Type': 'text/html' } }));
    await flush();
    expect(url()).toBe('third');

    // Nor does its success close the dialog or flash.
    (dialog.querySelector('form') as HTMLFormElement).dispatchEvent(new Event('submit', { cancelable: true }));
    opener.click();
    await flush();
    expect(url()).toBe('fourth');
    releaseSuccess(new Response(JSON.stringify({ ok: true, message: 'Saved' }), { headers: { 'Content-Type': 'application/json' } }));
    await flush();
    expect(dialog.close).not.toHaveBeenCalled();
    expect(document.querySelector('#nowo-ui-content .nowo-ui-flash')).toBeNull();
    expect(url()).toBe('fourth');
  });
});
