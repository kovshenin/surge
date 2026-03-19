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
  getConfigItems,
  getSettingsFields,
  getStatusTheme,
  getSummaryValue,
  prependNotice,
  resolveActionFailureState,
  resolveActionSuccessState,
  unwrapDashboardResponse,
} from './model.mjs';
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

  useEffect(() => {
    setView(initialView);
  }, [initialView]);

  const dashboardData = view.status === 'ready' ? view.data : view.data ?? bootstrap.initialData ?? null;
  const checklist = getChecklist(dashboardData);
  const configItems = getConfigItems(dashboardData);
  const settingsFields = getSettingsFields(dashboardData);

  useEffect(() => {
    if (!isSettingsDirty) {
      setSettingsDraft(createSettingsDraft(settingsFields));
    }
  }, [settingsFields, isSettingsDirty]);

  const pushNotice = (notice) => {
    setNotices((current) => prependNotice(current, notice));
  };

  const refresh = async () => {
    if (isRefreshing || busyAction || isSavingSettings) {
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
    if (busyAction || isRefreshing || isSavingSettings) {
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

    if (isSavingSettings || busyAction || isRefreshing) {
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

  const statusTitle = dashboardData?.status?.title || 'Dashboard status';
  const statusDescription = dashboardData?.status?.description || '';
  const statusTheme = getStatusTheme(dashboardData?.status?.state);
  const actionsDisabled = isRefreshing || Boolean(busyAction) || isSavingSettings;

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

          <Card elevated className="surge-panel">
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

          <Card elevated className="surge-panel">
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

          <Card elevated className="surge-panel">
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

          <Card elevated className="surge-panel">
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
