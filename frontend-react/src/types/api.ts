// Domain union types
export type ConversationStatus = 'open' | 'closed' | 'abandoned' | 'mistake';
export type MessageDirection = 'in' | 'out';
export type IocType = 'email' | 'ipv4' | 'ipv6' | 'domain' | 'url' | 'sha256' | 'md5' | 'phone' | 'bitcoin_address' | 'subject' | 'message_id' | 'spf_result' | 'dkim_result' | 'dmarc_result';

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
  by_scam_type: Record<string, {
    active: number;
    about_to_timeout: number;
    policy_timeout_hours: number;
  }>;
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
