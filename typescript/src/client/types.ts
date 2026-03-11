import type { HttpTransport } from '../transport/HttpTransport.js';
import type { BurrowClientState, BurrowDebugLogEntry } from './BurrowClient.js';

export type JsonPrimitive = string | number | boolean | null;
export type JsonValue = JsonPrimitive | JsonObject | JsonArray;
export interface JsonObject {
  [key: string]: JsonValue;
}
export interface JsonArray extends Array<JsonValue> {}

export interface HttpResponse<TBody = JsonObject | null> {
  status: number;
  body: TBody;
  raw: string;
  headers?: Record<string, string>;
}

export interface BurrowClientOptions {
  baseUrl: string;
  apiKey: string;
  transport: HttpTransport;
  state?: Partial<BurrowClientState>;
  debugLogger?: (entry: BurrowDebugLogEntry) => void;
}
