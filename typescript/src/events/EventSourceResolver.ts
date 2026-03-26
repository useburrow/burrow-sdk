import type { JsonObject, JsonValue } from '../client/types.js';

const FORMS_PROVIDER_MAP: Record<string, string> = {
  'gravity-forms': 'gravity-forms',
  gravityforms: 'gravity-forms',
  'fluent-forms': 'fluent-forms',
  fluentforms: 'fluent-forms',
  'contact-form-7': 'contact-form-7',
  contactform7: 'contact-form-7',
  cf7: 'contact-form-7',
  'ninja-forms': 'ninja-forms',
  ninjaforms: 'ninja-forms',
  freeform: 'freeform',
  formie: 'formie',
};

const ECOMMERCE_PROVIDER_MAP: Record<string, string> = {
  woocommerce: 'woocommerce',
  'woo-commerce': 'woocommerce',
  'craft-commerce': 'craft-commerce',
  craftcommerce: 'craft-commerce',
};

export function getDefaultEventSource(platform: string | null | undefined): string {
  const p = platform && typeof platform === 'string' ? platform.trim().toLowerCase() : '';
  return p === 'craft' ? 'craft-plugin' : 'wordpress-plugin';
}

export function resolveSourceForEvent(event: JsonObject): string {
  const channel = readString(event.channel)?.toLowerCase() ?? '';
  const provider = extractProviderHint(event);

  if (channel === 'forms' && provider) {
    const resolved = FORMS_PROVIDER_MAP[normalizeProvider(provider)];
    if (resolved) {
      return resolved;
    }
  }

  if (channel === 'ecommerce' && provider) {
    const resolved = ECOMMERCE_PROVIDER_MAP[normalizeProvider(provider)];
    if (resolved) {
      return resolved;
    }
  }

  return getDefaultEventSource(extractPlatformHint(event));
}

export function extractPlatformHint(event: JsonObject): string | null {
  const direct = readString(event.platform);
  if (direct) {
    return direct.toLowerCase();
  }

  const properties = isJsonObject(event.properties) ? event.properties : null;
  const inProperties = properties ? readString(properties.platform) : null;
  if (inProperties) {
    return inProperties.toLowerCase();
  }

  const tags = isJsonObject(event.tags) ? event.tags : null;
  const inTags = tags ? readString(tags.platform) : null;
  if (inTags) {
    return inTags.toLowerCase();
  }

  return null;
}

function normalizeProvider(provider: string): string {
  return provider.trim().toLowerCase();
}

function extractProviderHint(event: JsonObject): string | null {
  const keys = [
    'provider',
    'providerSlug',
    'integration',
    'integrationSlug',
    'sourcePlugin',
    'plugin',
    'adapter',
    'formProvider',
    'ecommerceProvider',
  ];

  for (const key of keys) {
    const direct = readString(event[key]);
    if (direct) {
      return direct;
    }

    const properties = isJsonObject(event.properties) ? event.properties : null;
    const inProperties = properties ? readString(properties[key]) : null;
    if (inProperties) {
      return inProperties;
    }

    const tags = isJsonObject(event.tags) ? event.tags : null;
    const inTags = tags ? readString(tags[key]) : null;
    if (inTags) {
      return inTags;
    }
  }

  return null;
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
