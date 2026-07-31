import { describe, expect, it } from 'vitest';
import { parseOnboardingLinkResponse } from '../src/contracts/OnboardingLinkResponse.js';

describe('parseOnboardingLinkResponse capabilities echo', () => {
  it('parses capabilities including a falsy ecommerce_funnel flag', () => {
    const parsed = parseOnboardingLinkResponse({
      routing: { projectId: 'prj_123' },
      capabilities: {
        forms: ['formie'],
        ecommerce: ['shopify'],
        ecommerce_funnel: false,
      },
    });

    expect(parsed.capabilities).toEqual({
      forms: ['formie'],
      ecommerce: ['shopify'],
      ecommerce_funnel: false,
    });
    expect(parsed.capabilities.ecommerce_funnel).toBe(false);
  });

  it('defaults capabilities to an empty object when missing', () => {
    const parsed = parseOnboardingLinkResponse({
      routing: { projectId: 'prj_123' },
    });

    expect(parsed.capabilities).toEqual({});
  });

  it('defaults capabilities to an empty object when not an object', () => {
    const parsed = parseOnboardingLinkResponse({
      capabilities: 'invalid',
    });

    expect(parsed.capabilities).toEqual({});
  });
});
