export interface ThreatActorProfile {
  name: string;
  description: string;
  sophistication: 'none' | 'minimal' | 'intermediate' | 'advanced';
  goals: string[];
  primaryMotivation: string;
  threatActorTypes: string[];
  firstSeen: string;
  lastSeen: string;
  scamType: string;
  personaUsed: string;
  engagementHours: number;
  engagementTurns: number;
  iocTypeCount: number;
  attackPattern: AttackPatternInfo | null;
  // Consolidated (clustered) actors describe a GROUP, not one session: they carry no
  // per-session engagement/IOC-diversity. These fields are populated only for clusters
  // (clusterType === 'consolidated'); single-conversation actors leave them null/0/[].
  clusterType: string | null;
  conversationCount: number;
  anchorIocTypes: string[];
}

export interface AttackPatternInfo {
  name: string;
  techniqueId: string;
  url: string;
}

export interface ThreatActorPsychProfile {
  cluster_id: string;
  dominant_lever: string;
  secondary_levers: string[];
  behavioural_summary: string;
  escalation_pattern: string;
  victim_targeting: string;
  dominant_stimulus: string | null;
  avg_urgency: number;
  hesitation_events: number;
  language_switches: number;
  conversation_count: number;
  message_count: number;
  generated_by_model: string;
  prompt_version: string;
  generated_at: string;
}

/** Temporal / burst / cadence analysis for a threat-actor cluster (GET /clusters/{id}/temporal). */
export interface ClusterTemporal {
  message_count: number;
  active_days: number;
  first_activity: string | null;
  last_activity: string | null;
  active_span_days: number;
  hour_of_day_histogram: Record<string, number>;
  peak_hour: number | null;
  day_of_week_histogram: Record<string, number>;
  peak_day_of_week: number | null;
  median_gap_hours: number | null;
  busiest_day: string | null;
  max_messages_per_day: number;
  burst_days: string[];
  burst_count: number;
  longest_dormancy_hours: number | null;
}

export interface AbuseReportIndicator {
  type: string;
  value: string;
  recommended_recipient: string;
  conv_count: number;
  first_observed: string | null;
  last_observed: string | null;
}

/** Abuse / takedown report for a threat-actor cluster (GET /clusters/{id}/abuse-report). */
export interface ClusterAbuseReport {
  report_type: string;
  generated_from: string;
  actor: {
    cluster_id: string;
    stix_id: string;
    name: string;
    sophistication: string | null;
    first_seen: string | null;
    last_seen: string | null;
  };
  scam_types: string[];
  evidence: {
    conversation_count: number;
    inbound_message_count: number;
    actionable_indicator_count: number;
    /** Total scambaiting time the actor was kept engaged, in seconds (summed across the cluster). */
    criminal_time_wasted_sec: number;
  };
  temporal: ClusterTemporal | null;
  psychological_profile: { dominant_lever?: string; behavioural_summary?: string } | null;
  actionable_indicators: AbuseReportIndicator[];
  narrative: string;
  text: string;
  disclaimer: string;
}
