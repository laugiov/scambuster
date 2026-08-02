import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import client from '@/api/client';
import { ENDPOINTS } from '@/api/endpoints';
import type { CanaryJob, LatestCanaryJob, PromptOverrideRow } from '@/types/api';

const LIST_KEY = ['prompt-overrides'];

export function usePromptOverrides() {
  return useQuery<PromptOverrideRow[]>({
    queryKey: LIST_KEY,
    queryFn: async () => {
      const { data } = await client.get<{ success: boolean; data: PromptOverrideRow[] }>(
        ENDPOINTS.promptOverrides.list,
      );
      return data.data;
    },
  });
}

export function useUpsertPromptOverride() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({ key, body, enabled }: { key: string; body: string; enabled: boolean }) => {
      const { data } = await client.put<{ success: boolean; data: PromptOverrideRow }>(
        ENDPOINTS.promptOverrides.upsert(key),
        { body, enabled },
      );
      return data.data;
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: LIST_KEY });
    },
  });
}

export function useDeletePromptOverride() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (key: string) => {
      await client.delete(ENDPOINTS.promptOverrides.remove(key));
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: LIST_KEY });
    },
  });
}

/** Enqueue an async canary validation of an unsaved candidate body; returns the new job id. */
export function useRequestPromptCanary() {
  return useMutation({
    mutationFn: async ({ key, body }: { key: string; body: string }) => {
      const { data } = await client.post<{ success: boolean; data: { job_id: number; status: string } }>(
        ENDPOINTS.promptOverrides.canary(key),
        { body },
      );
      return data.data;
    },
  });
}

/**
 * Fetch the most recent canary job for a key (or null) once on load, so the card can re-attach to
 * a running/recent validation after a refresh. One-shot (not polled): once it re-attaches, the
 * per-job poll {@link useCanaryJob} takes over. Enabled only where a verdict is obtainable.
 */
export function useLatestCanaryJob(key: string, enabled: boolean) {
  return useQuery<LatestCanaryJob | null>({
    queryKey: ['prompt-canary-latest', key],
    enabled,
    staleTime: Infinity,
    refetchOnWindowFocus: false,
    queryFn: async () => {
      const { data } = await client.get<{ success: boolean; data: LatestCanaryJob | null }>(
        ENDPOINTS.promptOverrides.canaryLatest(key),
      );
      return data.data;
    },
  });
}

/**
 * Poll a canary job while it is pending/running. Disabled until a jobId is set; stops polling
 * once the job reaches a terminal state (succeeded/failed).
 */
export function useCanaryJob(jobId: number | null) {
  return useQuery<CanaryJob>({
    queryKey: ['prompt-canary-job', jobId],
    enabled: jobId !== null,
    queryFn: async () => {
      const { data } = await client.get<{ success: boolean; data: CanaryJob }>(
        ENDPOINTS.promptOverrides.canaryJob(jobId as number),
      );
      return data.data;
    },
    refetchInterval: (query) => {
      // Keep polling after a transient status-check failure so it self-recovers instead of
      // silently stopping while the backend job is still running.
      if (query.state.status === 'error') return 5000;
      const status = query.state.data?.status;
      return status === 'pending' || status === 'running' ? 3000 : false;
    },
  });
}
