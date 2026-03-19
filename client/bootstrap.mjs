const DEFAULT_BOOTSTRAP = Object.freeze({
  apiBase: '',
  nonce: '',
  initialData: null,
});

function isObject(value) {
  return !!value && typeof value === 'object' && !Array.isArray(value);
}

export function normalizeBootstrapData(source) {
  if (!isObject(source)) {
    return { ...DEFAULT_BOOTSTRAP };
  }

  return {
    apiBase: typeof source.apiBase === 'string' ? source.apiBase : '',
    nonce: typeof source.nonce === 'string' ? source.nonce : '',
    initialData: isObject(source.initialData) ? source.initialData : null,
  };
}

export function deriveInitialView(initialData) {
  if (!isObject(initialData)) {
    return {
      status: 'loading',
      data: null,
    };
  }

  if (initialData.status === 'error' || initialData.error) {
    return {
      status: 'error',
      message:
        typeof initialData.message === 'string'
          ? initialData.message
          : 'Surge admin data could not be loaded.',
      data: initialData,
    };
  }

  if (
    initialData.status === 'ready' ||
    isObject(initialData.dashboard) ||
    Array.isArray(initialData.health)
  ) {
    return {
      status: 'ready',
      data: initialData,
    };
  }

  return {
    status: 'loading',
    data: initialData,
  };
}

export function getApiPaths(initialData) {
  const endpoints = isObject(initialData?.endpoints) ? initialData.endpoints : {};

  return {
    dashboard: typeof endpoints.dashboard === 'string' ? endpoints.dashboard : '/surge/v1/admin',
    flush: typeof endpoints.flush === 'string' ? endpoints.flush : '/surge/v1/admin/flush',
    flushDelete:
      typeof endpoints.flushDelete === 'string'
        ? endpoints.flushDelete
        : '/surge/v1/admin/flush?delete=1',
    reinstall:
      typeof endpoints.reinstall === 'string'
        ? endpoints.reinstall
        : '/surge/v1/admin/reinstall',
    settings:
      typeof endpoints.settings === 'string'
        ? endpoints.settings
        : '/surge/v1/admin/settings',
  };
}

export function formatApiError(error) {
  if (!error) {
    return 'The request failed.';
  }

  if (typeof error === 'string') {
    return error;
  }

  if (typeof error.message === 'string' && error.message) {
    return error.message;
  }

  return 'The request failed.';
}
