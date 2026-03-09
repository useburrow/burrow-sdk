import type { HttpResponse, JsonObject } from '../client/types.js';

export interface HttpTransport {
  post(url: string, headers: Record<string, string>, payload: JsonObject): Promise<HttpResponse>;
}
