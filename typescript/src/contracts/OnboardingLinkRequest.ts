import type { JsonObject, JsonValue } from '../client/types.js';

export interface OnboardingLinkRequest {
  site: JsonObject;
  selection: JsonObject;
  platform?: string;
  capabilities?: JsonObject;
}

export function toOnboardingLinkPayload(request: OnboardingLinkRequest): JsonObject {
  const payload: JsonObject = {
    site: request.site as JsonValue,
    selection: request.selection as JsonValue,
  };

  if (typeof request.platform === 'string' && request.platform.trim().length > 0) {
    payload.platform = request.platform.trim();
  }
  if (request.capabilities && typeof request.capabilities === 'object' && !Array.isArray(request.capabilities)) {
    payload.capabilities = request.capabilities as JsonValue;
  }

  return payload;
}
