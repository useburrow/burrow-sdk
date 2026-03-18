import { createHash } from 'node:crypto';
import type { JsonObject } from '../client/types.js';

const DEFAULT_ENTITY_ID_KEYS = [
  'externalEventId',
  'submissionId',
  'orderId',
  'lineItemId',
  'pluginId',
  'id',
] as const;

export interface EventKeyContext {
  provider?: string;
  source?: string;
  projectId?: string;
  entityIds?: Record<string, string | number>;
  versionMarker?: string;
}

export interface EventKeyResult {
  eventKey: string;
  canonical: string;
}

export function buildDeterministicEventKey(event: JsonObject, context: EventKeyContext = {}): EventKeyResult {
  const channel = readString(event.channel) ?? 'unknown';
  const eventName = readString(event.event) ?? 'unknown';
  const provider = context.provider ?? context.source ?? readString(event.provider) ?? readString(event.source) ?? 'unknown';
  const projectId = context.projectId ?? readString(event.projectId) ?? 'unknown';
  const versionMarker = context.versionMarker ?? readString(event.timestamp) ?? readString(event.updatedAt) ?? readString(event.version) ?? 'unknown';

  const discoveredEntityIds: Record<string, string> = {};
  for (const key of DEFAULT_ENTITY_ID_KEYS) {
    const value = event[key];
    if (typeof value === 'string' || typeof value === 'number') {
      discoveredEntityIds[key] = String(value);
    }
  }

  const mergedEntityIds: Record<string, string> = {};
  for (const [key, value] of Object.entries({ ...discoveredEntityIds, ...(context.entityIds ?? {}) })) {
    mergedEntityIds[key] = String(value);
  }
  const entityPairs = Object.entries(mergedEntityIds)
    .sort(([a], [b]) => a.localeCompare(b))
    .map(([k, v]) => `${k}=${v}`)
    .join('&');

  const canonical = [
    `channel=${channel}`,
    `event=${eventName}`,
    `provider=${provider}`,
    `projectId=${projectId}`,
    `entityIds=${entityPairs || 'none'}`,
    `version=${versionMarker}`,
  ].join('|');

  const eventKey = createHash('sha256').update(canonical).digest('hex');
  return { eventKey, canonical };
}

function readString(value: unknown): string | null {
  if (typeof value !== 'string') {
    return null;
  }
  const trimmed = value.trim();
  return trimmed === '' ? null : trimmed;
}
