// Domain union types
export type ConversationStatus = 'open' | 'closed' | 'abandoned' | 'mistake';
export type MessageDirection = 'in' | 'out';
export type IocType =
  | 'email'
  | 'ipv4'
  | 'ipv6'
  | 'domain'
  | 'url'
  | 'sha256'
  | 'md5'
  | 'phone'
  | 'bitcoin_address'
  | 'subject'
  | 'message_id'
  | 'spf_result'
  | 'dkim_result'
  | 'dmarc_result';

// Auth
export interface LoginRequest {
  email: string;
  password: string;
}

export interface LoginResponse {
  access_token: string;
  refresh_token: string;
  expires_in: number;
}

export interface RefreshRequest {
  refresh_token: string;
}

// Monitoring
export interface AutonomyStats {
  status: string;
  kill_switch_active?: boolean;
  kill_switch?: boolean;
  conversations: {
    total: number;
    open?: number;
    active?: number;
    closed: number;
    abandoned: number;
  };
  messages: {
    total: number;
    inbound: number;
    outbound: number;
  };
  iocs: {
    total: number;
    unique_types?: number;
    unique_indicators?: number;
    last_24h?: number;
  };
  convergence: {
    status?: string;
    best_persona?: string;
    best_score?: number;
    exploration_rate?: number;
    converged_types?: number;
    total_types?: number;
    details?: Record<string, boolean>;
  };
  last_activity?: {
    last_inbound?: string;
    last_outbound?: string;
    last_ioc?: string;
  };
  checked_at: string;
}

// Conversations
export interface Conversation {
  conv_id: string;
  status: ConversationStatus;
  score_risk: number;
  persona?: string | null;
  scam_type?: string | null;
  message_count?: number;
  ioc_count?: number;
  ts_first?: string;
  ts_last?: string;
  stix_id?: string;
  turns?: number;
  reward?: number | null;
  channels?: string[];
  created_at?: string;
  updated_at?: string;
}

// Messages
export interface Message {
  message_id: string;
  direction: MessageDirection;
  subject: string | null;
  body_text: string;
  body_html?: string | null;
  ts_msg: string;
  lang_detect?: string;
  external_message_id?: string | null;
}

// IOCs
export interface IocScore {
  vt: number;
  urlscan: number;
  agg: number;
  explain: string;
}

export interface Ioc {
  obs_id: string;
  ioc_id: string;
  type: IocType | string;
  value: string;
  value_norm: string;
  score?: IocScore;
  category: string;
  ts_observed: string;
  context_observation?: Record<string, unknown>;
  confidence?: number;
  decay_factor?: number;
  effective_score?: number;
  has_context?: boolean;
}

// IOC Detail (from GET /iocs/{id}/detail)
export interface IocObservation {
  obs_id: string;
  msg_id: string;
  conv_id: string;
  conv_subject: string | null;
  conv_status: string;
  conv_scam_type: string;
  extraction_method: string;
  ts_observed: string;
}

export interface IocRelated {
  indicator_id: string;
  type: string;
  value_norm: string;
  score: IocScore | Record<string, never>;
  co_occurrence_count: number;
}

export interface IocDetail {
  indicator_id: string;
  type: string;
  value: string;
  value_norm: string;
  first_seen: string;
  last_seen: string;
  occurrences: number;
  tlp: string;
  enrichment: Record<string, unknown>;
  score: IocScore | Record<string, never>;
  confidence: number;
  decay_factor: number;
  effective_score: number;
  category: string;
  misp: { category: string; type: string; to_ids: boolean } | null;
  stix: { sco_type: string; pattern: string } | null;
  observations: IocObservation[];
  related_iocs: IocRelated[];
}

// IOC Context (from GET /iocs/{indicatorId}/context)
export interface IocContextStructural {
  scam_type: string | null;
  attck_technique: string | null;
  persona_code: string | null;
  persona_label: string | null;
  extraction_method: string | null;
  revelation_turn: number | null;
  total_turns: number | null;
  revelation_turn_ratio: number | null;
  engagement_hours: number | null;
  reward_value: number | null;
  co_revealed_types: string[];
  co_revealed_count: number;
  campaign_id: string | null;
}

