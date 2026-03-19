import { Button, Card } from '@tangible/ui';

export function SettingsForm({
  fields,
  values,
  isDirty,
  isSaving,
  disabled,
  onChange,
  onSubmit,
}) {
  if (!fields.length) {
    return null;
  }

  return (
    <Card elevated className="surge-panel">
      <Card.Head>
        <h2>Settings</h2>
      </Card.Head>
      <Card.Body>
        <form className="surge-settings-form" onSubmit={onSubmit}>
          {fields.map((field) => (
            <div key={field.key} className="surge-settings-form__field">
              <label htmlFor={`surge-setting-${field.key}`} className="surge-settings-form__label">
                {field.label}
              </label>
              {field.type === 'textarea' ? (
                <textarea
                  id={`surge-setting-${field.key}`}
                  className="surge-settings-form__input surge-settings-form__textarea"
                  rows={field.rows ?? 4}
                  value={values[field.key] ?? ''}
                  disabled={disabled || field.locked}
                  onChange={(event) => onChange(field.key, event.target.value)}
                />
              ) : (
                <input
                  id={`surge-setting-${field.key}`}
                  className="surge-settings-form__input"
                  type={field.type === 'number' ? 'number' : 'text'}
                  inputMode={field.type === 'number' ? 'numeric' : undefined}
                  min={field.min}
                  max={field.max}
                  step={field.step}
                  value={values[field.key] ?? ''}
                  disabled={disabled || field.locked}
                  onChange={(event) => onChange(field.key, event.target.value)}
                />
              )}
              <p className="surge-settings-form__description">{field.description}</p>
              <p className="surge-settings-form__meta">
                Effective value: <strong>{field.effectiveLabel}</strong> <em>{field.source}</em>
              </p>
              {field.lockedMessage ? (
                <p className="surge-settings-form__lock" role="status">
                  {field.lockedMessage}
                </p>
              ) : null}
            </div>
          ))}
          <div className="surge-settings-form__actions">
            <Button
              type="submit"
              theme="primary"
              disabled={disabled || isSaving || !isDirty || fields.every((field) => field.locked)}
              loading={isSaving}
              label={isSaving ? 'Saving…' : 'Save settings'}
            />
          </div>
        </form>
      </Card.Body>
    </Card>
  );
}
