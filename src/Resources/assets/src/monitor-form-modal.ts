import { refreshDashboardLayout } from './dashboard-panel';

const MODAL_HEADER = 'X-Uptime-Modal';
const RETURN_HEADER = 'X-Uptime-Return';

type FormReturnTarget = 'show' | 'dashboard' | 'reload';

type FormSuccessPayload = {
  ok: boolean;
  message?: string;
  return?: FormReturnTarget;
};

type FormOpenConfig = {
  url: string;
  returnTarget: FormReturnTarget;
  title: string;
};

/**
 * Section and row keys (`data-monitor-fields`) => the monitor types that show them. Since 1.18 the retry interval and
 * the timeout are rows of their own (push has neither; DNS and groups have no timeout) and the port leaves ping. The
 * 1.17 keys `http-options` and `tcp` keep their meaning for host templates that still use them.
 */
const FIELD_RULES: Record<string, (type: string) => boolean> = {
  check: (type) => type !== 'group',
  'check-retry': (type) => type !== 'group' && type !== 'push',
  'check-timeout': (type) => ['http', 'https', 'tcp', 'ssl', 'ping'].includes(type),
  http: (type) => type === 'http' || type === 'https',
  'http-advanced': (type) => type === 'http' || type === 'https',
  'http-options': (type) => type === 'http' || type === 'https',
  tcp: (type) => ['tcp', 'ping', 'ssl'].includes(type),
  'tcp-port': (type) => type === 'tcp' || type === 'ssl',
  dns: (type) => type === 'dns',
  ssl: (type) => type === 'ssl',
  push: (type) => type === 'push',
};

/**
 * Shows the sections and rows of the selected monitor type, and makes the target fields of that type required
 * (`data-required-for="http https"`): a hidden field never blocks the browser, the server checks the same rule.
 */
export function initMonitorFormTypeToggle(scope: ParentNode): void {
  const typeSelect = scope.querySelector<HTMLSelectElement>('[data-monitor-type-select]');
  if (typeSelect === null) {
    return;
  }

  const sections = scope.querySelectorAll<HTMLElement>('[data-monitor-fields]');
  const requiredFields = scope.querySelectorAll<HTMLInputElement>('[data-required-for]');

  const toggle = (): void => {
    const value = typeSelect.value;
    sections.forEach((element) => {
      const rule = FIELD_RULES[element.getAttribute('data-monitor-fields') ?? ''];
      // An unknown key stays hidden, as in 1.17.
      element.hidden = rule === undefined || !rule(value);
    });
    requiredFields.forEach((field) => {
      field.required = (field.getAttribute('data-required-for') ?? '').split(' ').includes(value);
    });
  };

  typeSelect.addEventListener('change', toggle);
  toggle();

  // A field the browser refuses inside the folded Advanced block opens it, so the error is never hidden (1.18).
  scope.addEventListener(
    'invalid',
    (event) => {
      const details = (event.target as Element | null)?.closest?.('details');
      if (details instanceof HTMLDetailsElement && !details.open) {
        details.open = true;
      }
    },
    true,
  );
}

/** Base id of the dialog form (template `id: 'uptime-monitor-form'`). */
const FORM_ID = 'uptime-monitor-form';

/**
 * Open count of each dialog body (1.19.1, audit FM-I2): a request started for an older opening (load or save) is
 * ignored when it completes, so a late response never re-mounts or closes the form of a newer opening.
 */
const generations = new WeakMap<HTMLElement, number>();

function nextGeneration(bodyEl: HTMLElement): number {
  const generation = (generations.get(bodyEl) ?? 0) + 1;
  generations.set(bodyEl, generation);

  return generation;
}

function isCurrent(bodyEl: HTMLElement, generation: number | undefined): boolean {
  return generations.get(bodyEl) === generation;
}

type FormControl = HTMLButtonElement | HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement;

/** A form id no other element of the document uses (the form keeps its own when it is unique). */
function uniqueFormId(form: HTMLFormElement): string {
  const doc = form.ownerDocument;
  if (form.id !== '' && doc.getElementById(form.id) === form) {
    return form.id;
  }
  let id = FORM_ID;
  for (let n = 2; doc.getElementById(id) !== null && doc.getElementById(id) !== form; n += 1) {
    id = `${FORM_ID}-${n}`;
  }

  return id;
}

/** Native <dialog> of the monitor form (UiKit `dialog` partial); null when the page has none. */
function asDialog(element: HTMLElement): HTMLDialogElement | null {
  return typeof (element as HTMLDialogElement).showModal === 'function' ? (element as HTMLDialogElement) : null;
}

