function isObject(value) {
  return !!value && typeof value === 'object' && !Array.isArray(value);
}

function isString(value) {
  return typeof value === 'string' && value.length > 0;
}

function normalizeFeedSlice(slice, defaults) {
  if (!isObject(slice)) {
    return {
      items: [],
      emptyTitle: defaults.emptyTitle,
      emptyDescription: defaults.emptyDescription,
    };
  }

  return {
    items: Array.isArray(slice.items) ? slice.items : [],
    emptyTitle: isString(slice.emptyTitle) ? slice.emptyTitle : defaults.emptyTitle,
    emptyDescription: isString(slice.emptyDescription)
      ? slice.emptyDescription
      : defaults.emptyDescription,
  };
}

function titleCaseToken(token) {
  return token
    .split(/[_-]+/g)
    .filter(Boolean)
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join(' ');
}

export const ACTION_DEFINITIONS = Object.freeze({
  flush: Object.freeze({
    key: 'flush',
    title: 'Flush cache',
    confirmLabel: 'Flush cache',
    theme: 'primary',
    consequence:
      'This marks current cache entries stale. Visitors may trigger cache regeneration on their next request.',
    caution: 'This operation is safe but still needs confirmation.',
  }),
  flushDelete: Object.freeze({
    key: 'flushDelete',
    title: 'Flush and delete files',
    confirmLabel: 'Delete cache files',
    theme: 'danger',
    consequence:
      'This removes cached files from disk. Existing requests will regenerate cache on demand.',
    caution: 'This is the destructive flush path.',
  }),
  reinstall: Object.freeze({
    key: 'reinstall',
    title: 'Reinstall drop-in',
    confirmLabel: 'Reinstall drop-in',
    theme: 'danger',
    consequence:
      'This rewrites the drop-in loader and may change cache behavior if the file is repaired.',
    caution: 'This operation is safe but still needs confirmation.',
  }),
});

