const EVENT_ICON_MAP: Record<string, string> = {
  'forms.submission.received': 'file-signature',
  'ecommerce.order.placed': 'shopping-cart',
  'ecommerce.item.purchased': 'package',
  'system.stack.snapshot': 'layers',
  'system.heartbeat.ping': 'heart-pulse',
  'code.commit.pushed': 'git-commit-horizontal',
  'analytics.stats.daily': 'chart-column',
  'monitoring.incident.started': 'triangle-alert',
  'backups.job.completed': 'database-backup',
  'invoicing.invoice.synced': 'receipt-text',
};

const CHANNEL_ICON_DEFAULTS: Record<string, string> = {
  forms: 'file-signature',
  ecommerce: 'shopping-cart',
  system: 'layers',
  code: 'git-commit-horizontal',
  analytics: 'chart-column',
  monitoring: 'triangle-alert',
  backups: 'database-backup',
  invoicing: 'receipt-text',
};

export function resolveIconForEvent(channel: string, event: string): string | null {
  const eventKey = event.trim().toLowerCase();
  if (EVENT_ICON_MAP[eventKey]) {
    return EVENT_ICON_MAP[eventKey];
  }

  const channelKey = channel.trim().toLowerCase();
  return CHANNEL_ICON_DEFAULTS[channelKey] ?? null;
}
