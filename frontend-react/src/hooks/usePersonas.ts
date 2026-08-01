import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import client from '@/api/client';
import { ENDPOINTS } from '@/api/endpoints';
import type { PersonaSummary, PersonaDetail } from '@/types/api';

export function usePersonaPerformance(code: string) {
  return useQuery<PersonaSummary>({
    queryKey: ['persona-performance', code],
    queryFn: async () => {
      const { data } = await client.get<{ success: boolean; data: PersonaSummary }>(
        ENDPOINTS.scambaiting.personaPerformance(code),
      );
      return data.data;
    },
    enabled: !!code,
  });
}

export function useAllPersonaPerformances(personaCodes: string[]) {
  return useQuery<PersonaSummary[]>({
    queryKey: ['all-persona-performances', personaCodes],
    queryFn: async () => {
      const results = await Promise.all(
        personaCodes.map(async (code) => {
          try {
            const { data } = await client.get<{ success: boolean; data: PersonaSummary }>(
              ENDPOINTS.scambaiting.personaPerformance(code),
            );
            return data.success ? data.data : null;
          } catch {
            return null;
          }
        }),
      );
      return results.filter((r): r is PersonaSummary => r !== null);
    },
    enabled: personaCodes.length > 0,
  });
}

export function usePersonaDetail(code: string | null) {
  return useQuery<PersonaDetail>({
    queryKey: ['persona-detail', code],
    queryFn: async () => {
      const { data } = await client.get<{ success: boolean; data: PersonaDetail }>(
        ENDPOINTS.personas.detail(code!),
      );
      return data.data;
    },
    enabled: !!code,
  });
}

export interface CreatePersonaInput {
  persona_code: string;
  persona_label: string;
  persona_tone: string;
  system_prompt: string;
  scam_type_codes?: string[];
}

export function useCreatePersona() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (body: CreatePersonaInput) => {
      const { data } = await client.post<{ success: boolean; data: PersonaDetail }>(
        ENDPOINTS.personas.create,
        body,
      );
      return data.data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['all-persona-performances'] });
      queryClient.invalidateQueries({ queryKey: ['meta-config'] });
    },
  });
}

export function useUpdatePersona() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({
      code,
      updates,
    }: {
      code: string;
      updates: {
        persona_label?: string;
        persona_tone?: string;
        system_prompt?: string;
        // When true, the persona's bandit stats are cleared so a prompt change
        // does not bias exploration (see PersonaDetailPanel warning).
        reset_stats?: boolean;
      };
    }) => {
      const { data } = await client.put<{ success: boolean; data: PersonaDetail }>(
        ENDPOINTS.personas.update(code),
        updates,
      );
      return data.data;
    },
    onSuccess: (_data, variables) => {
      queryClient.invalidateQueries({ queryKey: ['persona-detail', variables.code] });
      queryClient.invalidateQueries({ queryKey: ['all-persona-performances'] });
      queryClient.invalidateQueries({ queryKey: ['meta-config'] });
    },
  });
}

export function useTogglePersonaActive() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({ code, active }: { code: string; active: boolean }) => {
      const { data } = await client.patch<{ success: boolean; data: { persona_code: string; is_active: boolean } }>(
        ENDPOINTS.personas.toggleActive(code),
        { active },
      );
      return data.data;
    },
    onSuccess: (_data, variables) => {
      queryClient.invalidateQueries({ queryKey: ['persona-detail', variables.code] });
      queryClient.invalidateQueries({ queryKey: ['all-persona-performances'] });
      queryClient.invalidateQueries({ queryKey: ['meta-config'] });
    },
  });
}
