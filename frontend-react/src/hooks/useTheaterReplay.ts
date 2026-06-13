import { useQuery } from '@tanstack/react-query';
import client from '@/api/client';
import { ENDPOINTS } from '@/api/endpoints';

/**
 * Spec 097 — Live Bait Theater data hook.
 *
 * Reads the composite endpoint /api/v1/communication/conversation/{convId}/theater
 * which returns conv meta + ordered messages + deduplicated IOCs (each
 * enriched with its revelation_context) + a human_factor block split
 * into deterministic vs exploratory_llm_signals.
 */

export interface TheaterMeta {
  conv_id: string;
  scam_type: string;
  scammer_address: string | null;
  persona_address: string | null;
  persona_code: string | null;
  status: string;
  ts_first: string;
  ts_last: string;
  messages_count: number;
  iocs_count: number;
  long_conversation_truncated: boolean;
  enrichment_coverage_pct: number;
}

export interface TheaterMessage {
  idx: number;
  msg_id: string;
  direction: 'in' | 'out';
  ts_msg: string;
  sender: string;
  subject: string | null;
  body_text: string;
  lang_detect: string;
}

export interface TheaterRevelationContext {
  enrichment_status: string;
  enrichment_confidence?: number | null;
  context_excerpt?: string | null;
  semantic_role?: string | null;
  stimulus_type?: 'active' | 'passive' | null;
  urgency_score?: number | null;
  hesitation_detected?: boolean | null;
  co_revealed_types?: string[];
  co_revealed_count?: number;
  stimulus_msg_id?: string | null;
  revelation_turn?: number | null;
  revelation_turn_ratio?: number | null;
}

export interface TheaterIoc {
  msg_id: string;
  obs_id: string;
  indicator_id: string;
  type: string;
  value: string;
  value_norm: string;
  category: 'financial' | 'contact' | 'infrastructure' | 'other';
  ts_observed: string;
  revelation_context: TheaterRevelationContext | null;
}

export interface TheaterCascadeEvent {
  trigger_msg_id: string;
  turn: number;
  yielded_types: string[];
}

export interface TheaterPersonaPressure {
  persona_code: string | null;
  iocs_obtained: number;
  financial_obtained: number;
}

export interface TheaterHumanFactor {
  deterministic: {
    total_turns: number;
    inbound_count: number;
    outbound_count: number;
    engagement_hours: number;
    first_financial_turn: number | null;
    first_financial_ratio: number | null;
    scammer_response_times_hours: number[];
    scammer_response_time_hours_median: number | null;
    cascade_events: TheaterCascadeEvent[];
    language_switch_count: number;
    language_switch_turns: number[];
    persona_pressure_profile: TheaterPersonaPressure;
  };
  exploratory_llm_signals: {
    enrichment_coverage_pct: number;
    enrichment_confidence_avg: number | null;
    enrichment_confidence_median: number | null;
    active_stimuli_count: number;
    iocs_under_active_stimulus: number;
    avg_urgency_at_reveal: number | null;
    hesitation_count: number;
  };
}

export interface TheaterResponse {
  meta: TheaterMeta;
  messages: TheaterMessage[];
  iocs_by_msg: TheaterIoc[];
  human_factor: TheaterHumanFactor;
}

export function useTheaterReplay(convId: string | undefined) {
  return useQuery<TheaterResponse>({
    queryKey: ['theater', convId],
    enabled: Boolean(convId),
    queryFn: async () => {
      if (!convId) throw new Error('convId required');
      const { data } = await client.get<TheaterResponse>(ENDPOINTS.conversations.theater(convId));
      return data;
    },
    staleTime: 300_000,
  });
}
