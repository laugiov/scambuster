import { useQuery } from '@tanstack/react-query';
import client from '@/api/client';

/**
 * Corpus median + P75 of the turn at which a financial
 * IOC is first revealed, across closed conversations. Cached for 5
 * minutes since the underlying corpus grows slowly.
 */

export interface FinancialRevealTiming {
  n: number;
  median_turn: number | null;
  p75_turn: number | null;
  median_ratio_pct: number | null;
  p75_ratio_pct: number | null;
}

export function useFinancialRevealTiming() {
  return useQuery<FinancialRevealTiming>({
    queryKey: ['stats', 'financial-reveal-timing'],
    queryFn: async () => {
      const { data } = await client.get<FinancialRevealTiming>('/stats/financial-reveal-timing');
      return data;
    },
    staleTime: 5 * 60 * 1000,
    retry: false,
  });
}