function createNoticeId() {
  return `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
}

export function getActionDefinition(key) {
  return ACTION_DEFINITIONS[key] ?? null;
}

export function getObservabilitySummaryItems(data) {
  const items = data?.observability?.summary;
  return Array.isArray(items) ? items : [];
}

export function getRecentAdminActions(data) {
  return normalizeFeedSlice(data?.observability?.adminActions, {
    emptyTitle: 'No admin actions yet',
    emptyDescription: 'Flush, reinstall, and settings saves will appear here.',
  });
}

export function getRecentInvalidations(data) {
  return normalizeFeedSlice(data?.observability?.invalidations, {
    emptyTitle: 'No invalidations yet',
    emptyDescription: 'Expired flags are summarized here after they are written.',
  });
}

export function getRequestSamples(data) {
  const slice = normalizeFeedSlice(data?.observability?.requestSamples, {
    emptyTitle: 'Debug capture is inactive',
    emptyDescription: 'Start a timed debug session to capture recent request outcomes.',
  });

  return {
    active: Boolean(data?.observability?.requestSamples?.active),
    ...slice,
  };
}

export function getDebugSessionState(data) {
  const session = isObject(data?.observability?.debugSession) ? data.observability.debugSession : {};

  return {
    active: Boolean(session.active),
    duration: isString(session.duration) ? session.duration : null,
    enabledAt: typeof session.enabledAt === 'number' ? session.enabledAt : null,
    enabledAtIso: isString(session.enabledAtIso) ? session.enabledAtIso : null,
    expiresAt: typeof session.expiresAt === 'number' ? session.expiresAt : null,
    expiresAtIso: isString(session.expiresAtIso) ? session.expiresAtIso : null,
    remainingSeconds: typeof session.remainingSeconds === 'number' ? session.remainingSeconds : 0,
    availableDurations: Array.isArray(session.availableDurations)
      ? session.availableDurations.filter((value) => isString(value))
      : [],
  };
}

export function getRequestSampleOutcomeLabel(outcome) {
  const labels = {
    hit: 'Cache hit',
    bypass: 'Cache bypass',
    expired: 'Cache expired',
  };

  if (isString(labels[outcome])) {
    return labels[outcome];
  }

  return titleCaseToken(String(outcome ?? 'request'));
}

export function getRequestSampleReasonLabel(reason) {
  const labels = {
    ttl_disabled: 'TTL disabled',
    set_cookie: 'Set-Cookie header',
    cache_control_no_cache: 'Cache-Control no-cache',
    auth_header: 'Authorization header present',
    method_not_cacheable: 'Request method not cacheable',
    status_not_cacheable: 'Response status not cacheable',
    donotcachepage: 'DONOTCACHEPAGE set',
    cache_write_open_failed: 'Cache write failed',
    cache_file_missing: 'Cache file missing',
    expired_by_ttl: 'Expired by TTL',
    expired_by_flag: 'Expired by flag',
    hit: 'Cache hit',
  };

  if (isString(labels[reason])) {
    return labels[reason];
  }

  return titleCaseToken(String(reason ?? 'unknown'));
}

export function unwrapDashboardResponse(response) {
  if (isObject(response) && isObject(response.data)) {
    return response.data;
  }

  return response;
}

export function getResponseNotice(response) {
  return isObject(response) && isObject(response.notice) ? response.notice : null;
}

export function prependNotice(notices, notice, makeId = createNoticeId) {
  return [
    {
      id: makeId(),
      announce: notice.theme === 'danger' ? 'assertive' : 'polite',
      ...notice,
    },
    ...notices,
  ];
}

export function dismissNotice(notices, id) {
  return notices.filter((notice) => notice.id !== id);
}

export function closeAction(activeAction, busyAction) {
  return busyAction ? activeAction : null;
}

export function resolveActionSuccessState({ response, action, notices, makeNoticeId }) {
  const responseNotice = getResponseNotice(response);

  return {
    view: {
      status: 'ready',
      data: unwrapDashboardResponse(response),
    },
    notices: prependNotice(
      notices,
      {
        theme: responseNotice?.type === 'error' ? 'danger' : responseNotice?.type || 'success',
        title: `${action.title} completed`,
        message: responseNotice?.message || 'The dashboard was refreshed with the latest server data.',
      },
      makeNoticeId
    ),
    activeAction: null,
    busyAction: null,
  };
}

export function resolveActionFailureState({ error, action, notices, activeAction, makeNoticeId }) {
  const message =
    typeof error === 'string'
      ? error
      : typeof error?.message === 'string' && error.message
        ? error.message
        : 'The request failed.';

  return {
    notices: prependNotice(
      notices,
      {
        theme: 'danger',
        title: `${action.title} failed`,
        message,
      },
      makeNoticeId
    ),
    activeAction,
    busyAction: null,
  };
}

export function getSummaryValue(data, key, fallback = '—') {
  if (!isObject(data) || !isObject(data.summary)) {
    return fallback;
  }

  const value = data.summary[key];
  return value === undefined || value === null || value === '' ? fallback : String(value);
}

export function getChecklist(data) {
  const health = Array.isArray(data?.health) ? data.health : [];

  if (health.length > 0) {
    return health.map((item) => ({
      label: String(item.label ?? item.name ?? 'Check'),
      status: String(item.status ?? 'info'),
      details: typeof item.details === 'string' ? item.details : '',
    }));
  }

  return [
    { label: 'WP_CACHE enabled', status: data?.wpCacheEnabled ? 'success' : 'warning', details: '' },
    { label: 'Drop-in installed', status: data?.dropInPresent ? 'success' : 'warning', details: '' },
    {
      label: 'Cache directory writable',
      status: data?.cacheWritable ? 'success' : 'warning',
      details: '',
    },
  ];
}

export function getConfigItems(data) {
  return Array.isArray(data?.config?.items) ? data.config.items : [];
}

export function getSettingsFields(data) {
  return Array.isArray(data?.settings?.fields) ? data.settings.fields : [];
}

export function createSettingsDraft(fields) {
  return fields.reduce((draft, field) => {
    const rawValue = field.locked
      ? field.effectiveValue
      : field.draftValue ?? field.uiValue ?? field.effectiveValue ?? '';

    const value = Array.isArray(rawValue) ? rawValue.join('\n') : rawValue;
    return {
      ...draft,
      [field.key]: value === null || value === undefined ? '' : String(value),
    };
  }, {});
}

export function getStatusTheme(state) {
  return state === 'critical' ? 'danger' : state === 'warning' ? 'warning' : 'success';
}
