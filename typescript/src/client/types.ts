import type { HttpTransport } from '../transport/HttpTransport.js';

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
}

export interface BurrowClientOptions {
  baseUrl: string;
  apiKey: string;
  transport: HttpTransport;
}