function showInlineFlash(message: string, type: 'success' | 'error'): void {
  const host = document.querySelector('#nowo-ui-content') ?? document.querySelector('.uptime-app');
  if (host === null) {
    return;
  }

  const flash = document.createElement('div');
  flash.className = `nowo-ui-flash nowo-ui-flash--${type}`;
  flash.setAttribute('role', type === 'error' ? 'alert' : 'status');
  flash.textContent = message;
  host.prepend(flash);
}

function readFormOpenConfig(trigger: HTMLElement): FormOpenConfig | null {
  const url = trigger.dataset.monitorFormUrl ?? '';
  if (url === '') {
    return null;
  }

  const returnRaw = trigger.dataset.monitorFormReturn ?? 'dashboard';
  const returnTarget: FormReturnTarget =
    returnRaw === 'show' || returnRaw === 'reload' ? returnRaw : 'dashboard';
  // Explicit title, else the accessible name / text of the opener (UiKit actions carry the translated label).
  const title =
    trigger.dataset.monitorFormTitle ?? trigger.getAttribute('aria-label') ?? trigger.textContent?.trim() ?? '';

  return { url, returnTarget, title };
}

function syncDashboardEditButton(dashboardRoot: HTMLElement): void {
  const button = dashboardRoot.querySelector<HTMLElement>('[data-events-edit-monitor]');
  if (button === null) {
    return;
  }

  const filterRaw = dashboardRoot.dataset.eventsFilter ?? '';
  const filterId = filterRaw !== '' ? Number.parseInt(filterRaw, 10) : Number.NaN;
  if (Number.isNaN(filterId)) {
    button.hidden = true;
    button.dataset.monitorFormUrl = '';
    return;
  }

  const template = dashboardRoot.dataset.monitorFormUrlTemplate ?? '';
  if (template === '') {
    button.hidden = true;
    return;
  }

  button.hidden = false;
  button.dataset.monitorFormUrl = template.replace('__ID__', String(filterId));
  button.dataset.monitorFormReturn = 'dashboard';
}

/** The dialog footer the UiKit `dialog` partial renders with `footer_target` (UiKit 2.29); null without one. */
function dialogFooter(bodyEl: HTMLElement): HTMLElement | null {
  const modalEl = bodyEl.closest<HTMLElement>('[data-uptime-monitor-form-modal]');
  if (modalEl === null) {
    return null;
  }

  return (
    Array.from(modalEl.children).find(
      (child): child is HTMLElement => child instanceof HTMLElement && child.hasAttribute('data-nowo-ui-dialog-footer'),
    ) ?? null
  );
}

/**
 * The footer every step without its own actions shows (dialog rule v2: loading, a load error, a file-managed monitor):
 * the dialog template's <template data-monitor-form-footer-idle> (Cancel), read once when the dialog is wired since
 * the body that holds it is replaced on the first opening.
 */
const idleFooters = new WeakMap<HTMLElement, HTMLTemplateElement>();

/** True when the keyboard focus is on a control of the footer (it is lost when the footer's children are swapped). */
function footerHasFocus(footer: HTMLElement): boolean {
  const active = footer.ownerDocument.activeElement;

  return active !== null && active !== footer && footer.contains(active);
}

/**
 * Audit GJ-I2: after a footer swap that removed the focused control, focus goes to the first field of the form (when
 * there is one), else to the footer's Cancel / Close, so it never falls back to <body> behind the open dialog.
 */
function restoreFocus(footer: HTMLElement, form: HTMLFormElement | null): void {
  const field = form?.querySelector<HTMLElement>('input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled])') ?? null;
  const target = field ?? footer.querySelector<HTMLElement>('[data-nowo-ui-dialog-close]') ?? footer.querySelector<HTMLElement>('button');
  target?.focus();
}

function clearFooter(bodyEl: HTMLElement): void {
  const footer = dialogFooter(bodyEl);
  if (footer === null) {
    return;
  }
  const hadFocus = footerHasFocus(footer);
  const idle = idleFooters.get(bodyEl);
  footer.replaceChildren(...(idle !== undefined ? [idle.content.cloneNode(true)] : []));
  footer.hidden = idle === undefined;
  if (hadFocus && !footer.hidden) {
    restoreFocus(footer, null);
  }
}

