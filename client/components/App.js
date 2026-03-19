import { useEffect, useMemo, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { Button, Card, Icon, Modal, Notice } from '@tangible/ui';

import { deriveInitialView, formatApiError, getApiPaths } from '../bootstrap.mjs';
import {
  ACTION_DEFINITIONS,
  createSettingsDraft,
  closeAction as resolveCloseAction,
  dismissNotice,
  getActionDefinition,
  getChecklist,
  getDebugSessionState,
  getConfigItems,
  getObservabilitySummaryItems,
  getRecentAdminActions,
  getRecentInvalidations,
  getRequestSampleOutcomeLabel,
  getRequestSampleReasonLabel,
  getRequestSamples,
  getSettingsFields,
  getStatusTheme,
  getSummaryValue,
  prependNotice,
  resolveActionFailureState,
  resolveActionSuccessState,
  unwrapDashboardResponse,
} from './model.mjs';
import { DebugSessionControls } from './DebugSessionControls.js';
import { ObservabilityFeed } from './ObservabilityFeed.js';
import { SettingsForm } from './SettingsForm.js';

function NoticeStack({ notices, onDismiss }) {
  if (!notices.length) {
    return null;
  }

  return (
    <div className="surge-notice-stack" aria-live="polite" aria-relevant="additions removals">
      {notices.map((notice) => (
        <Notice
          key={notice.id}
          theme={notice.theme}
          announce={notice.announce}
          dismissible
          onDismiss={() => onDismiss(notice.id)}
          className="surge-notice"
        >
          <Notice.Head title={notice.title} />
          {notice.message && <Notice.Body>{notice.message}</Notice.Body>}
        </Notice>
      ))}
    </div>
  );
}

function ActionModal({ action, busy, onCancel, onConfirm }) {
  if (!action) {
    return null;
  }

  return (
    <Modal
      open={!!action}
      onClose={onCancel}
      size="md"
      showCloseButton
      aria-labelledby="surge-admin-action-title"
      initialFocusSelector="[data-surge-confirm]"
    >
      <Modal.Head>
        <h2 id="surge-admin-action-title">{action.title}</h2>
      </Modal.Head>
      <Modal.Body scrollLabel="Action details">
        <p>{action.consequence}</p>
        <p>{action.caution}</p>
      </Modal.Body>
      <Modal.Foot>
        <Button variant="ghost" theme="secondary" onClick={onCancel} disabled={busy}>
          Cancel
        </Button>
        <Button
          data-surge-confirm
          theme={action.theme}
          loading={busy}
          disabled={busy}
          onClick={onConfirm}
          label={busy ? 'Working…' : action.confirmLabel}
        />
      </Modal.Foot>
    </Modal>
  );
}

async function requestDashboard(paths) {
  return apiFetch({ path: paths.dashboard, method: 'GET' });
}

async function requestAction(paths, actionKey) {
  const path = paths[actionKey];
  if (!path) {
    throw new Error(`Unknown action: ${actionKey}`);
  }

  return apiFetch({ path, method: 'POST' });
}

async function requestSettingsSave(paths, settings) {
  return apiFetch({
    path: paths.settings,
    method: 'POST',
    data: {
      settings,
    },
  });
}

async function requestDebugSession(paths, mode, duration) {
  if (mode === 'start') {
    return apiFetch({
      path: paths.debugStart,
      method: 'POST',
      data: {
        duration,
      },
    });
  }

  return apiFetch({
    path: paths.debugStop,
    method: 'POST',
  });
}

export function App({ bootstrap }) {
  const initialView = useMemo(() => deriveInitialView(bootstrap.initialData), [bootstrap.initialData]);
  const apiPaths = useMemo(() => getApiPaths(bootstrap.initialData), [bootstrap.initialData]);
  const [view, setView] = useState(initialView);
  const [notices, setNotices] = useState([]);
  const [activeAction, setActiveAction] = useState(null);
  const [busyAction, setBusyAction] = useState(null);
  const [isRefreshing, setIsRefreshing] = useState(false);
  const [settingsDraft, setSettingsDraft] = useState(() => createSettingsDraft(getSettingsFields(initialView.data)));
  const [isSettingsDirty, setIsSettingsDirty] = useState(false);
  const [isSavingSettings, setIsSavingSettings] = useState(false);
  const [debugBusyAction, setDebugBusyAction] = useState(null);
  const [selectedDebugDuration, setSelectedDebugDuration] = useState('1h');

  useEffect(() => {
    setView(initialView);
  }, [initialView]);

  const dashboardData = view.status === 'ready' ? view.data : view.data ?? bootstrap.initialData ?? null;
  const checklist = getChecklist(dashboardData);
  const configItems = getConfigItems(dashboardData);
  const observabilitySummary = getObservabilitySummaryItems(dashboardData);
  const recentAdminActions = getRecentAdminActions(dashboardData);
  const recentInvalidations = getRecentInvalidations(dashboardData);
  const requestSamples = getRequestSamples(dashboardData);
  const debugSession = getDebugSessionState(dashboardData);
  const settingsFields = getSettingsFields(dashboardData);

  useEffect(() => {
    if (!isSettingsDirty) {
      setSettingsDraft(createSettingsDraft(settingsFields));
    }
  }, [settingsFields, isSettingsDirty]);

  useEffect(() => {
    const availableDurations =
      Array.isArray(debugSession.availableDurations) && debugSession.availableDurations.length > 0
        ? debugSession.availableDurations
        : ['1h', '3h', '12h', '24h', '3d'];

    if (!availableDurations.includes(selectedDebugDuration)) {
      setSelectedDebugDuration(
        debugSession.duration && availableDurations.includes(debugSession.duration)
          ? debugSession.duration
          : availableDurations[0]
      );
    }
  }, [debugSession.availableDurations, debugSession.duration, selectedDebugDuration]);

  const pushNotice = (notice) => {
    setNotices((current) => prependNotice(current, notice));
  };

  const refresh = async () => {
    if (isRefreshing || busyAction || isSavingSettings || debugBusyAction) {
      return;
    }

    setIsRefreshing(true);
    try {
      const response = await requestDashboard(apiPaths);
      const data = unwrapDashboardResponse(response);
      setView({
        status: 'ready',
        data,
      });
    } catch (error) {
      pushNotice({
        theme: 'warning',
        title: 'Refresh failed',
        message: formatApiError(error),
      });
    } finally {
      setIsRefreshing(false);
    }
  };

  const handleDismissNotice = (id) => {
    setNotices((current) => dismissNotice(current, id));
  };

  const openAction = (key) => {
    if (busyAction || isRefreshing || isSavingSettings || debugBusyAction) {
      return;
    }

    setActiveAction(getActionDefinition(key));
  };

  const closeAction = () => {
    setActiveAction((current) => resolveCloseAction(current, busyAction));
  };

  const confirmAction = async () => {
    if (!activeAction || busyAction) {
      return;
    }

    setBusyAction(activeAction.key);
    try {
      const response = await requestAction(apiPaths, activeAction.key);
      const nextState = resolveActionSuccessState({ response, action: activeAction, notices: [] });

      setView(nextState.view);
      setNotices((current) =>
        resolveActionSuccessState({
          response,
          action: activeAction,
          notices: current,
        }).notices
      );
      setActiveAction(nextState.activeAction);
    } catch (error) {
      const nextState = resolveActionFailureState({
        error: { message: formatApiError(error) },
        action: activeAction,
        notices: [],
        activeAction,
      });

      setNotices((current) =>
        resolveActionFailureState({
          error: { message: formatApiError(error) },
          action: activeAction,
          notices: current,
          activeAction,
        }).notices
      );
      setActiveAction(nextState.activeAction);
    } finally {
      setBusyAction(null);
    }
  };

  const handleSettingChange = (key, value) => {
    setSettingsDraft((current) => ({
      ...current,
      [key]: value,
    }));
    setIsSettingsDirty(true);
  };

  const handleSettingsSubmit = async (event) => {
    event.preventDefault();

    if (isSavingSettings || busyAction || isRefreshing || debugBusyAction) {
      return;
    }

    setIsSavingSettings(true);
    try {
      const response = await requestSettingsSave(apiPaths, settingsDraft);
      const nextState = resolveActionSuccessState({
        response,
        action: {
          title: 'Save settings',
        },
        notices: [],
      });

      setView(nextState.view);
      setNotices((current) =>
        resolveActionSuccessState({
          response,
          action: {
            title: 'Save settings',
          },
          notices: current,
        }).notices
      );
      setIsSettingsDirty(false);
    } catch (error) {
      pushNotice({
        theme: 'danger',
        title: 'Save settings failed',
        message: formatApiError(error),
      });
    } finally {
      setIsSavingSettings(false);
    }
  };

  const handleDebugDurationChange = (duration) => {
    setSelectedDebugDuration(duration);
  };

  const handleDebugStart = async (duration) => {
    if (debugBusyAction || busyAction || isRefreshing || isSavingSettings || debugSession.active) {
      return;
    }

    setDebugBusyAction('start');
    try {
      const response = await requestDebugSession(apiPaths, 'start', duration);
      const nextState = resolveActionSuccessState({
        response,
        action: {
          title: 'Start debug capture',
        },
        notices: [],
      });

      setView(nextState.view);
      setNotices((current) =>
        resolveActionSuccessState({
          response,
          action: {
            title: 'Start debug capture',
          },
          notices: current,
        }).notices
      );
    } catch (error) {
      pushNotice({
        theme: 'danger',
        title: 'Start debug capture failed',
        message: formatApiError(error),
      });
    } finally {
      setDebugBusyAction(null);
    }
  };

  const handleDebugStop = async () => {
    if (debugBusyAction || busyAction || isRefreshing || isSavingSettings || !debugSession.active) {
      return;
    }

    setDebugBusyAction('stop');
    try {
      const response = await requestDebugSession(apiPaths, 'stop');
      const nextState = resolveActionSuccessState({
        response,
        action: {
          title: 'Stop debug capture',
        },
        notices: [],
      });

      setView(nextState.view);
      setNotices((current) =>
        resolveActionSuccessState({
          response,
          action: {
            title: 'Stop debug capture',
          },
          notices: current,
        }).notices
      );
    } catch (error) {
      pushNotice({
        theme: 'danger',
        title: 'Stop debug capture failed',
        message: formatApiError(error),
      });
    } finally {
      setDebugBusyAction(null);
    }
  };

  const statusTitle = dashboardData?.status?.title || 'Dashboard status';
  const statusDescription = dashboardData?.status?.description || '';
  const statusTheme = getStatusTheme(dashboardData?.status?.state);
  const actionsDisabled = isRefreshing || Boolean(busyAction) || isSavingSettings || Boolean(debugBusyAction);

  return (
    <div className="surge-admin-shell">
      <a href="#surge-primary-content" className="surge-skip-link tui-visually-hidden--focusable">
        Skip to dashboard content
      </a>
      <div className="surge-admin-shell__backdrop" aria-hidden="true" />
      <div className="surge-admin-shell__inner">
        <header className="surge-admin-hero">
          <div>
            <p className="surge-admin-kicker">Surge</p>
            <h2 id="surge-admin-title">Cache control center</h2>
            <p className="surge-admin-summary">
              Review cache health, flush safely, and keep the admin surface explicit about what the
              plugin is doing.
            </p>
          </div>
          <div className="surge-admin-hero__actions">
            <Button
              variant="ghost"
              theme="secondary"
              onClick={refresh}
              loading={isRefreshing}
              disabled={actionsDisabled}
              label={isRefreshing ? 'Refreshing…' : 'Refresh'}
            />
            <Button theme="primary" onClick={() => openAction('flush')} disabled={actionsDisabled} label="Flush cache" />
          </div>
        </header>

        {view.status === 'error' && (
          <Notice theme="danger" announce="assertive" className="surge-admin-status">
            <Notice.Head title="Unable to load dashboard" icon={<Icon name="system/alert-circle-outline" />} />
            <Notice.Body>{view.message || 'Surge admin data could not be loaded.'}</Notice.Body>
          </Notice>
        )}

        {view.status === 'loading' && (
          <Notice theme="info" announce="polite" className="surge-admin-status">
            <Notice.Head title="Loading dashboard snapshot" icon={<Icon name="system/cloud" />} />
            <Notice.Body>
              Surge is waiting for bootstrap data. The shell is mounted and ready to refresh once the
              backend endpoint is available.
            </Notice.Body>
          </Notice>
        )}

        <NoticeStack notices={notices} onDismiss={handleDismissNotice} />

        <Notice theme={statusTheme} announce="polite" className="surge-admin-status">
          <Notice.Head title={statusTitle} />
          {statusDescription && <Notice.Body>{statusDescription}</Notice.Body>}
        </Notice>

        <section
          id="surge-primary-content"
          className="surge-admin-grid"
          aria-labelledby="surge-admin-title"
        >
          <Card elevated className="surge-panel surge-panel--summary">
            <Card.Head>
              <h2>Current state</h2>
            </Card.Head>
            <Card.Body>
              <div className="surge-stat-grid">
                <article>
                  <span>Install</span>
                  <strong>{getSummaryValue(dashboardData, 'install', 'Unknown')}</strong>
                </article>
                <article>
                  <span>Cache size</span>
                  <strong>{getSummaryValue(dashboardData, 'cacheSize', '—')}</strong>
                </article>
                <article>
                  <span>Cache entries</span>
                  <strong>{getSummaryValue(dashboardData, 'cacheCount', '—')}</strong>
                </article>
                <article>
                  <span>TTL</span>
                  <strong>{getSummaryValue(dashboardData, 'ttl', '—')}</strong>
                </article>
              </div>
            </Card.Body>
          </Card>

          <Card elevated className="surge-panel surge-panel--observability-summary">
            <Card.Head>
              <h2>Observability summary</h2>
            </Card.Head>
            <Card.Body>
              <div className="surge-observability-summary">
                {observabilitySummary.length === 0 ? (
                  <div className="surge-empty-state">
                    <strong>No observability summary yet</strong>
                    <p>Recent admin actions and invalidations will appear here once the plugin is used.</p>
                  </div>
                ) : (
                  observabilitySummary.map((item) => (
                    <article key={item.key}>
                      <span>{item.label}</span>
                      <strong>{item.value}</strong>
                    </article>
                  ))
                )}
              </div>
            </Card.Body>
          </Card>

          <Card elevated className="surge-panel surge-panel--health">
            <Card.Head>
              <h2>Health checklist</h2>
            </Card.Head>
            <Card.Body>
              <ul className="surge-checklist">
                {checklist.map((item) => (
                  <li key={item.label} data-status={item.status}>
                    <span className="surge-checklist__label">{item.label}</span>
                    {item.details ? <span className="surge-checklist__details">{item.details}</span> : null}
                  </li>
                ))}
              </ul>
            </Card.Body>
          </Card>

          <DebugSessionControls
            session={debugSession}
            selectedDuration={selectedDebugDuration}
            onDurationChange={handleDebugDurationChange}
            onStart={handleDebugStart}
            onStop={handleDebugStop}
            busyAction={debugBusyAction}
            disabled={actionsDisabled}
          />

          <ObservabilityFeed
            title="Recent admin actions"
            items={recentAdminActions.items}
            emptyTitle={recentAdminActions.emptyTitle}
            emptyDescription={recentAdminActions.emptyDescription}
            className="surge-panel--admin-actions"
            getItemKey={(item, index) => `${item.logged_at || 'admin'}-${item.action || index}`}
            renderItem={(item) => (
              <div className="surge-feed__content">
                <div className="surge-feed__header">
                  <strong>{item.action || 'Action'}</strong>
                  <span>{item.logged_at || 'recently'}</span>
                </div>
                <p className="surge-feed__summary">{item.summary || 'No summary provided.'}</p>
                <dl className="surge-feed__meta">
                  {item.mode ? (
                    <div>
                      <dt>Mode</dt>
                      <dd>{item.mode}</dd>
                    </div>
                  ) : null}
                  {item.userId ? (
                    <div>
                      <dt>User</dt>
                      <dd>{item.userId}</dd>
                    </div>
                  ) : null}
                </dl>
              </div>
            )}
          />

          <ObservabilityFeed
            title="Recent invalidations"
            items={recentInvalidations.items}
            emptyTitle={recentInvalidations.emptyTitle}
            emptyDescription={recentInvalidations.emptyDescription}
            className="surge-panel--invalidations"
            getItemKey={(item, index) => `${item.logged_at || 'invalid'}-${item.scope || index}`}
            renderItem={(item) => (
              <div className="surge-feed__content">
                <div className="surge-feed__header">
                  <strong>{item.scope === 'path' ? 'Path invalidation' : 'Semantic invalidation'}</strong>
                  <span>{item.logged_at || 'recently'}</span>
                </div>
                <p className="surge-feed__summary">
                  {(item.flagCount || 0) === 1 ? '1 flag expired' : `${item.flagCount || 0} flags expired`}
                  {Array.isArray(item.flags) && item.flags.length ? `: ${item.flags.slice(0, 4).join(', ')}` : ''}
                </p>
                <dl className="surge-feed__meta">
                  {item.scope ? (
                    <div>
                      <dt>Scope</dt>
                      <dd>{item.scope}</dd>
                    </div>
                  ) : null}
                  {item.trigger ? (
                    <div>
                      <dt>Trigger</dt>
                      <dd>{item.trigger}</dd>
                    </div>
                  ) : null}
                </dl>
              </div>
            )}
          />

          <ObservabilityFeed
            title="Recent request samples"
            items={requestSamples.items}
            emptyTitle={requestSamples.emptyTitle}
            emptyDescription={requestSamples.emptyDescription}
            className="surge-panel--request-samples"
            getItemKey={(item, index) => `${item.logged_at || 'request'}-${item.cacheKey || index}`}
            renderItem={(item) => (
              <div className="surge-feed__content">
                <div className="surge-feed__header">
                  <span className={`surge-feed__badge surge-feed__badge--${item.outcome || 'unknown'}`}>
                    {getRequestSampleOutcomeLabel(item.outcome)}
                  </span>
                  <span>{item.logged_at || 'recently'}</span>
                </div>
                <p className="surge-feed__summary">{getRequestSampleReasonLabel(item.reason)}</p>
                <dl className="surge-feed__meta">
                  {item.path ? (
                    <div>
                      <dt>Path</dt>
                      <dd>{item.path}</dd>
                    </div>
                  ) : null}
                  {item.cacheKey ? (
                    <div>
                      <dt>Cache key</dt>
                      <dd>{item.cacheKey}</dd>
                    </div>
                  ) : null}
                </dl>
              </div>
            )}
          />

          <Card elevated className="surge-panel surge-panel--config">
            <Card.Head>
              <h2>Effective configuration</h2>
            </Card.Head>
            <Card.Body>
              <ul className="surge-config-list">
                {configItems.length === 0 ? (
                  <li>No configuration summary available yet.</li>
                ) : (
                  configItems.map((item) => (
                    <li key={item.key}>
                      <span>{item.label}</span>
                      <strong>{item.displayValue}</strong>
                      <em>{item.source}</em>
                    </li>
                  ))
                )}
              </ul>
            </Card.Body>
          </Card>

          <SettingsForm
            fields={settingsFields}
            values={settingsDraft}
            isDirty={isSettingsDirty}
            isSaving={isSavingSettings}
            disabled={actionsDisabled}
            onChange={handleSettingChange}
            onSubmit={handleSettingsSubmit}
          />

          <Card elevated className="surge-panel surge-panel--danger">
            <Card.Head>
              <h2>Danger zone</h2>
            </Card.Head>
            <Card.Body>
              <div className="surge-action-list">
                <Button
                  theme="secondary"
                  variant="outline"
                  onClick={() => openAction(ACTION_DEFINITIONS.flush.key)}
                  disabled={actionsDisabled}
                  label={ACTION_DEFINITIONS.flush.title}
                />
                <Button
                  theme="danger"
                  variant="outline"
                  onClick={() => openAction(ACTION_DEFINITIONS.flushDelete.key)}
                  disabled={actionsDisabled}
                  label={ACTION_DEFINITIONS.flushDelete.title}
                />
                <Button
                  theme="secondary"
                  variant="outline"
                  onClick={() => openAction(ACTION_DEFINITIONS.reinstall.key)}
                  disabled={actionsDisabled}
                  label={ACTION_DEFINITIONS.reinstall.title}
                />
              </div>
            </Card.Body>
          </Card>

          <Card elevated className="surge-panel surge-panel--help">
            <Card.Head>
              <h2>How this works</h2>
            </Card.Head>
            <Card.Body>
              <div className="surge-help-copy">
                <p>
                  Surge keeps configuration code-first today. The dashboard shows the effective
                  runtime view without assuming the UI owns those values.
                </p>
                <p>
                  Use soft flush to expire existing entries, delete flush to remove cached files,
                  and reinstall when the drop-in or install state needs repair.
                </p>
                <p>
                  Observability stays derived by default. Timed request capture only appears when you
                  deliberately enable a debug session.
                </p>
              </div>
            </Card.Body>
          </Card>
        </section>
      </div>

      <ActionModal
        action={activeAction}
        busy={busyAction === activeAction?.key}
        onCancel={closeAction}
        onConfirm={confirmAction}
      />
    </div>
  );
}
