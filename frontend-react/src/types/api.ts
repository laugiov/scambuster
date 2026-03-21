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
  conversations: {
    total: number;
    active: number;
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
    unique_types: number;
  };
  convergence: {
    status: string;
    best_persona: string;
    best_score: number;
    exploration_rate: number;
  };
  kill_switch: boolean;
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

// Persona / Bandit
export const PERSONA_CODES = [
  'generic_user', 'bank_customer', 'elderly_person',
  'lonely_person', 'confused_user', 'small_business_owner',
] as const;

export type PersonaCode = typeof PERSONA_CODES[number];

export const PERSONA_DISPLAY_NAMES: Record<PersonaCode, string> = {
  generic_user: 'Generic User',
  bank_customer: 'Bank Customer',
  elderly_person: 'Retiree',
  lonely_person: 'Lonely Person',
  confused_user: 'Confused User',
  small_business_owner: 'Small Business',
};

export interface PersonaScamTypePerf {
  scam_type_code: string;
  total_pulls: number;
  avg_reward: number;
  best_reward: number;
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
