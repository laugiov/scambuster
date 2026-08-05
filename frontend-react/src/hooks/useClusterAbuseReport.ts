import { useQuery } from '@tanstack/react-query';
import axios from 'axios';
import client from '@/api/client';
import { ENDPOINTS } from '@/api/endpoints';
import type { ClusterAbuseReport } from '@/types/threatActor';

/**
 * Fetch the abuse / takedown report for a cluster. On-demand: pass enabled=true
 * (e.g. on a button click) to run it. Resolves to null on 404 (unknown cluster).
 */
export function useClusterAbuseReport(clusterId: string, enabled: boolean) {
  return useQuery<ClusterAbuseReport | null>({
    queryKey: ['cluster-abuse-report', clusterId],
    queryFn: async () => {
      try {
        const { data } = await client.get<ClusterAbuseReport>(ENDPOINTS.clusters.abuseReport(clusterId));
        return data;
      } catch (error) {
        if (axios.isAxiosError(error) && error.response?.status === 404) {
          return null;
        }
        throw error;
      }
    },
    enabled: enabled && !!clusterId,
    staleTime: 5 * 60 * 1000,
  });
}
