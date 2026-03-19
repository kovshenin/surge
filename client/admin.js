import { createRoot } from '@wordpress/element';
import apiFetch, { createNonceMiddleware, createRootURLMiddleware } from '@wordpress/api-fetch';
import '@tangible/ui/styles/unlayered';

import { App } from './components/App.js';
import { normalizeBootstrapData } from './bootstrap.mjs';
import './styles/base.scss';

let configuredBootstrapKey = null;

function configureApiClient({ apiBase, nonce }) {
  const key = `${apiBase}::${nonce}`;
  if (configuredBootstrapKey === key) {
    return;
  }

  if (apiBase) {
    apiFetch.use(createRootURLMiddleware(apiBase));
  }

  if (nonce) {
    apiFetch.use(createNonceMiddleware(nonce));
  }

  configuredBootstrapKey = key;
}

export function mountSurgeAdmin(root = document.getElementById('surge-admin-root')) {
  if (!root) {
    return false;
  }

  root.classList.add('tui-interface');

  const bootstrap = normalizeBootstrapData(globalThis.window?.surgeAdmin);
  configureApiClient(bootstrap);

  createRoot(root).render(<App bootstrap={bootstrap} />);
  return true;
}

function boot() {
  if (document.readyState === 'loading') {
    document.addEventListener(
      'DOMContentLoaded',
      () => {
        mountSurgeAdmin();
      },
      { once: true }
    );
    return;
  }

  mountSurgeAdmin();
}

boot();
