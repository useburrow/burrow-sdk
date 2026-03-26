import type { JsonObject } from '../client/types.js';
import { extractPlatformHint } from './EventSourceResolver.js';

/**
 * Copies the event and merges the client's platform when the event does not
 * already declare a platform (top-level, properties, or tags).
 *
 * If the client is Craft, strips an mistaken `wordpress-plugin` source so
 * ingest resolution can emit `craft-plugin`. Symmetric handling for WordPress.
 *
 * To force a different CMS label on a Craft install, set an explicit `platform`
 * field (or in `properties` / `tags`) on the event so this merge is skipped.
 */
export function applyClientPlatformDefault(event: JsonObject, clientPlatform: string | null | undefined): JsonObject {
  if (typeof clientPlatform !== 'string' || clientPlatform.trim() === '') {
    return event;
  }

  if (extractPlatformHint(event) !== null) {
    return event;
  }

  const norm = clientPlatform.trim().toLowerCase();
  if (norm !== 'craft' && norm !== 'wordpress') {
    return event;
  }

  const out: JsonObject = { ...event };
  const sourceStr = typeof out.source === 'string' ? out.source.trim() : '';

  if (norm === 'craft' && (sourceStr === '' || sourceStr === 'wordpress-plugin')) {
    delete out.source;
    out.platform = 'craft';
  } else if (norm === 'wordpress' && (sourceStr === '' || sourceStr === 'craft-plugin')) {
    delete out.source;
    out.platform = 'wordpress';
  } else if (sourceStr === '') {
    out.platform = norm;
  }

  return out;
}

export function eventNeedsInferredSource(event: JsonObject): boolean {
  const s = event.source;
  return typeof s !== 'string' || s.trim() === '';
}
