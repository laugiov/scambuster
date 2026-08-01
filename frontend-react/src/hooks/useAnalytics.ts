import { useQuery } from '@tanstack/react-query';
import client from '@/api/client';
import { ENDPOINTS } from '@/api/endpoints';

interface TimeSeriesPoint {
  date: string;
  [key: string]: string | number;
}

interface DistributionEntry {
  label: string;
  count: number;
}

interface ActivityEvent {
  event_type: string;
  ref_id: string;
  ts: string;
}

interface WeeklyTrend {
  metric: string;
  current: number;
  previous: number;
  delta_pct: number | null;
}

interface TimeSeriesResponse {
  period_days: number;
  data: TimeSeriesPoint[];
}

interface DistributionResponse {
  data: DistributionEntry[];
}

interface ActivityFeedResponse {
  events: ActivityEvent[];
}

interface WeeklyTrendsResponse {
  trends: WeeklyTrend[];
}

export function useIocTimeline(days = 30) {
  return useQuery<TimeSeriesResponse>({
    queryKey: ['analytics-ioc-timeline', days],
    queryFn: async () => {
      const { data } = await client.get<TimeSeriesResponse>(ENDPOINTS.monitoring.analyticsIocTimeline, {
        params: { days },
      });
      return data;
    },
    staleTime: 60_000,
  });
}

export function useConversationTimeline(days = 30) {
  return useQuery<TimeSeriesResponse>({
    queryKey: ['analytics-conversation-timeline', days],
    queryFn: async () => {
      const { data } = await client.get<TimeSeriesResponse>(ENDPOINTS.monitoring.analyticsConversationTimeline, {
        params: { days },
      });
      return data;
    },
    staleTime: 60_000,
  });
}

export function useIocDistribution() {
  return useQuery<DistributionResponse>({
    queryKey: ['analytics-ioc-distribution'],
    queryFn: async () => {
      const { data } = await client.get<DistributionResponse>(ENDPOINTS.monitoring.analyticsIocDistribution);
      return data;
    },
    staleTime: 60_000,
  });
}

export function useScamDistribution() {
  return useQuery<DistributionResponse>({
    queryKey: ['analytics-scam-distribution'],
    queryFn: async () => {
      const { data } = await client.get<DistributionResponse>(ENDPOINTS.monitoring.analyticsScamDistribution);
      return data;
    },
    staleTime: 60_000,
  });
}

export function useCostTimeline(days = 30) {
  return useQuery<TimeSeriesResponse>({
    queryKey: ['analytics-cost-timeline', days],
    queryFn: async () => {
      const { data } = await client.get<TimeSeriesResponse>(ENDPOINTS.monitoring.analyticsCostTimeline, {
        params: { days },
      });
      return data;
    },
    staleTime: 60_000,
  });
}

export function usePipelineTimeline(days = 30) {
  return useQuery<TimeSeriesResponse>({
    queryKey: ['analytics-pipeline-timeline', days],
    queryFn: async () => {
      const { data } = await client.get<TimeSeriesResponse>(ENDPOINTS.monitoring.analyticsPipelineTimeline, {
        params: { days },
      });
      return data;
    },
    staleTime: 60_000,
  });
}

export function useActivityFeed(limit = 10) {
  return useQuery<ActivityFeedResponse>({
    queryKey: ['analytics-activity-feed', limit],
    queryFn: async () => {
      const { data } = await client.get<ActivityFeedResponse>(ENDPOINTS.monitoring.analyticsActivityFeed, {
        params: { limit },
      });
      return data;
    },
    staleTime: 30_000,
  });
}

export function useWeeklyTrends() {
  return useQuery<WeeklyTrendsResponse>({
    queryKey: ['analytics-weekly-trends'],
    queryFn: async () => {
      const { data } = await client.get<WeeklyTrendsResponse>(ENDPOINTS.monitoring.analyticsWeeklyTrends);
      return data;
    },
    staleTime: 60_000,
  });
}

export type { TimeSeriesPoint, DistributionEntry, ActivityEvent, WeeklyTrend };
