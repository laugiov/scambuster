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

export interface ThreatActorSummary {
  conversationCount: number;
  scamTypes: string[];
  maxSophistication: ThreatActorProfile['sophistication'];
  allGoals: string[];
  attackPatterns: string[];
  topActor: ThreatActorProfile;
}

const SOPHISTICATION_RANK: Record<string, number> = { none: 0, minimal: 1, intermediate: 2, advanced: 3 };

export function useThreatActorSummary(convIds: string[]) {
  const uniqueIds = [...new Set(convIds.filter(Boolean))].slice(0, 5); // cap at 5 to limit API calls

  return useQuery<ThreatActorSummary | null>({
    queryKey: ['threat-actor-summary', ...uniqueIds],
    queryFn: async () => {
      const profiles: ThreatActorProfile[] = [];

      for (const cid of uniqueIds) {
        try {
          const { data } = await client.get<StixBundle>(ENDPOINTS.conversations.exportStix(cid));
          const profile = extractProfile(data);
          if (profile) profiles.push(profile);
        } catch {
          // skip failed exports
        }
      }

      if (profiles.length === 0) return null;

      // Find the most sophisticated actor
      const sorted = [...profiles].sort(
        (a, b) => (SOPHISTICATION_RANK[b.sophistication] ?? 0) - (SOPHISTICATION_RANK[a.sophistication] ?? 0),
      );
      const topActor = sorted[0];

      return {
        conversationCount: profiles.length,
        scamTypes: [...new Set(profiles.map((p) => p.scamType))],
        maxSophistication: topActor.sophistication,
        allGoals: [...new Set(profiles.flatMap((p) => p.goals))],
        attackPatterns: [...new Set(profiles.map((p) => p.attackPattern?.name).filter(Boolean) as string[])],
        topActor,
      };
    },
    enabled: uniqueIds.length > 0,
    staleTime: 120_000,
    refetchOnWindowFocus: false,
  });
}
