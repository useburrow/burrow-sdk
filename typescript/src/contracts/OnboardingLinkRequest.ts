import type { JsonObject, JsonValue } from '../client/types.js';

export interface OnboardingLinkRequest {
  site: JsonObject;
  selection: JsonObject;
}

export function toOnboardingLinkPayload(request: OnboardingLinkRequest): JsonObject {
  return {
    site: request.site as JsonValue,
    selection: request.selection as JsonValue,
  };
}
