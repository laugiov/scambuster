import { useQuery } from '@tanstack/react-query';
import client from '@/api/client';
import { ENDPOINTS } from '@/api/endpoints';
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
