import test from 'node:test';
import assert from 'node:assert/strict';

import {
  ACTION_DEFINITIONS,
  createSettingsDraft,
  closeAction,
  dismissNotice,
  getActionDefinition,
  getChecklist,
  getSettingsFields,
  getStatusTheme,
  prependNotice,
  resolveActionFailureState,
  resolveActionSuccessState,
} from './model.mjs';

test('action definitions use explicit confirmation labels and consequence copy', () => {
  assert.equal(getActionDefinition('flush')?.confirmLabel, 'Flush cache');
  assert.equal(getActionDefinition('flushDelete')?.confirmLabel, 'Delete cache files');
  assert.equal(getActionDefinition('reinstall')?.confirmLabel, 'Reinstall drop-in');
  assert.equal(getActionDefinition('flushDelete')?.theme, 'danger');
  assert.match(getActionDefinition('flushDelete')?.caution ?? '', /destructive flush path/i);
});

test('prependNotice adds accessibility announcement based on theme', () => {
  const notices = prependNotice([], { theme: 'danger', title: 'Failed' }, () => 'notice-1');

  assert.deepEqual(notices, [
    {
      id: 'notice-1',
      announce: 'assertive',
      theme: 'danger',
      title: 'Failed',
    },
  ]);
});

test('closeAction keeps the modal open while an action is busy', () => {
  const action = ACTION_DEFINITIONS.flushDelete;

  assert.equal(closeAction(action, 'flushDelete'), action);
  assert.equal(closeAction(action, null), null);
});

test('resolveActionSuccessState refreshes the view and prepends a success notice', () => {
  const nextState = resolveActionSuccessState({
    response: {
      data: {
        summary: {
          cacheCount: 0,
        },
      },
      notice: {
        type: 'success',
        message: 'Deleted cache files and recreated the cache directory.',
      },
    },
    action: ACTION_DEFINITIONS.flushDelete,
    notices: [{ id: 'existing', title: 'Old notice' }],
    makeNoticeId: () => 'notice-2',
  });

  assert.deepEqual(nextState.view, {
    status: 'ready',
    data: {
      summary: {
        cacheCount: 0,
      },
    },
  });
  assert.equal(nextState.activeAction, null);
  assert.equal(nextState.busyAction, null);
  assert.equal(nextState.notices[0].title, 'Flush and delete files completed');
  assert.equal(nextState.notices[0].message, 'Deleted cache files and recreated the cache directory.');
  assert.equal(nextState.notices[1].id, 'existing');
});

test('resolveActionFailureState preserves retry context and prepends an error notice', () => {
  const nextState = resolveActionFailureState({
    error: new Error('Sorry, you are not allowed to do that.'),
    action: ACTION_DEFINITIONS.reinstall,
    notices: [],
    activeAction: ACTION_DEFINITIONS.reinstall,
    makeNoticeId: () => 'notice-3',
  });

  assert.equal(nextState.activeAction, ACTION_DEFINITIONS.reinstall);
  assert.equal(nextState.busyAction, null);
  assert.deepEqual(nextState.notices, [
    {
      id: 'notice-3',
      announce: 'assertive',
      theme: 'danger',
      title: 'Reinstall drop-in failed',
      message: 'Sorry, you are not allowed to do that.',
    },
  ]);
});

test('dismissNotice removes only the requested notice id', () => {
  assert.deepEqual(
    dismissNotice(
      [
        { id: 'one', title: 'First' },
        { id: 'two', title: 'Second' },
      ],
      'one'
    ),
    [{ id: 'two', title: 'Second' }]
  );
});

test('getChecklist falls back to derived health rows when the payload omits structured checks', () => {
  assert.deepEqual(getChecklist({ wpCacheEnabled: true, dropInPresent: false, cacheWritable: true }), [
    { label: 'WP_CACHE enabled', status: 'success', details: '' },
    { label: 'Drop-in installed', status: 'warning', details: '' },
    { label: 'Cache directory writable', status: 'success', details: '' },
  ]);
});

test('settings helpers expose fields and derive a draft from ui or effective values', () => {
  const fields = getSettingsFields({
    settings: {
      fields: [
        {
          key: 'ttl',
          draftValue: 600,
          uiValue: null,
          effectiveValue: 600,
          locked: false,
        },
      ],
    },
  });

  assert.deepEqual(fields, [
    {
      key: 'ttl',
      draftValue: 600,
      uiValue: null,
      effectiveValue: 600,
      locked: false,
    },
  ]);
  assert.deepEqual(createSettingsDraft(fields), { ttl: '600' });
});

test('createSettingsDraft prefers the effective value when a field is locked by code', () => {
  assert.deepEqual(
    createSettingsDraft([
      {
        key: 'ttl',
        uiValue: 900,
        effectiveValue: 1200,
        locked: true,
      },
    ]),
    { ttl: '1200' }
  );
});

test('createSettingsDraft serializes unlocked list fields from draft values', () => {
  assert.deepEqual(
    createSettingsDraft([
      {
        key: 'extra_ignore_query_vars',
        draftValue: ['gbraid', 'wbraid'],
        uiValue: ['gbraid', 'wbraid'],
        effectiveValue: ['fbclid', 'gclid', 'gbraid', 'wbraid'],
        locked: false,
      },
    ]),
    { extra_ignore_query_vars: 'gbraid\nwbraid' }
  );
});

test('getStatusTheme maps dashboard state to notice themes', () => {
  assert.equal(getStatusTheme('critical'), 'danger');
  assert.equal(getStatusTheme('warning'), 'warning');
  assert.equal(getStatusTheme('good'), 'success');
  assert.equal(getStatusTheme(undefined), 'success');
});
