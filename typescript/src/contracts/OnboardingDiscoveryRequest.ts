import type { JsonObject, JsonValue } from '../client/types.js';

export interface OnboardingDiscoveryRequest {
  site: JsonObject;
  capabilities: JsonObject;
}

export function toOnboardingDiscoveryPayload(request: OnboardingDiscoveryRequest): JsonObject {
  return {
    site: request.site as JsonValue,
    capabilities: request.capabilities as JsonValue,
  };
}
