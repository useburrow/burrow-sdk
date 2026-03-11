import type { JsonObject, JsonValue } from '../client/types.js';

export interface OnboardingIngestionKey {
  key: string;
  keyPrefix: string | null;
  scope: string | null;
  projectId: string | null;
}

export interface OnboardingLinkedProject {
  id: string;
  name: string | null;
  slug: string | null;
  clientId: string | null;
  clientName: string | null;
  clientSlug: string | null;
  burrowProjectPath: string | null;
  burrowProjectUrl: string | null;
}

export interface LinkedProjectDeepLink {
  path: string | null;
  url: string | null;
}

export interface OnboardingLinkResponse {
  routing: JsonObject;
  ingestionKey: OnboardingIngestionKey | null;
  project: OnboardingLinkedProject | null;
}

export function parseOnboardingLinkResponse(body: JsonObject | null): OnboardingLinkResponse {
  const payload = body ?? {};
  const routing = isJsonObject(payload.routing) ? payload.routing : {};

  const ingestionKey = parseIngestionKey(payload.ingestionKey);
  const project = parseLinkedProject(payload.project);

  return {
    routing,
    ingestionKey,
    project,
  };
}

export function toLinkedProjectDeepLink(response: OnboardingLinkResponse): LinkedProjectDeepLink | null {
  if (!response.project) {
    return null;
  }

  return {
    path: response.project.burrowProjectPath,
    url: response.project.burrowProjectUrl,
  };
}

export function isProjectScopedIngestionKey(value: OnboardingIngestionKey | null): value is OnboardingIngestionKey {
  return value !== null && value.scope === 'project' && typeof value.projectId === 'string' && value.projectId.length > 0;
}

function parseIngestionKey(value: JsonValue | undefined): OnboardingIngestionKey | null {
  if (!isJsonObject(value)) {
    return null;
  }

  const key = readString(value.key);
  if (!key) {
    return null;
  }

  return {
    key,
    keyPrefix: readString(value.keyPrefix),
    scope: readString(value.scope)?.toLowerCase() ?? null,
    projectId: readString(value.projectId),
  };
}

function parseLinkedProject(value: JsonValue | undefined): OnboardingLinkedProject | null {
  if (!isJsonObject(value)) {
    return null;
  }

  const id = readString(value.id);
  if (!id) {
    return null;
  }

  return {
    id,
    name: readString(value.name),
    slug: readString(value.slug),
    clientId: readString(value.clientId),
    clientName: readString(value.clientName),
    clientSlug: readString(value.clientSlug),
    burrowProjectPath: readString(value.burrowProjectPath),
    burrowProjectUrl: readString(value.burrowProjectUrl),
  };
}

function readString(value: JsonValue | undefined): string | null {
  if (typeof value !== 'string') {
    return null;
  }

  const trimmed = value.trim();
  return trimmed.length > 0 ? trimmed : null;
}

function isJsonObject(value: JsonValue | undefined): value is JsonObject {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}