/**
 * Moves the fragment's actions row ([data-nowo-ui-dialog-actions], else .uptime-form-actions) into the dialog footer
 * (1.19, UiKit 2.29 anatomy): the footer stays visible while only the body scrolls. Each moved control is bound to
 * the form with form="<id>" (an id is given to a form without one), so Save still submits it from the footer. Since
 * 1.19.1 a control whose form="" does not resolve to this form (a host override with another form id, or an id
 * used twice in the page) is rebound, and the form gets a unique id when its own is taken (audit FM-I1).
 */
function mountFooterActions(bodyEl: HTMLElement, form: HTMLFormElement): void {
  const footer = dialogFooter(bodyEl);
  const actions =
    bodyEl.querySelector<HTMLElement>('[data-nowo-ui-dialog-actions]') ?? bodyEl.querySelector<HTMLElement>('.uptime-form-actions');
  if (footer === null || actions === null) {
    return;
  }

  form.id = uniqueFormId(form);
  const hadFocus = footerHasFocus(footer);
  const controls = Array.from(actions.querySelectorAll<FormControl>('button, input, select, textarea'));
  footer.replaceChildren(...Array.from(actions.childNodes));
  actions.remove();
  footer.hidden = false;
  controls.forEach((control) => {
    if (control.form !== form) {
      control.setAttribute('form', form.id);
    }
  });
  if (hadFocus) {
    restoreFocus(footer, form);
  }
}

/** The submit buttons of the form: inside it, or bound to it from the dialog footer (form="<id>"). */
function submitButtonsOf(form: HTMLFormElement): HTMLButtonElement[] {
  const scope = form.closest<HTMLElement>('[data-uptime-monitor-form-modal]') ?? form;

  return Array.from(scope.querySelectorAll<HTMLButtonElement>('button[type="submit"]')).filter(
    (button) => form.contains(button) || (form.id !== '' && button.getAttribute('form') === form.id),
  );
}

function setBodyMessage(bodyEl: HTMLElement, message: string, error = false): void {
  clearFooter(bodyEl);
  const paragraph = document.createElement('p');
  paragraph.className = error ? 'nowo-ui-flash nowo-ui-flash--error' : 'nowo-ui-muted';
  paragraph.textContent = message;
  bodyEl.replaceChildren(paragraph);
}

/** Injects a form fragment into the dialog body, wires the type toggle, the footer actions and the submit. */
function mountFormFragment(bodyEl: HTMLElement, html: string, returnTarget: FormReturnTarget): void {
  clearFooter(bodyEl);
  bodyEl.innerHTML = html;
  initMonitorFormTypeToggle(bodyEl);

  const form = bodyEl.querySelector<HTMLFormElement>('[data-monitor-form]');
  if (form !== null) {
    mountFooterActions(bodyEl, form);
    bindModalFormSubmit(form, bodyEl, returnTarget);
  }
}

async function loadModalForm(
  bodyEl: HTMLElement,
  url: string,
  returnTarget: FormReturnTarget,
  loadingText: string,
  generation: number,
): Promise<void> {
  setBodyMessage(bodyEl, loadingText);

  const response = await fetch(url, {
    headers: {
      Accept: 'text/html',
      [MODAL_HEADER]: '1',
      [RETURN_HEADER]: returnTarget,
    },
    credentials: 'same-origin',
  });

  const text = response.status === 409 || response.ok ? await response.text() : '';
  if (!isCurrent(bodyEl, generation)) {
    return;
  }

  if (response.status === 409) {
    // A file-managed monitor (1.18): the server explains that its monitors file owns it.
    setBodyMessage(bodyEl, text.trim() || 'Could not load monitor form.', true);
    return;
  }

  if (!response.ok) {
    setBodyMessage(bodyEl, 'Could not load monitor form.', true);
    return;
  }

  mountFormFragment(bodyEl, text, returnTarget);
}

function bindModalFormSubmit(
  form: HTMLFormElement,
  bodyEl: HTMLElement,
  returnTarget: FormReturnTarget,
): void {
  form.addEventListener('submit', (event) => {
    event.preventDefault();
    void submitModalForm(form, bodyEl, returnTarget);
  });
}

