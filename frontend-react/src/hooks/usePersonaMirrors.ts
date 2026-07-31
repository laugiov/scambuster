import { useQuery } from '@tanstack/react-query';
import client from '@/api/client';
import { ENDPOINTS } from '@/api/endpoints';
import type { PersonaMirrorEntry, PersonaMirrorsResponse } from '@/types/api';

/**
 * Cognitive Mirror per persona.
 *
 * Fetches all (scam type) framings for a given persona. The cache is
 * filled offline by `app:persona:compute-mirrors`; an empty array
 * means the batch hasn't been run yet for this persona.
 */
export function usePersonaMirrors(personaCode: string | null | undefined) {
  return useQuery<PersonaMirrorEntry[]>({
    queryKey: ['persona-mirrors', personaCode],
    enabled: !!personaCode,
    queryFn: async () => {
      if (!personaCode) return [];
      const { data } = await client.get<PersonaMirrorsResponse>(
        ENDPOINTS.scambaiting.personaMirrors(personaCode),
      );
      return data.success ? data.data.mirrors : [];
    },
    staleTime: 5 * 60 * 1000,
  });
}
