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

function setBodyMessage(bodyEl: HTMLElement, message: string, error = false): void {
  const paragraph = document.createElement('p');
  paragraph.className = error ? 'nowo-ui-flash nowo-ui-flash--error' : 'nowo-ui-muted';
  paragraph.textContent = message;
  bodyEl.replaceChildren(paragraph);
}

async function loadModalForm(
  bodyEl: HTMLElement,
  url: string,
  returnTarget: FormReturnTarget,
  loadingText: string,
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

  if (!response.ok) {
    setBodyMessage(bodyEl, 'Could not load monitor form.', true);
    return;
  }

  bodyEl.innerHTML = await response.text();
  initMonitorFormTypeToggle(bodyEl);

  const form = bodyEl.querySelector<HTMLFormElement>('[data-monitor-form]');
  if (form !== null) {
    bindModalFormSubmit(form, bodyEl, returnTarget);
  }
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
  const submitButtons = form.querySelectorAll<HTMLButtonElement>('button[type="submit"]');
  submitButtons.forEach((button) => {
    button.disabled = true;
  });

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

    if (response.status === 422 || contentType.includes('text/html')) {
      bodyEl.innerHTML = await response.text();
      initMonitorFormTypeToggle(bodyEl);
      const nextForm = bodyEl.querySelector<HTMLFormElement>('[data-monitor-form]');
      if (nextForm !== null) {
        bindModalFormSubmit(nextForm, bodyEl, returnTarget);
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

  if (!dialog.open) {
    dialog.showModal();
  }
  await loadModalForm(bodyEl, config.url, config.returnTarget, loadingText);
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
