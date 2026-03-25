import { useQuery } from '@tanstack/react-query';
import client from '@/api/client';
import { ENDPOINTS } from '@/api/endpoints';
import type { Ioc } from '@/types/api';

export function useAllIocs() {
  return useQuery<Ioc[]>({
    queryKey: ['all-iocs'],
    queryFn: async () => {
      const { data } = await client.get<Ioc[]>(ENDPOINTS.iocs.list);
      return data;
    },
    staleTime: 60_000,
    refetchOnWindowFocus: false,
  });
}
