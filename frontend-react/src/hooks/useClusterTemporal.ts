import { useQuery } from '@tanstack/react-query';
import axios from 'axios';
import client from '@/api/client';
import { ENDPOINTS } from '@/api/endpoints';
import type { ClusterTemporal } from '@/types/threatActor';

/**
 * Fetch the temporal / burst / cadence analysis for a cluster.
 * Resolves to null (not an error) when the cluster has no inbound activity (404).
 */
export function useClusterTemporal(clusterId: string) {
  return useQuery<ClusterTemporal | null>({
    queryKey: ['cluster-temporal', clusterId],
    queryFn: async () => {
      try {
        const { data } = await client.get<ClusterTemporal>(ENDPOINTS.clusters.temporal(clusterId));
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
