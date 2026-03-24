import { http, HttpResponse } from 'msw';
import type { MetaConfig, AutonomyStats, Conversation, LlmCostReport } from '@/types/api';

const BASE = '/api/v1';

export const mockMetaConfig: MetaConfig = {
  personas: [
    { code: 'elderly_person', label: 'Personne agee', tone: 'Familier', active: true },
    { code: 'bank_customer', label: 'Client bancaire', tone: 'Formel', active: true },
  ],
  scam_types: [
    { code: 'PHISHING', label: 'Phishing', description: 'Phishing scam', active: true },
    { code: 'ROMANCE', label: 'Romance', description: 'Romance scam', active: true },
  ],
  ioc_types: ['email', 'ipv4', 'domain', 'url', 'sha256', 'phone'],
  bandit: {
    strategy: 'epsilon-greedy',
    epsilon: 0.20,
    cold_start_threshold: 3,
    convergence_threshold: 0.60,
    min_sessions_for_convergence: 10,
    converged_epsilon: 0.05,
    reward_weights: { duration: 0.40, iocs_total: 0.25, iocs_sensibles: 0.25, completion: 0.10 },
  },
  llm_provider: 'openai',
  llm_model: 'gpt-4o-mini',
};

export const mockAutonomyStats: AutonomyStats = {
  status: 'operational',
  conversations: { total: 15, active: 10, closed: 3, abandoned: 2 },
  messages: { total: 42, inbound: 20, outbound: 22 },
  iocs: { total: 89, unique_types: 6 },
  convergence: { status: 'converging', best_persona: 'elderly_person', best_score: 0.82, exploration_rate: 0.15 },
  kill_switch: false,
  checked_at: new Date().toISOString(),
};

export const mockConversations: Conversation[] = [
  {
    conv_id: 'aaaa-bbbb-cccc-dddd',
    status: 'open',
    score_risk: 50,
    persona: 'elderly_person',
    scam_type: 'PHISHING',
    turns: 4,
    ts_first: '2026-03-20T10:00:00Z',
    ts_last: '2026-03-20T12:00:00Z',
  },
  {
    conv_id: 'eeee-ffff-0000-1111',
    status: 'closed',
    score_risk: 80,
    persona: 'bank_customer',
    scam_type: 'ROMANCE',
    turns: 12,
    ts_first: '2026-03-19T08:00:00Z',
    ts_last: '2026-03-19T16:00:00Z',
  },
];

export const mockLlmCostReport: LlmCostReport = {
  current_month: {
    total_usd: 12.345678,
    limit_usd: 50.0,
    pct_used: 24.7,
    calls_count: 1842,
    total_prompt_tokens: 2450000,
    total_completion_tokens: 890000,
  },
  per_purpose: {
    generation: { cost_usd: 5.123456, calls: 620 },
    classification: { cost_usd: 2.345678, calls: 410 },
    validation: { cost_usd: 1.890123, calls: 380 },
    ioc_extraction: { cost_usd: 1.567890, calls: 290 },
    conversation_analysis: { cost_usd: 0.987654, calls: 98 },
    injection_detection: { cost_usd: 0.430877, calls: 44 },
  },
  daily_trend: [
    { date: '2026-03-22', cost_usd: 1.234567, calls: 312 },
    { date: '2026-03-21', cost_usd: 2.100000, calls: 287 },
    { date: '2026-03-20', cost_usd: 1.890000, calls: 301 },
  ],
  limit_exceeded: false,
};

export const handlers = [
  http.get(`${BASE}/meta/config`, () => HttpResponse.json(mockMetaConfig)),
  http.get(`${BASE}/monitoring/autonomy`, () => HttpResponse.json(mockAutonomyStats)),
  http.get(`${BASE}/communication/conversation`, () => HttpResponse.json(mockConversations)),
  http.get(`${BASE}/monitoring/llm-cost`, () => HttpResponse.json(mockLlmCostReport)),
  http.post(`${BASE}/auth/login`, () => HttpResponse.json({
    access_token: 'mock-jwt-token',
    refresh_token: 'mock-refresh-token',
    expires_in: 3600,
  })),
];