export interface IocContextSemantic {
  role: string | null;
  stimulus_type: string | null;
  urgency_score: number | null;
  language_switch: boolean | null;
  hesitation_detected: boolean | null;
  context_excerpt: string | null;
  enrichment_confidence: number | null;
  enrichment_model: string | null;
}

export interface IocContextEntry {
  obs_id: string;
  enrichment_status: 'pending' | 'structural' | 'enriched' | 'failed' | 'skipped';
  structural: IocContextStructural;
  semantic: IocContextSemantic | null;
  computed_at: string | null;
}

export interface IocContextResponse {
  indicator_id: string;
  contexts: IocContextEntry[];
}

// IOC Co-occurrence Graph
export interface GraphNode {
  id: string;
  type: string;
  value: string;
  score: number;
  center: boolean;
}

export interface GraphEdge {
  source: string;
  target: string;
  weight: number;
  conversations: string[];
}

export interface IocGraph {
  nodes: GraphNode[];
  edges: GraphEdge[];
}

// Meta config (from GET /meta/config)
export interface MetaPersona {
  code: string;
  label: string;
  tone: string;
  active: boolean;
}

export interface MetaScamType {
  code: string;
  label: string;
  description: string | null;
  active: boolean;
}

export interface MetaBanditConfig {
  strategy: string;
  epsilon: number;
  cold_start_threshold: number;
  convergence_threshold: number;
  min_sessions_for_convergence: number;
  converged_epsilon: number;
  reward_weights: Record<string, number>;
}

export interface MetaConfig {
  personas: MetaPersona[];
  scam_types: MetaScamType[];
  ioc_types: string[];
  bandit: MetaBanditConfig;
  llm_provider: string;
  llm_model: string;
}

// Conversation Lifecycle Monitoring
export interface ConversationLifecycleStats {
  active: number;
  about_to_timeout: number;
  completed_today: number;
  reopened_today: number;
  by_scam_type: Record<
    string,
    {
      active: number;
      about_to_timeout: number;
      policy_timeout_hours: number;
    }
  >;
  about_to_timeout_list: ConversationTimeoutRow[];
}

export interface ConversationTimeoutRow {
  conv_id: string;
  scam_type: string;
  persona: string;
  last_activity: string;
  timeout_hours: number;
  hours_remaining: number;
}

// Persona / Bandit
export interface PersonaScamTypePerf {
  scam_type_code: string;
  sessions_count?: number;
  total_pulls?: number;
  avg_reward?: number;
  reward_avg?: number;
  best_reward?: number;
  is_cold_start?: boolean;
}

export interface PersonaSummary {
  persona_code: string;
  persona_label: string;
  total_sessions: number;
  global_avg_reward: number;
  performance_by_scam_type: PersonaScamTypePerf[];
}

export interface PersonaDetail {
  persona_code: string;
  persona_label: string;
  persona_tone: string;
  system_prompt: string;
  is_active: boolean;
  created_by: string;
  created_at: string;
}

export interface ScambaitingStats {
  scam_type: string;
  total_conversations: number;
  avg_iocs_per_conversation: number;
  avg_engagement_turns: number;
  response_rate: number;
}

// LLM Cost Monitoring
export interface LlmCostMonth {
  total_usd: number;
  limit_usd: number;
  pct_used: number;
  calls_count: number;
  total_prompt_tokens: number;
  total_completion_tokens: number;
}

export interface LlmPurposeCost {
  cost_usd: number;
  calls: number;
}

export interface LlmDailyTrend {
  date: string;
  cost_usd: number;
  calls: number;
}

export interface LlmCostReport {
  current_month: LlmCostMonth;
  per_purpose: Record<string, LlmPurposeCost>;
  daily_trend: LlmDailyTrend[];
  limit_exceeded: boolean;
}
