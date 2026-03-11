import type { JsonObject, JsonValue } from '../client/types.js';

export interface FormsContractMapping {
  contractId: string;
  externalFormId: string | null;
  formHandle: string | null;
  formName: string | null;
  enabled: boolean;
  updatedAt: string | null;
  saved: boolean;
}

export interface FormsContractsResponse {
  projectSourceId: string | null;
  contractsVersion: string | null;
  contractMappings: FormsContractMapping[];
  formsContracts: JsonObject[];
}

export function parseFormsContractsResponse(body: JsonObject | null): FormsContractsResponse {
  const payload = body ?? {};
  const contractMappings = Array.isArray(payload.contractMappings)
    ? payload.contractMappings.filter(isJsonObject).map(parseContractMapping).filter((row): row is FormsContractMapping => row !== null)
    : [];
  const formsContracts = Array.isArray(payload.formsContracts) ? payload.formsContracts.filter(isJsonObject) : [];

  return {
    projectSourceId: readString(payload.projectSourceId),
    contractsVersion: readString(payload.contractsVersion),
    contractMappings,
    formsContracts,
  };
}

export function toFormsContractsFetchPayload(projectId: string, platform: string): JsonObject {
  return {
    platform,
    routing: {
      projectId,
    },
  };
}

function parseContractMapping(value: JsonObject): FormsContractMapping | null {
  const contractId = readString(value.contractId);
  if (!contractId) {
    return null;
  }

  return {
    contractId,
    externalFormId: readString(value.externalFormId),
    formHandle: readString(value.formHandle),
    formName: readString(value.formName),
    enabled: value.enabled === true,
    updatedAt: readString(value.updatedAt),
    saved: value.saved === true,
  };
}

function readString(value: JsonValue | undefined): string | null {
  if (typeof value !== 'string') {
    return null;
  }

  const trimmed = value.trim();
  return trimmed.length > 0 ? trimmed : null;
}

function isJsonObject(value: JsonValue): value is JsonObject {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}
