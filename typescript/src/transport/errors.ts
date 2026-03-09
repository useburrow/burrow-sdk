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

  constructor(
    public readonly endpointPath: string,
    public readonly status: number,
    public readonly body: JsonObject | null,
    public readonly rawBody: string
  ) {
    super(`Burrow endpoint ${endpointPath} returned status ${status}.`);
    this.retryable = status >= 500;
  }
}
