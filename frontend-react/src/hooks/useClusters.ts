import { useQuery } from '@tanstack/react-query';
import client from '@/api/client';
import { ENDPOINTS } from '@/api/endpoints';

export interface Cluster {
  cluster_id: string;
  stix_id: string;
  name: string;
  status: string;
  conversation_count: number;
  anchor_ioc_count: number;
  anchor_ioc_types: string[];
  sophistication: string;
  primary_scam_types: string[];
  first_seen: string | null;
  last_seen: string | null;
}

export interface ClusterStats {
  total_conversations: number;
  clustered_conversations: number;
  singleton_conversations: number;
  total_clusters: number;
  suspect_clusters: number;
  taxii_noise_reduction_pct: number;
  avg_cluster_size: number;
  largest_cluster_size: number;
  anchor_ioc_coverage: Record<string, number>;
  last_clustered_at: string | null;
}

export interface ClusterDetail extends Cluster {
  algorithm_version: string;
  anchor_iocs: Array<{
    indicator_id: string;
    ioc_type: string;
    ioc_value: string;
    ioc_value_norm: string;
    value_norm_hash: string;
    conv_count: number;
    first_observed: string;
    last_observed: string;
    conv_ids: string[];
  }>;
  conversations: Array<{
    conv_id: string;
    status: string;
    score_risk: number;
    ts_first: string;
    ts_last: string;
    scam_type: string;
    linked_at: string;
  }>;
}

export interface ClusterForIoc {
  cluster_id: string;
  stix_id: string;
  name: string;
  status: string;
  conversation_count: number;
  sophistication: string;
}

export function useClusters() {
  return useQuery<Cluster[]>({
    queryKey: ['clusters'],
    queryFn: async () => {
      const { data } = await client.get<Cluster[]>(ENDPOINTS.clusters.list);
      return data;
    },
  });
}

export function useClusterStats() {
  return useQuery<ClusterStats>({
    queryKey: ['cluster-stats'],
    queryFn: async () => {
      const { data } = await client.get<ClusterStats>(ENDPOINTS.clusters.stats);
      return data;
    },
  });
}

export function useClusterDetail(clusterId: string) {
  return useQuery<ClusterDetail>({
    queryKey: ['cluster-detail', clusterId],
    queryFn: async () => {
      const { data } = await client.get<ClusterDetail>(ENDPOINTS.clusters.detail(clusterId));
      return data;
    },
    enabled: !!clusterId,
  });
}

export function useClusterForIoc(indicatorId: string) {
  return useQuery<ClusterForIoc | null>({
    queryKey: ['cluster-for-ioc', indicatorId],
    queryFn: async () => {
      try {
        const { data } = await client.get<ClusterForIoc>(ENDPOINTS.clusters.forIoc(indicatorId));
        return data;
      } catch {
        return null;
      }
    },
    enabled: !!indicatorId,
  });
}
