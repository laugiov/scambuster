import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
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

// Spec 065b — kill switch state hook
export interface KillSwitchState {
  active: boolean;
}

export function useKillSwitchState() {
  return useQuery<KillSwitchState>({
    queryKey: ['llm-kill-switch'],
    queryFn: async () => {
      const { data } = await client.get<KillSwitchState>(ENDPOINTS.admin.llmKillSwitch);
      return data;
    },
    staleTime: 15_000,
    refetchInterval: 30_000,
  });
}

// Spec 065b — kill switch toggle mutation
export function useToggleKillSwitch() {
  const queryClient = useQueryClient();
  return useMutation<KillSwitchState, Error, boolean>({
    mutationFn: async (active: boolean) => {
      const { data } = await client.post<KillSwitchState>(ENDPOINTS.admin.llmKillSwitch, { active });
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['llm-kill-switch'] });
    },
  });
}
