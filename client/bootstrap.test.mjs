import test from 'node:test';
import assert from 'node:assert/strict';

import {
  deriveInitialView,
  formatApiError,
  getApiPaths,
  normalizeBootstrapData,
} from './bootstrap.mjs';

test('normalizeBootstrapData returns safe defaults', () => {
  assert.deepEqual(normalizeBootstrapData(undefined), {
    apiBase: '',
    nonce: '',
    initialData: null,
  });
});

test('deriveInitialView marks a dashboard payload as ready', () => {
  assert.deepEqual(
    deriveInitialView({
      dashboard: {
        title: 'Surge',
      },
    }),
    {
      status: 'ready',
      data: {
        dashboard: {
          title: 'Surge',
        },
      },
    }
  );
});

test('deriveInitialView marks structured API failures as error state', () => {
  assert.deepEqual(
    deriveInitialView({
      status: 'error',
      message: 'Sorry, you are not allowed to view this dashboard.',
    }),
    {
      status: 'error',
      message: 'Sorry, you are not allowed to view this dashboard.',
      data: {
        status: 'error',
        message: 'Sorry, you are not allowed to view this dashboard.',
      },
    }
  );
});

test('getApiPaths uses endpoint overrides when bootstrap data provides them', () => {
  assert.deepEqual(
    getApiPaths({
      endpoints: {
        dashboard: '/custom/dashboard',
        flush: '/custom/flush',
        flushDelete: '/custom/delete',
        reinstall: '/custom/reinstall',
        settings: '/custom/settings',
      },
    }),
    {
      dashboard: '/custom/dashboard',
      flush: '/custom/flush',
      flushDelete: '/custom/delete',
      reinstall: '/custom/reinstall',
      settings: '/custom/settings',
      debugStart: '/surge/v1/admin/debug/start',
      debugStop: '/surge/v1/admin/debug/stop',
    }
  );
});

test('getApiPaths falls back to Surge REST defaults for malformed bootstrap data', () => {
  assert.deepEqual(getApiPaths({ endpoints: { dashboard: 42 } }), {
    dashboard: '/surge/v1/admin',
    flush: '/surge/v1/admin/flush',
    flushDelete: '/surge/v1/admin/flush?delete=1',
    reinstall: '/surge/v1/admin/reinstall',
    settings: '/surge/v1/admin/settings',
    debugStart: '/surge/v1/admin/debug/start',
    debugStop: '/surge/v1/admin/debug/stop',
  });
});

test('getApiPaths includes debug endpoint overrides when provided', () => {
  assert.deepEqual(
    getApiPaths({
      endpoints: {
        debugStart: '/custom/debug/start',
        debugStop: '/custom/debug/stop',
      },
    }),
    {
      dashboard: '/surge/v1/admin',
      flush: '/surge/v1/admin/flush',
      flushDelete: '/surge/v1/admin/flush?delete=1',
      reinstall: '/surge/v1/admin/reinstall',
      settings: '/surge/v1/admin/settings',
      debugStart: '/custom/debug/start',
      debugStop: '/custom/debug/stop',
    }
  );
});

test('formatApiError prefers explicit message fields and degrades predictably', () => {
  assert.equal(formatApiError({ message: 'Forbidden' }), 'Forbidden');
  assert.equal(formatApiError('Nope'), 'Nope');
  assert.equal(formatApiError({ code: 'bad_request' }), 'The request failed.');
});
