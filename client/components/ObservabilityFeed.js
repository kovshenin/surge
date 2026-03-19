import { Card } from '@tangible/ui';

export function ObservabilityFeed({
  title,
  items,
  emptyTitle,
  emptyDescription,
  renderItem,
  getItemKey,
  className = '',
}) {
  const hasItems = Array.isArray(items) && items.length > 0;

  return (
    <Card elevated className={`surge-panel ${className}`.trim()}>
      <Card.Head>
        <h2>{title}</h2>
      </Card.Head>
      <Card.Body>
        {hasItems ? (
          <ul className="surge-feed">
            {items.map((item, index) => (
              <li key={getItemKey ? getItemKey(item, index) : index} className="surge-feed__item">
                {renderItem(item, index)}
              </li>
            ))}
          </ul>
        ) : (
          <div className="surge-empty-state">
            <strong>{emptyTitle}</strong>
            <p>{emptyDescription}</p>
          </div>
        )}
      </Card.Body>
    </Card>
  );
}
