import { useQuery } from '@tanstack/react-query';
import client from '@/api/client';
import { ENDPOINTS } from '@/api/endpoints';
import type { ConversationLifecycleStats } from '@/types/api';

export function useConversationLifecycle() {
  return useQuery<ConversationLifecycleStats>({
    queryKey: ['conversation-lifecycle'],
    queryFn: async () => {
      const { data } = await client.get<ConversationLifecycleStats>(ENDPOINTS.monitoring.lifecycle);
      return data;
    },
    staleTime: 30_000,
    refetchInterval: 60_000,
  });
}
