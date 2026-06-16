import { useQuery } from '@tanstack/react-query';
import client from '@/api/client';
import { ENDPOINTS } from '@/api/endpoints';

interface WeeklyPoint {
  week: string;
  hours: number;
}

interface WastedTime {
  total_hours: number;
  total_conversations: number;
  avg_hours: number;
  max_hours: number;
  longest_scam_type: string | null;
  weekly_trend: WeeklyPoint[];
  // Spec 108 — Scammer Replies Elicited tile. Count of inbound messages
  // (direction='in') in qualified conversations (turns_count >= 2).
  // Replaces the time-based Engagement Time headline with a direct,
  // uninferred count. prev/delta are null on period=all (no "vs previous
  // period" framing on cumulative All), same pattern as fresh_iocs_*.
  scammer_replies_count: number;
  scammer_replies_prev_count: number | null;
  scammer_replies_delta_pct: number | null;
}

interface IocTypeEntry {
  type: string;
  count: number;
}

interface IocValue {
  total_iocs: number;
  novel_iocs: number;
  novel_pct: number;
  // Spec 106 — Fresh IOCs tile, replaces the misleading Novel % tile.
  // When period is 7d/30d/90d the tile shows "Fresh IOCs (last Nd)" with
  // delta vs the previous window. When period='all', all fresh_* fields
  // are null and the tile switches to its "Total IOCs" face, rendering
  // `total_iocs` instead — consistent with how the other tiles behave
  // on All (cumulative state, no trend).
  fresh_iocs_count: number | null;
  fresh_iocs_prev_count: number | null;
  fresh_iocs_delta_pct: number | null;
  fresh_iocs_window_days: number | null;
  financial_iocs: number;
  high_confidence_iocs: number;
  by_type: IocTypeEntry[];
}

interface CostEfficiency {
  total_cost_usd: number;
  cost_per_ioc_usd: number;
  cost_per_hour_wasted_usd: number;
  current_month_usd: number;
  previous_month_usd: number;
  month_delta_pct: number;
}

interface TopCampaign {
  campaign_id: string;
  status: string;
  severity: string;
  first_seen: string;
  tlp: string;
  conv_count: number;
  ioc_count: number;
  dominant_scam_type: string | null;
}

interface CampaignData {
  total: number;
  promoted: number;
  scam_type_count: number;
  top_campaigns: TopCampaign[];
}

interface TrendDeltas {
  wasted_hours_delta_pct: number | null;
  novel_pct_delta: number | null;
  cost_per_ioc_delta_pct: number | null;
  campaigns_delta: number | null;
}

export interface ImpactSummary {
  wasted_time: WastedTime;
  ioc_value: IocValue;
  cost_efficiency: CostEfficiency;
  campaigns: CampaignData;
  trends: TrendDeltas | null;
}

export function useImpactSummary(period: string = 'all', scamType?: string | null) {
  return useQuery<ImpactSummary>({
    queryKey: ['impact-summary', period, scamType ?? 'all'],
    queryFn: async () => {
      // Spec 096 / C2 — scam_type combines with period (orthogonal filters).
      const params: Record<string, string> = { period };
      if (scamType) params.scam_type = scamType;
      const { data } = await client.get<ImpactSummary>(ENDPOINTS.impact.summary, { params });
      return data;
    },
    staleTime: 300_000,
  });
}

interface IocDailyPoint {
  date: string;
  total: number;
  novel: number;
}

interface IocUniquenessData {
  summary: { total_iocs: number; novel_iocs: number; novel_pct: number };
  by_type: { type: string; total: number; novel: number; novel_pct: number }[];
  daily_trend: IocDailyPoint[];
}

export function useIocUniqueness(period: string = '30d', scamType?: string | null) {
  return useQuery<IocUniquenessData>({
    queryKey: ['impact-ioc-uniqueness', period, scamType ?? 'all'],
    queryFn: async () => {
      // Spec 096 / C3 — scam_type combines with period.
      const params: Record<string, string> = { period };
      if (scamType) params.scam_type = scamType;
      const { data } = await client.get<IocUniquenessData>(ENDPOINTS.impact.iocUniqueness, { params });
      return data;
    },
    staleTime: 300_000,
  });
}

// Spec 096 / C1 — Bias-corrected scammer engagement metric
export interface ScammerEngagementByScamType {
  scam_type: string;
  observable: number;
  responded: number;
  rate_pct: number;
}

export interface ScammerEngagementResponse {
  global: { observable: number; responded: number; rate_pct: number };
  by_scam_type: ScammerEngagementByScamType[];
  params: {
    censoring_hours: number;
    scam_type_filter: string | null;
    noise_subject_patterns: number;
    noise_sender_patterns: number;
    honeypot_addresses: number;
  };
  methodology_note: string;
}

export function useScammerEngagement(
  censoringHours: number = 96,
  scamType?: string | null,
  period: string = 'all',
) {
  const params: Record<string, string | number> = { censoring_hours: censoringHours };
  if (scamType) params.scam_type = scamType;
  // Spec 096 / C2b — period filter combines with scam_type orthogonally.
  if (period && period !== 'all') params.period = period;
  return useQuery<ScammerEngagementResponse>({
    queryKey: ['impact-scammer-engagement', censoringHours, scamType ?? 'all', period],
    queryFn: async () => {
      const { data } = await client.get<ScammerEngagementResponse>(
        ENDPOINTS.monitoring.analyticsScammerEngagement,
        { params },
      );
      return data;
    },
    staleTime: 300_000,
  });
}

export type { WeeklyPoint, IocTypeEntry, TopCampaign };
