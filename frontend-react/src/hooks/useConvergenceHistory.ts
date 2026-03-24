import { useQuery } from '@tanstack/react-query';
import client from '@/api/client';
import { ENDPOINTS } from '@/api/endpoints';

export interface ConvergenceEntry {
  date: string;
  dominant_persona: string;
  dominant_pct: number;
  sessions_count: number;
  converged: boolean;
}

export interface ConvergenceHistoryResponse {
  period_days: number;
  by_scam_type: Record<string, ConvergenceEntry[]>;
}

export function useConvergenceHistory() {
  return useQuery<ConvergenceHistoryResponse>({
    queryKey: ['convergence-history'],
    queryFn: async () => {
      const { data } = await client.get<ConvergenceHistoryResponse>(ENDPOINTS.monitoring.convergenceHistory);
      return data;
    },
    staleTime: 60_000,
  });
}
