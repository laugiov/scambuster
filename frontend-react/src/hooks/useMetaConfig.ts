import { useQuery } from '@tanstack/react-query';
import client from '@/api/client';
import { ENDPOINTS } from '@/api/endpoints';
import type { MetaConfig } from '@/types/api';

export function useMetaConfig() {
  return useQuery<MetaConfig>({
    queryKey: ['meta-config'],
    queryFn: async () => {
      const { data } = await client.get<MetaConfig>(ENDPOINTS.meta.config);
      return data;
    },
    staleTime: 300_000,
    refetchOnWindowFocus: false,
  });
}

/**
 * Get display name for a persona code from the meta config.
 * Returns the code itself if not found.
 */
export function personaDisplayName(config: MetaConfig | undefined, code: string): string {
  if (!config) return code;
  const persona = config.personas.find((p) => p.code === code);
  return persona?.label ?? code;
}

/**
 * Get display name for a scam type code from the meta config.
 * Returns the code itself if not found.
 */
export function scamTypeDisplayName(config: MetaConfig | undefined, code: string): string {
  if (!config) return code;
  const st = config.scam_types.find((s) => s.code === code);
  return st?.label ?? code;
}
