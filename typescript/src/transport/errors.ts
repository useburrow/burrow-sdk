import type { JsonObject } from '../client/types.js';

export class BurrowSdkError extends Error {
  constructor(message: string, options?: ErrorOptions) {
    super(message, options);
    this.name = new.target.name;
  }
}

export class TransportError extends BurrowSdkError {
  readonly retryable = true;
}

export class InvalidJsonResponseError extends BurrowSdkError {
  readonly retryable = false;

  constructor(
    public readonly status: number,
    public readonly rawBody: string,
    options?: ErrorOptions
  ) {
    super('Burrow response was not valid JSON.', options);
  }
}

export class HttpStatusError extends BurrowSdkError {
  readonly retryable: boolean;
  readonly headers: Record<string, string>;

  constructor(
    public readonly endpointPath: string,
    public readonly status: number,
    public readonly body: JsonObject | null,
    public readonly rawBody: string,
    headers: Record<string, string> = {}
  ) {
    super(`Burrow endpoint ${endpointPath} returned status ${status}.`);
    this.headers = headers;
    this.retryable = status === 429 || status >= 500;
  }
}

export interface SdkApiErrorDetails {
  code: string;
  message: string;
  hint?: string | null;
  required?: string[] | null;
  details?: JsonObject | null;
}

export class SdkApiError extends BurrowSdkError {
  constructor(
    public readonly endpointPath: string,
    public readonly status: number,
    public readonly code: string,
    message: string,
    public readonly retryable: boolean,
    public readonly rejected: JsonObject[],
    public readonly apiError: SdkApiErrorDetails | null,
    public readonly rawBody: string,
    public readonly headers: Record<string, string> = {},
    options?: ErrorOptions
  ) {
    super(message, options);
  }
}

export class SdkPreflightError extends BurrowSdkError {
  constructor(
    public readonly code: 'MISSING_INGESTION_KEY' | 'MISSING_PROJECT_ID' | 'MISSING_PROJECT_SOURCE_ID',
    message: string,
    public readonly hint: string,
    options?: ErrorOptions
  ) {
    super(message, options);
  }
}

export function normalizeApiError(
  endpointPath: string,
  status: number,
  body: JsonObject | null,
  rawBody: string,
  headers: Record<string, string> = {}
): SdkApiError {
  const payload = body ?? {};
  const errorNode = asJsonObject(payload.error);
  const topCode = readString(payload.code);
  const nestedCode = readString(errorNode?.code);
  const rejected = Array.isArray(payload.rejected) ? payload.rejected.filter(isJsonObject) : [];
  const code = mapApiErrorCode(status, topCode ?? nestedCode, payload, errorNode);
  const message =
    readString(errorNode?.message) ??
    readString(payload.message) ??
    `Burrow endpoint ${endpointPath} returned status ${status}.`;
  const hint = readString(errorNode?.hint) ?? readString(payload.hint);
  const required = Array.isArray(errorNode?.required)
    ? errorNode.required.filter((value): value is string => typeof value === 'string')
    : null;
  const details = asJsonObject(errorNode?.details);
  const retryable = status === 429 || status >= 500;

  return new SdkApiError(
    endpointPath,
    status,
    code,
    message,
    retryable,
    rejected,
    {
      code,
      message,
      hint,
      required,
      details,
    },
    rawBody,
    headers
  );
}

export function isRetryableSdkError(error: unknown): boolean {
  if (error instanceof SdkApiError || error instanceof TransportError) {
    return error.retryable;
  }
  if (error instanceof HttpStatusError) {
    return error.retryable;
  }
  return false;
}

function mapApiErrorCode(
  status: number,
  responseCode: string | null,
  payload: JsonObject,
  errorNode: JsonObject | null
): string {
  if (status === 401) {
    return 'INVALID_INGESTION_API_KEY';
  }
  if (status === 400) {
    if (responseCode) {
      return responseCode;
    }
    const message = `${readString(errorNode?.message) ?? ''} ${readString(payload.message) ?? ''}`.toLowerCase();
    if (message.includes('attribution') || message.includes('projectsourceid')) {
      return 'FORMS_BACKFILL_ATTRIBUTION_REQUIRED';
    }
    if (message.includes('no events')) {
      return 'NO_EVENTS_PROVIDED';
    }
    if (message.includes('json')) {
      return 'INVALID_JSON_BODY';
    }
  }
  return responseCode ?? 'UNKNOWN_API_ERROR';
}

function asJsonObject(value: unknown): JsonObject | null {
  return typeof value === 'object' && value !== null && !Array.isArray(value) ? (value as JsonObject) : null;
}

function readString(value: unknown): string | null {
  if (typeof value !== 'string') {
    return null;
  }
  const trimmed = value.trim();
  return trimmed === '' ? null : trimmed;
}

function isJsonObject(value: unknown): value is JsonObject {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}
