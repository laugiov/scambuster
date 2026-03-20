import { useQuery } from '@tanstack/react-query';
import client from '@/api/client';
import { ENDPOINTS } from '@/api/endpoints';
import type { AutonomyStats, ScambaitingStats } from '@/types/api';

export function useAutonomyStats() {
  return useQuery<AutonomyStats>({
    queryKey: ['autonomy-stats'],
    queryFn: async () => {
      const { data } = await client.get<AutonomyStats>(ENDPOINTS.monitoring.autonomy);
      return data;
    },
    staleTime: 30_000,
    refetchInterval: 60_000,
  });
}

export function useScambaitingStats() {
  return useQuery<ScambaitingStats[]>({
    queryKey: ['scambaiting-stats'],
    queryFn: async () => {
      const { data } = await client.get<ScambaitingStats[]>(ENDPOINTS.scambaiting.stats);
      return data;
    },
    staleTime: 30_000,
  });
}
