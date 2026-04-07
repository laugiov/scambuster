import { useQuery } from '@tanstack/react-query';
import client from '@/api/client';
import { ENDPOINTS } from '@/api/endpoints';
import type { ThreatActorProfile, AttackPatternInfo } from '@/types/threatActor';

interface StixObject {
  type: string;
  id: string;
  name?: string;
  description?: string;
  sophistication?: string;
  goals?: string[];
  primary_motivation?: string;
  threat_actor_types?: string[];
  first_seen?: string;
  last_seen?: string;
  extensions?: Record<string, Record<string, unknown>>;
  external_references?: Array<{ source_name?: string; external_id?: string; url?: string }>;
}

interface StixBundle {
  type: string;
  objects: StixObject[];
}

function extractProfile(bundle: StixBundle): ThreatActorProfile | null {
  const actor = bundle.objects.find((o) => o.type === 'threat-actor');
  if (!actor) return null;

  const attackPattern = bundle.objects.find((o) => o.type === 'attack-pattern');
  let attackPatternInfo: AttackPatternInfo | null = null;

  if (attackPattern) {
    const extRef = attackPattern.external_references?.[0];
    attackPatternInfo = {
      name: attackPattern.name ?? '',
      techniqueId: extRef?.external_id ?? '',
      url: extRef?.url ?? '',
    };
  }

  const ext = actor.extensions?.x_scambuster_actor;

  const styleDna = (ext?.style_dna ?? {}) as Record<string, unknown>;
  const infraDna = (ext?.infra_dna ?? {}) as Record<string, unknown>;

  return {
    name: actor.name ?? '',
    description: actor.description ?? '',
    sophistication: (actor.sophistication as ThreatActorProfile['sophistication']) ?? 'none',
    goals: actor.goals ?? [],
    primaryMotivation: actor.primary_motivation ?? '',
    threatActorTypes: actor.threat_actor_types ?? [],
    firstSeen: actor.first_seen ?? '',
    lastSeen: actor.last_seen ?? '',
    scamType: (ext?.scam_type as string) ?? '',
    personaUsed: (styleDna.persona_used as string) ?? '',
    engagementHours: (infraDna.engagement_hours as number) ?? 0,
    engagementTurns: (styleDna.engagement_turns as number) ?? 0,
    iocTypeCount: (infraDna.ioc_type_count as number) ?? 0,
    attackPattern: attackPatternInfo,
  };
}

export function useThreatActorProfile(convId: string) {
  return useQuery<ThreatActorProfile | null>({
    queryKey: ['threat-actor', convId],
    queryFn: async () => {
      const { data } = await client.get<StixBundle>(ENDPOINTS.conversations.exportStix(convId));
      return extractProfile(data);
    },
    enabled: !!convId,
    staleTime: 120_000,
    refetchOnWindowFocus: false,
  });
}
