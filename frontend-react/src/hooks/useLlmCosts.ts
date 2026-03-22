import { useQuery } from '@tanstack/react-query';
import client from '@/api/client';
import { ENDPOINTS } from '@/api/endpoints';
import type { LlmCostReport } from '@/types/api';

export function useLlmCosts() {
  return useQuery<LlmCostReport>({
    queryKey: ['llm-costs'],
    queryFn: async () => {
      const { data } = await client.get<LlmCostReport>(ENDPOINTS.monitoring.llmCost);
      return data;
    },
    staleTime: 30_000,
    refetchInterval: 60_000,
  });
}
