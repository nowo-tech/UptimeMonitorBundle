import { refreshDashboardLayout } from './dashboard-panel';

const MODAL_HEADER = 'X-Uptime-Modal';
const RETURN_HEADER = 'X-Uptime-Return';

type BootstrapModal = {
  show: () => void;
  hide: () => void;
};

type BootstrapNamespace = {
  Modal: {
    getOrCreateInstance: (element: Element) => BootstrapModal;
  };
};

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

export function initMonitorFormTypeToggle(scope: ParentNode): void {
  const typeSelect = scope.querySelector<HTMLSelectElement>('[data-monitor-type-select]');
  if (typeSelect === null) {
    return;
  }

  const group = 'group';
  const httpTypes = ['http', 'https'];
  const tcpTypes = ['tcp', 'ping', 'ssl'];
  const dns = 'dns';
  const sections = scope.querySelectorAll<HTMLElement>('[data-monitor-fields]');

  const toggle = (): void => {
    const value = typeSelect.value;
    sections.forEach((element) => {
      const key = element.getAttribute('data-monitor-fields');
      let show = false;
      if (key === 'check') {
        show = value !== group;
      } else if (key === 'http' || key === 'http-advanced' || key === 'http-options') {
        show = httpTypes.includes(value);
      } else if (key === 'tcp') {
        show = tcpTypes.includes(value);
      } else if (key === 'dns') {
        show = value === dns;
      } else if (key === 'ssl') {
        show = value === 'ssl';
      }
      element.hidden = !show;
    });
  };

  typeSelect.addEventListener('change', toggle);
  toggle();
}

function getBootstrapModal(element: HTMLElement): BootstrapModal | null {
  const bootstrap = (window as Window & { bootstrap?: BootstrapNamespace }).bootstrap;
  if (bootstrap?.Modal === undefined) {
    return null;
  }

  return bootstrap.Modal.getOrCreateInstance(element);
}

function showInlineFlash(message: string, type: 'success' | 'error'): void {
  const host = document.querySelector('.uptime-app');
  if (host === null) {
    return;
  }

  const alert = document.createElement('div');
  alert.className = `alert alert-${type === 'error' ? 'danger' : 'success'} alert-dismissible fade show mb-3`;
  alert.setAttribute('role', 'alert');
  alert.innerHTML = `${message}<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>`;
  host.prepend(alert);
}

function readFormOpenConfig(button: HTMLElement): FormOpenConfig | null {
  const url = button.dataset.monitorFormUrl ?? '';
  if (url === '') {
    return null;
  }

  const returnRaw = button.dataset.monitorFormReturn ?? 'dashboard';
  const returnTarget: FormReturnTarget =
    returnRaw === 'show' || returnRaw === 'reload' ? returnRaw : 'dashboard';
  const title = button.dataset.monitorFormTitle ?? '';

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

async function loadModalForm(
  bodyEl: HTMLElement,
  url: string,
  returnTarget: FormReturnTarget,
): Promise<void> {
  bodyEl.innerHTML =
    '<div class="text-center text-secondary py-5"><div class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></div></div>';

  const response = await fetch(url, {
    headers: {
      Accept: 'text/html',
      [MODAL_HEADER]: '1',
      [RETURN_HEADER]: returnTarget,
    },
    credentials: 'same-origin',
  });

  if (!response.ok) {
    bodyEl.innerHTML = '<p class="text-danger mb-0">Could not load monitor form.</p>';
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
        getBootstrapModal(modalEl)?.hide();
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
  modalEl: HTMLElement,
  bodyEl: HTMLElement,
  titleEl: HTMLElement | null,
): Promise<void> {
  const config = readFormOpenConfig(trigger);
  if (config === null) {
    return;
  }

  const modal = getBootstrapModal(modalEl);
  if (modal === null) {
    window.location.href = config.url;
    return;
  }

  if (titleEl !== null && config.title !== '') {
    titleEl.textContent = config.title;
  }

  modal.show();
  await loadModalForm(bodyEl, config.url, config.returnTarget);
}

/** @deprecated Use initMonitorFormModal */
export const initMonitorEditModal = initMonitorFormModal;

export function initMonitorFormModal(doc: Document = document): void {
  const modalEl = doc.querySelector<HTMLElement>('[data-uptime-monitor-form-modal]');
  const bodyEl = modalEl?.querySelector<HTMLElement>('[data-monitor-form-body]') ?? null;
  const titleEl = modalEl?.querySelector<HTMLElement>('[data-monitor-form-title]') ?? null;
  if (modalEl === null || bodyEl === null) {
    return;
  }

  doc.body.addEventListener('click', (event) => {
    const target = (event.target as HTMLElement).closest<HTMLElement>('[data-monitor-form-open]');
    if (target === null) {
      return;
    }

    event.preventDefault();
    void openFormModal(target, modalEl, bodyEl, titleEl);
  });

  const dashboardRoot = doc.getElementById('uptime-dashboard-root');
  if (dashboardRoot !== null) {
    syncDashboardEditButton(dashboardRoot);
    dashboardRoot.addEventListener('uptime:events-filter-changed', () => {
      syncDashboardEditButton(dashboardRoot);
    });

    if (dashboardRoot.dataset.eventsFilter !== undefined && dashboardRoot.dataset.eventsFilter !== '') {
      syncDashboardEditButton(dashboardRoot);
    }
  }
}
