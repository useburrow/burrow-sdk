import { readFileSync } from 'node:fs';
import { existsSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import { EventEnvelopeBuilder } from '../src/events/EventEnvelopeBuilder.js';
import type { JsonObject } from '../src/client/types.js';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);
const repoRoot = resolve(__dirname, '../..');
const contractsDir = resolve(repoRoot, 'spec/contracts');

const requiredEnvelopeKeys = [
  'organizationId',
  'clientId',
  'projectId',
  'integrationId',
  'projectSourceId',
  'clientSourceId',
  'channel',
  'event',
  'timestamp',
  'source',
  'description',
  'icon',
  'schemaVersion',
  'isLifecycle',
  'entityType',
  'externalEntityId',
  'externalEventId',
  'state',
  'stateChangedAt',
  'properties',
  'tags',
];

describe('spec/contracts fixture alignment', () => {
  it('loads forms contract request fixture', () => {
    const payload = readJsonFixture('forms-contracts.request.json');
    expect(payload.platform).toBeDefined();
    expect(payload.formsContracts).toBeInstanceOf(Array);
  });

  it('validates canonical event fixtures', () => {
    const fixtures: Array<{ name: string; event: string }> = [
      { name: 'event-forms-submission.json', event: 'forms.submission.received' },
      { name: 'event-system-stack-snapshot.json', event: 'system.stack.snapshot' },
      { name: 'event-system-heartbeat-ping.json', event: 'system.heartbeat.ping' },
      { name: 'event-ecommerce-order-placed.json', event: 'ecommerce.order.placed' },
      { name: 'event-ecommerce-item-purchased.json', event: 'ecommerce.item.purchased' },
    ];

    for (const fixture of fixtures) {
      const fixturePath = resolve(contractsDir, fixture.name);
      if (!existsSync(fixturePath)) {
        continue;
      }

      const payload = readJsonFixture(fixture.name);
      expect(payload.event).toBe(fixture.event);
      expect(payload.schemaVersion).toBe('1');

      const built = EventEnvelopeBuilder.build(payload as Parameters<typeof EventEnvelopeBuilder.build>[0]);
      for (const key of requiredEnvelopeKeys) {
        expect(built).toHaveProperty(key);
      }
      expect(built.organizationId).toBe(payload.organizationId);
      expect(built.clientId).toBe(payload.clientId);
      expect(built.channel).toBe(payload.channel);
      expect(built.event).toBe(payload.event);
      expect(built.timestamp).toBe(payload.timestamp);
      expect(built.schemaVersion).toBe('1');
      expect(built.isLifecycle).toBe(false);
      expect(typeof built.icon === 'string' || built.icon === null).toBe(true);
      expect(built.properties).toEqual(payload.properties);
      expect(built.tags).toEqual(payload.tags);
    }
  });
});

function readJsonFixture(name: string): JsonObject {
  const fixturePath = resolve(contractsDir, name);
  const contents = readFileSync(fixturePath, 'utf8');
  return JSON.parse(contents) as JsonObject;
}
