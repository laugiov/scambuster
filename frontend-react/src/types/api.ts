export interface ApiMeta {
  generated_at: string;
  fixture?: boolean;
}

export interface ApiResponse<T> {
  data: T;
  meta: ApiMeta;
}

export interface LoginRequest {
  email: string;
  password: string;
}

export interface LoginResponse {
  token: string;
  refresh_token: string;
}

export interface RefreshRequest {
  refresh_token: string;
}

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

export interface Conversation {
  conv_id: string;
  status: string;
  score_risk: number;
  persona: string | null;
  scam_type: string | null;
  message_count: number;
  ioc_count: number;
  created_at: string;
  updated_at: string;
}

export interface Message {
  msg_id: string;
  conversation_id: string;
  direction: string;
  body_text: string;
  body_html: string | null;
  headers: Record<string, string>;
  created_at: string;
}

export interface Ioc {
  obs_id: string;
  indicator_id: string;
  indicator_type: string;
  indicator_value: string;
  confidence: number;
  context_observation: Record<string, unknown>;
  message_id: string;
  created_at: string;
}

export interface PersonaPerformance {
  persona_code: string;
  persona_label: string;
  total_conversations: number;
  avg_reward: number;
  best_reward: number;
  total_pulls: number;
  trend: 'up' | 'down' | 'stable';
}

export interface ScambaitingStats {
  scam_type: string;
  total_conversations: number;
  avg_iocs_per_conversation: number;
  avg_engagement_turns: number;
  response_rate: number;
  personas: PersonaPerformance[];
}
