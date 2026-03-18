export interface ChannelRoutingState {
  projectId: string | null;
  projectSourceIds: Partial<Record<'system' | 'forms' | 'ecommerce' | string, string>>;
  clientId?: string | null;
}

export class ChannelRoutingResolver {
  constructor(private readonly state: ChannelRoutingState) {}

  getRoutingForChannel(channel: string): { projectId: string; projectSourceId: string; clientId: string | null } {
    const projectId = (this.state.projectId ?? '').trim();
    if (projectId === '') {
      throw new Error('projectId is required to route events.');
    }

    const channelKey = channel.trim().toLowerCase();
    const projectSourceId = (this.state.projectSourceIds[channelKey] ?? '').trim();
    if (projectSourceId === '') {
      throw new Error(`Missing projectSourceId for channel "${channelKey}".`);
    }

    return {
      projectId,
      projectSourceId,
      clientId: this.state.clientId ?? null,
    };
  }
}
