import { useQuery } from '@tanstack/react-query';
import client from '@/api/client';
import { ENDPOINTS } from '@/api/endpoints';
import { PERSONA_CODES } from '@/types/api';
import type { PersonaSummary } from '@/types/api';

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

export function useAllPersonaPerformances() {
  return useQuery<PersonaSummary[]>({
    queryKey: ['all-persona-performances'],
    queryFn: async () => {
      const results = await Promise.all(
        PERSONA_CODES.map(async (code) => {
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
  });
}