async function submitModalForm(
  form: HTMLFormElement,
  bodyEl: HTMLElement,
  returnTarget: FormReturnTarget,
): Promise<void> {
  const submitButtons = submitButtonsOf(form);
  submitButtons.forEach((button) => {
    button.disabled = true;
  });
  const generation = generations.get(bodyEl);

  try {
    const response = await fetch(form.action, {
      method: 'POST',
      body: new FormData(form),
      headers: {
        Accept: 'application/json, text/html',
        [MODAL_HEADER]: '1',
        [RETURN_HEADER]: returnTarget,
      },
      credentials: 'same-origin',
    });

    const contentType = response.headers.get('Content-Type') ?? '';
    if (!isCurrent(bodyEl, generation)) {
      // The dialog was opened again while this POST was in flight: its response belongs to a form that is gone.
      return;
    }

    if (response.ok && contentType.includes('application/json')) {
      const payload = (await response.json()) as FormSuccessPayload;
      const modalEl = bodyEl.closest<HTMLElement>('[data-uptime-monitor-form-modal]');
      if (modalEl !== null) {
        asDialog(modalEl)?.close();
      }

      if (payload.message !== undefined && payload.message !== '') {
        showInlineFlash(payload.message, 'success');
      }

      const target = payload.return ?? returnTarget;
      if (target === 'show' || target === 'reload') {
        window.location.reload();
        return;
      }

      const dashboardRoot = document.getElementById('uptime-dashboard-root');
      if (dashboardRoot !== null) {
        await refreshDashboardLayout(dashboardRoot);
      }

      return;
    }

    if (response.status === 409) {
      showInlineFlash((await response.text()).trim() || 'Could not save monitor.', 'error');
      return;
    }

    if (response.status === 422 || contentType.includes('text/html')) {
      const html = await response.text();
      if (isCurrent(bodyEl, generation)) {
        mountFormFragment(bodyEl, html, returnTarget);
      }
      return;
    }

    showInlineFlash('Could not save monitor.', 'error');
  } catch {
    showInlineFlash('Could not save monitor.', 'error');
  } finally {
    submitButtons.forEach((button) => {
      button.disabled = false;
    });
  }
}

async function openFormModal(
  trigger: HTMLElement,
  dialog: HTMLDialogElement,
  bodyEl: HTMLElement,
  titleEl: HTMLElement | null,
  loadingText: string,
): Promise<void> {
  const config = readFormOpenConfig(trigger);
  if (config === null) {
    return;
  }

  if (titleEl !== null && config.title !== '') {
    titleEl.textContent = config.title;
  }

  const generation = nextGeneration(bodyEl);
  if (!dialog.open) {
    dialog.showModal();
  }
  await loadModalForm(bodyEl, config.url, config.returnTarget, loadingText, generation);
}

/** @deprecated Use initMonitorFormModal */
export const initMonitorEditModal = initMonitorFormModal;

/**
 * Opens the monitor form in the native <dialog> ([data-uptime-monitor-form-modal]) for every
 * [data-monitor-form-open] opener; openers are links to the form page, followed as-is without the dialog.
 */
export function initMonitorFormModal(doc: Document = document): void {
  const modalEl = doc.querySelector<HTMLElement>('[data-uptime-monitor-form-modal]');
  const dialog = modalEl !== null ? asDialog(modalEl) : null;
  const bodyEl = modalEl?.querySelector<HTMLElement>('[data-monitor-form-body]') ?? null;
  const titleEl = modalEl?.querySelector<HTMLElement>('[data-monitor-form-title]') ?? null;
  if (modalEl === null || dialog === null || bodyEl === null) {
    return;
  }
  const idle = bodyEl.querySelector<HTMLTemplateElement>('template[data-monitor-form-footer-idle]');
  if (idle !== null) {
    idleFooters.set(bodyEl, idle);
    idle.remove();
  }
  const loadingText = bodyEl.textContent?.trim() ?? '';

  // Close button / cancel (also handled by the UiKit dialog script when the host loads it).
  modalEl.addEventListener('click', (event) => {
    const closer = (event.target as HTMLElement).closest('[data-nowo-ui-dialog-close]');
    if (closer !== null && modalEl.contains(closer)) {
      event.preventDefault();
      dialog.close();
    }
  });

  doc.body.addEventListener('click', (event) => {
    const target = (event.target as HTMLElement).closest<HTMLElement>('[data-monitor-form-open]');
    if (target === null) {
      return;
    }

    event.preventDefault();
    void openFormModal(target, dialog, bodyEl, titleEl, loadingText);
  });

  const dashboardRoot = doc.getElementById('uptime-dashboard-root');
  if (dashboardRoot !== null) {
    syncDashboardEditButton(dashboardRoot);
    dashboardRoot.addEventListener('uptime:events-filter-changed', () => {
      syncDashboardEditButton(dashboardRoot);
    });
  }
}
