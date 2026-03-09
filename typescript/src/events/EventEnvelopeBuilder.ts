import type { JsonObject, JsonValue } from '../client/types.js';

export interface EventEnvelope extends JsonObject {
  organizationId: string;
  clientId: string;
  projectId: string | null;
  projectSourceId: string | null;
  channel: string;
  event: string;
  timestamp: string;
  source: string | null;
  description: string | null;
  schemaVersion: string;
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
      projectSourceId:
        input.projectSourceId !== undefined && input.projectSourceId !== null
          ? String(input.projectSourceId)
          : null,
      channel: String(input.channel),
      event: String(input.event),
      timestamp: String(input.timestamp),
      source: input.source !== undefined && input.source !== null ? String(input.source) : null,
      description:
        input.description !== undefined && input.description !== null ? String(input.description) : null,
      schemaVersion: input.schemaVersion !== undefined ? String(input.schemaVersion) : '1',
      properties: isJsonObject(input.properties) ? input.properties : {},
      tags: isJsonObject(input.tags) ? input.tags : {},
    };
  }
}

function isJsonObject(value: JsonValue | undefined): value is JsonObject {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}
