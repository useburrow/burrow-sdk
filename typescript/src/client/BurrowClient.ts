import type { HttpResponse, JsonObject } from './types.js';
import type { FormsContractSubmissionRequest } from '../contracts/FormsContractSubmissionRequest.js';
import { toFormsContractSubmissionPayload } from '../contracts/FormsContractSubmissionRequest.js';
import type { OnboardingDiscoveryRequest } from '../contracts/OnboardingDiscoveryRequest.js';
import { toOnboardingDiscoveryPayload } from '../contracts/OnboardingDiscoveryRequest.js';
import type { OnboardingLinkRequest } from '../contracts/OnboardingLinkRequest.js';
import { toOnboardingLinkPayload } from '../contracts/OnboardingLinkRequest.js';
import { HttpStatusError } from '../transport/errors.js';
import type { BurrowClientOptions } from './types.js';

export class BurrowClient {
  private readonly baseUrl: string;
  private readonly apiKey: string;
  private readonly transport: BurrowClientOptions['transport'];

  constructor(options: BurrowClientOptions) {
    this.baseUrl = options.baseUrl.replace(/\/+$/, '');
    this.apiKey = options.apiKey.trim();
    this.transport = options.transport;
  }

  async discover(request: OnboardingDiscoveryRequest): Promise<HttpResponse> {
    return this.post('/api/v1/plugin-onboarding/discover', toOnboardingDiscoveryPayload(request));
  }

  async link(request: OnboardingLinkRequest): Promise<HttpResponse> {
    return this.post('/api/v1/plugin-onboarding/link', toOnboardingLinkPayload(request));
  }

  async submitFormsContract(request: FormsContractSubmissionRequest): Promise<HttpResponse> {
    return this.post('/api/v1/plugin-onboarding/forms/contracts', toFormsContractSubmissionPayload(request));
  }

  async publishEvent(event: JsonObject): Promise<HttpResponse> {
    return this.post('/api/v1/events', event, [200, 207]);
  }

  private async post(path: string, payload: JsonObject, acceptedStatuses?: readonly number[]): Promise<HttpResponse> {
    const url = `${this.baseUrl}${path}`;
    const response = await this.transport.post(
      url,
      {
        'x-api-key': this.apiKey,
      },
      payload
    );

    if (acceptedStatuses) {
      if (!acceptedStatuses.includes(response.status)) {
        throw new HttpStatusError(path, response.status, response.body, response.raw);
      }
      return response;
    }

    if (response.status < 200 || response.status >= 300) {
      throw new HttpStatusError(path, response.status, response.body, response.raw);
    }

    return response;
  }
}
