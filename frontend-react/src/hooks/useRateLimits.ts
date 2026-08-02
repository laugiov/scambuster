import { useQuery } from '@tanstack/react-query';
import client from '@/api/client';
import { ENDPOINTS } from '@/api/endpoints';

export interface RateLimitStats {
  llm_calls_limit: number;
  active_conversations_limit: number;
  rate_limited_today: Array<{ type: string; count: number }>;
  quarantined_senders_today: number;
}

export function useRateLimits() {
  return useQuery<RateLimitStats>({
    queryKey: ['rate-limits'],
    queryFn: async () => {
      const { data } = await client.get<RateLimitStats>(ENDPOINTS.monitoring.rateLimits);
      return data;
    },
    staleTime: 30_000,
    refetchInterval: 60_000,
  });
}
