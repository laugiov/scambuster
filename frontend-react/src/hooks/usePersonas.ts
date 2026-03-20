import { useQuery } from '@tanstack/react-query';
import client from '@/api/client';
import { ENDPOINTS } from '@/api/endpoints';

interface PersonaSummary {
  persona_code: string;
  persona_label: string;
  total_sessions: number;
  global_avg_reward: number;
  performance_by_scam_type: Array<{
    scam_type_code: string;
    total_pulls: number;
    avg_reward: number;
    best_reward: number;
  }>;
}

interface PersonaListItem {
  persona_code: string;
  persona_label: string;
}

export function usePersonaList() {
  return useQuery<PersonaListItem[]>({
    queryKey: ['persona-list'],
    queryFn: async () => {
      // Fetch from the known persona codes
      const codes = [
        'generic_user', 'bank_customer', 'elderly_person',
        'lonely_person', 'confused_user', 'small_business_owner',
      ];
      const results = await Promise.all(
        codes.map(async (code) => {
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
      return results
        .filter((r): r is PersonaSummary => r !== null)
        .map((p) => ({
          persona_code: p.persona_code,
          persona_label: p.persona_label,
        }));
    },
    staleTime: 300_000,
  });
}

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
    staleTime: 60_000,
  });
}

export function useAllPersonaPerformances() {
  return useQuery<PersonaSummary[]>({
    queryKey: ['all-persona-performances'],
    queryFn: async () => {
      const codes = [
        'generic_user', 'bank_customer', 'elderly_person',
        'lonely_person', 'confused_user', 'small_business_owner',
      ];
      const results = await Promise.all(
        codes.map(async (code) => {
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
    staleTime: 60_000,
  });
}
