import type { JsonObject } from '../client/types.js';

export type FormsContractSubmissionRequest = JsonObject;

export function toFormsContractSubmissionPayload(request: FormsContractSubmissionRequest): JsonObject {
  return request;
}
