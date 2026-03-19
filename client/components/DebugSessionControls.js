import { Button, Card, Notice } from '@tangible/ui';

function formatDurationOption(value) {
  const labels = {
    '1h': '1 hour',
    '3h': '3 hours',
    '12h': '12 hours',
    '24h': '24 hours',
    '3d': '3 days',
  };

  return labels[value] || value;
}

function formatRemainingSeconds(seconds) {
  const totalSeconds = Math.max(0, Number(seconds) || 0);

  if (totalSeconds < 60) {
    return `${totalSeconds}s`;
  }

  const hours = Math.floor(totalSeconds / 3600);
  const minutes = Math.floor((totalSeconds % 3600) / 60);
  const parts = [];

  if (hours > 0) {
    parts.push(`${hours}h`);
  }

  if (minutes > 0) {
    parts.push(`${minutes}m`);
  }

  return parts.join(' ') || `${Math.ceil(totalSeconds / 60)}m`;
}

export function DebugSessionControls({
  session,
  selectedDuration,
  onDurationChange,
  onStart,
  onStop,
  busyAction,
  disabled,
}) {
  const active = Boolean(session?.active);
  const durationOptions = Array.isArray(session?.availableDurations) && session.availableDurations.length > 0
    ? session.availableDurations
    : ['1h', '3h', '12h', '24h', '3d'];

  return (
    <Card elevated className="surge-panel surge-panel--debug">
      <Card.Head>
        <h2>Debug capture</h2>
      </Card.Head>
      <Card.Body>
        {active ? (
          <Notice theme="warning" announce="polite" className="surge-debug-banner">
            <Notice.Head title="Debug session active" />
            <Notice.Body>
              Timed request samples are being captured until {session.expiresAtIso || 'the selected expiry'}.
              Time remaining: {formatRemainingSeconds(session.remainingSeconds)}.
            </Notice.Body>
          </Notice>
        ) : (
          <div className="surge-debug-banner surge-debug-banner--inactive">
            <strong>Timed request capture is off.</strong>
            <p>Start a session when you need request samples for debugging or validation.</p>
          </div>
        )}

        <div className="surge-debug-controls">
          <label className="surge-debug-controls__field">
            <span className="surge-debug-controls__label">Duration</span>
            <select
              className="surge-debug-controls__select"
              value={selectedDuration}
              onChange={(event) => onDurationChange(event.target.value)}
              disabled={disabled}
            >
              {durationOptions.map((duration) => (
                <option key={duration} value={duration}>
                  {formatDurationOption(duration)}
                </option>
              ))}
            </select>
          </label>

          <div className="surge-debug-controls__actions">
            <Button
              theme="primary"
              onClick={() => onStart(selectedDuration)}
              loading={busyAction === 'start'}
              disabled={disabled || busyAction === 'stop' || active}
              label={busyAction === 'start' ? 'Starting…' : 'Start capture'}
            />
            {active && (
              <Button
                theme="secondary"
                variant="ghost"
                onClick={onStop}
                loading={busyAction === 'stop'}
                disabled={disabled || busyAction === 'start'}
                label={busyAction === 'stop' ? 'Stopping…' : 'Stop capture'}
              />
            )}
          </div>
        </div>

        {active && (
          <p className="surge-debug-controls__meta">
            Enabled at {session.enabledAtIso || 'unknown'}{session.duration ? ` for ${formatDurationOption(session.duration)}` : ''}.
          </p>
        )}
      </Card.Body>
    </Card>
  );
}
