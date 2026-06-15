import { useQuery } from '@tanstack/react-query';
import client from '@/api/client';
import { ENDPOINTS } from '@/api/endpoints';
import type { PersonaMatrixCell, PersonaMatrixResponse } from '@/types/api';

/**
 * Spec 104 P1 — Persona × scam type matrix.
 *
 * Single call backing the matrix grid. Returns one row per active
 * (persona, scam type) pair with the aggregated reward + session
 * count. Cached for 5 minutes — the underlying stats table updates
 * incrementally on each conversation close, not in real-time.
 */
export function usePersonaMatrix() {
  return useQuery<PersonaMatrixCell[]>({
    queryKey: ['persona-matrix'],
    queryFn: async () => {
      const { data } = await client.get<PersonaMatrixResponse>(ENDPOINTS.scambaiting.personaMatrix);
      return data.success ? data.data : [];
    },
    staleTime: 5 * 60 * 1000,
  });
}
