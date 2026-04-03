import { useQuery } from '@tanstack/react-query';
import client from '@/api/client';
import { ENDPOINTS } from '@/api/endpoints';
import type { Ioc, IocDetail, IocGraph } from '@/types/api';

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

export function useIocGraph(indicatorId: string) {
  return useQuery<IocGraph>({
    queryKey: ['ioc-graph', indicatorId],
    queryFn: async () => {
      const { data } = await client.get<IocGraph>(ENDPOINTS.iocs.coOccurrence, {
        params: { indicator_id: indicatorId },
      });
      return data;
    },
    enabled: !!indicatorId,
    staleTime: 60_000,
    refetchOnWindowFocus: false,
  });
}

export function useIocDetail(indicatorId: string) {
  return useQuery<IocDetail>({
    queryKey: ['ioc-detail', indicatorId],
    queryFn: async () => {
      const { data } = await client.get<IocDetail>(ENDPOINTS.iocs.detail(indicatorId));
      return data;
    },
    enabled: !!indicatorId,
    staleTime: 60_000,
    refetchOnWindowFocus: false,
  });
}
