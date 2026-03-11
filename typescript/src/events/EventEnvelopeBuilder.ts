import type { JsonObject, JsonValue } from '../client/types.js';
import { resolveIconForEvent } from './EventIconResolver.js';
import { resolveSourceForEvent } from './EventSourceResolver.js';

export interface EventEnvelope extends JsonObject {
  organizationId: string;
  clientId: string;
  projectId: string | null;
  integrationId: string | null;
  projectSourceId: string | null;
  clientSourceId: string | null;
  channel: string;
  event: string;
  timestamp: string;
  source: string | null;
  description: string | null;
  icon: string | null;
  schemaVersion: string;
  isLifecycle: boolean;
  entityType: string | null;
  externalEntityId: string | null;
  externalEventId: string | null;
  state: string | null;
  stateChangedAt: string | null;
  properties: JsonObject;
  tags: JsonObject;
}

export type EventEnvelopeInput = Partial<EventEnvelope> & Pick<
  EventEnvelope,
  'organizationId' | 'clientId' | 'channel' | 'event' | 'timestamp'
>;

export class EventEnvelopeBuilder {
  static build(input: EventEnvelopeInput): EventEnvelope {
    const requiredFields: Array<keyof EventEnvelopeInput> = [
      'organizationId',
      'clientId',
      'channel',
      'event',
      'timestamp',
    ];

    for (const field of requiredFields) {
      const value = input[field];
      if (value === undefined || value === null || value === '') {
        throw new Error(`Missing required field: ${field}`);
      }
    }

    return {
      organizationId: String(input.organizationId),
      clientId: String(input.clientId),
      projectId: input.projectId !== undefined && input.projectId !== null ? String(input.projectId) : null,
      integrationId: input.integrationId !== undefined && input.integrationId !== null ? String(input.integrationId) : null,
      projectSourceId:
        input.projectSourceId !== undefined && input.projectSourceId !== null
          ? String(input.projectSourceId)
          : null,
      clientSourceId:
        input.clientSourceId !== undefined && input.clientSourceId !== null ? String(input.clientSourceId) : null,
      channel: String(input.channel),
      event: String(input.event),
      timestamp: String(input.timestamp),
      source:
        input.source !== undefined && input.source !== null && String(input.source).trim() !== ''
          ? String(input.source)
          : resolveSourceForEvent(input as unknown as JsonObject),
      description:
        input.description !== undefined && input.description !== null ? String(input.description) : null,
      icon:
        input.icon !== undefined && input.icon !== null
          ? String(input.icon)
          : resolveIconForEvent(String(input.channel), String(input.event)),
      schemaVersion: input.schemaVersion !== undefined ? String(input.schemaVersion) : '1',
      isLifecycle: input.isLifecycle === true,
      entityType: input.entityType !== undefined && input.entityType !== null ? String(input.entityType) : null,
      externalEntityId:
        input.externalEntityId !== undefined && input.externalEntityId !== null
          ? String(input.externalEntityId)
          : null,
      externalEventId:
        input.externalEventId !== undefined && input.externalEventId !== null ? String(input.externalEventId) : null,
      state: input.state !== undefined && input.state !== null ? String(input.state) : null,
      stateChangedAt:
        input.stateChangedAt !== undefined && input.stateChangedAt !== null ? String(input.stateChangedAt) : null,
      properties: isJsonObject(input.properties) ? input.properties : {},
      tags: isJsonObject(input.tags) ? input.tags : {},
    };
  }
}

function isJsonObject(value: JsonValue | undefined): value is JsonObject {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}
