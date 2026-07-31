import { useQuery } from '@tanstack/react-query';
import client from '@/api/client';

/**
 * Corpus median + P75 of urgency_score across
 * enriched IOCs. Consumed by the Theater UrgencyBar to render a
 * baseline tick alongside the per-IOC value, so the analyst can
 * immediately see whether the IOC in front of them is more or less
 * pressuring than typical.
 *
 * Cached 5 minutes since the corpus grows slowly.
 */
export interface UrgencyCorpusStats {
  n: number;
  median: number | null;
  p75: number | null;
}

export function useUrgencyCorpusStats() {
  return useQuery<UrgencyCorpusStats>({
    queryKey: ['stats', 'urgency-corpus'],
    queryFn: async () => {
      const { data } = await client.get<UrgencyCorpusStats>('/stats/urgency-corpus');
      return data;
    },
    staleTime: 5 * 60 * 1000,
    retry: false,
  });
}
