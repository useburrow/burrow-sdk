import { describe, expect, it } from 'vitest';
import { applyClientPlatformDefault } from '../src/events/applyClientPlatformDefault.js';

describe('applyClientPlatformDefault', () => {
  it('injects craft platform and strips mistaken wordpress-plugin source for Craft clients', () => {
    const base = {
      channel: 'system',
      event: 'system.heartbeat.ping',
      source: 'wordpress-plugin',
    };
    const out = applyClientPlatformDefault(base, 'craft');
    expect(out.source).toBeUndefined();
    expect(out.platform).toBe('craft');
  });

  it('does not override when the event already declares a platform hint', () => {
    const base = {
      channel: 'system',
      event: 'system.heartbeat.ping',
      source: 'wordpress-plugin',
      tags: { platform: 'wordpress' },
    };
    const out = applyClientPlatformDefault(base, 'craft');
    expect(out).toBe(base);
  });

  it('preserves non-CMS sources such as form providers', () => {
    const base = {
      channel: 'forms',
      event: 'forms.submission.received',
      properties: { provider: 'freeform' },
      source: 'freeform',
    };
    const out = applyClientPlatformDefault(base, 'craft');
    expect(out.source).toBe('freeform');
    expect(out.platform).toBeUndefined();
  });
});
