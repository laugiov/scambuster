import { useQuery } from '@tanstack/react-query';
import axios from 'axios';
import client from '@/api/client';
import { ENDPOINTS } from '@/api/endpoints';
import type { ThreatActorPsychProfile } from '@/types/threatActor';

/**
 * Fetch the persisted per-cluster threat-actor psychological profile.
 * Resolves to null (not an error) when the cluster has no profile yet.
 */
export function useClusterPsychProfile(clusterId: string) {
  return useQuery<ThreatActorPsychProfile | null>({
    queryKey: ['cluster-psych-profile', clusterId],
    queryFn: async () => {
      try {
        const { data } = await client.get<ThreatActorPsychProfile>(
          ENDPOINTS.clusters.psychProfile(clusterId),
        );
        return data;
      } catch (error) {
        if (axios.isAxiosError(error) && error.response?.status === 404) {
          return null;
        }
        throw error;
      }
    },
    enabled: !!clusterId,
  });
}
