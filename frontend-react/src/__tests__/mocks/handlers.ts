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
    epsilon: 0.2,
    cold_start_threshold: 3,
    convergence_threshold: 0.6,
    min_sessions_for_convergence: 10,
    converged_epsilon: 0.05,
    reward_weights: { duration: 0.4, iocs_total: 0.25, iocs_sensibles: 0.25, completion: 0.1 },
  },
  llm_provider: 'openai',
  llm_model: 'gpt-4o-mini',
};

export const mockAutonomyStats: AutonomyStats = {
  status: 'operational',
  conversations: { total: 15, active: 10, closed: 3, abandoned: 2 },
  messages: { total: 42, inbound: 20, outbound: 22 },
  iocs: { total: 89, unique_types: 6 },
  convergence: {
    status: 'converging',
    best_persona: 'elderly_person',
    best_score: 0.82,
    exploration_rate: 0.15,
  },
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
    ioc_extraction: { cost_usd: 1.56789, calls: 290 },
    conversation_analysis: { cost_usd: 0.987654, calls: 98 },
    injection_detection: { cost_usd: 0.430877, calls: 44 },
  },
  daily_trend: [
    { date: '2026-03-22', cost_usd: 1.234567, calls: 312 },
    { date: '2026-03-21', cost_usd: 2.1, calls: 287 },
    { date: '2026-03-20', cost_usd: 1.89, calls: 301 },
  ],
  limit_exceeded: false,
};

const emptyTimeSeries = { period_days: 30, data: [] };
const emptyDistribution = { data: [] };

export const mockImpactSummary = {
  period_days: 30,
  conversations: { total: 25, active: 10, closed: 12, abandoned: 3 },
  messages: { total: 180, inbound: 90, outbound: 90 },
  iocs: { total: 320, unique: 210, sensitive: 45 },
  cost: { total_usd: 5.2, per_ioc_usd: 0.016 },
  engagement: { avg_duration_sec: 43200, max_duration_sec: 172800 },
};

export const mockConvergenceHistory = {
  history: [
    {
      date: '2026-03-20',
      scam_type: 'PHISHING',
      persona: 'elderly_person',
      selection_share: 0.65,
      sessions: 12,
    },
  ],
};

export const mockLifecycle = {
  total: 25,
  by_status: { open: 10, closed: 12, abandoned: 3 },
  avg_duration_sec: 43200,
  median_turns: 6,
};

export const mockRateLimits = {
  violations_today: { login_ip: 0, api_global: 2, llm_calls: 0 },
};

export const handlers = [
  http.get(`${BASE}/meta/config`, () => HttpResponse.json(mockMetaConfig)),
  http.get(`${BASE}/monitoring/autonomy`, () => HttpResponse.json(mockAutonomyStats)),
  http.get(`${BASE}/communication/conversation`, () => HttpResponse.json(mockConversations)),
  http.get(`${BASE}/monitoring/llm-cost`, () => HttpResponse.json(mockLlmCostReport)),
  http.post(`${BASE}/auth/login`, () =>
    HttpResponse.json({
      access_token: 'mock-jwt-token',
      refresh_token: 'mock-refresh-token',
      expires_in: 3600,
    }),
  ),
  // Analytics endpoints
  http.get(`${BASE}/monitoring/analytics/ioc-timeline`, () => HttpResponse.json(emptyTimeSeries)),
  http.get(`${BASE}/monitoring/analytics/conversation-timeline`, () =>
    HttpResponse.json(emptyTimeSeries),
  ),
  http.get(`${BASE}/monitoring/analytics/ioc-distribution`, () =>
    HttpResponse.json(emptyDistribution),
  ),
  http.get(`${BASE}/monitoring/analytics/scam-distribution`, () =>
    HttpResponse.json(emptyDistribution),
  ),
  http.get(`${BASE}/monitoring/analytics/cost-timeline`, () => HttpResponse.json(emptyTimeSeries)),
  http.get(`${BASE}/monitoring/analytics/pipeline-timeline`, () =>
    HttpResponse.json(emptyTimeSeries),
  ),
  http.get(`${BASE}/monitoring/analytics/activity-feed`, () => HttpResponse.json({ events: [] })),
  http.get(`${BASE}/monitoring/analytics/weekly-trends`, () => HttpResponse.json({ trends: [] })),
  // Impact
  http.get(`${BASE}/impact/summary`, () => HttpResponse.json(mockImpactSummary)),
  http.get(`${BASE}/impact/ioc-uniqueness`, () => HttpResponse.json({ data: [] })),
  // Convergence
  http.get(`${BASE}/monitoring/convergence-history`, () =>
    HttpResponse.json(mockConvergenceHistory),
  ),
  // Lifecycle + Rate limits
  http.get(`${BASE}/monitoring/conversation-lifecycle`, () => HttpResponse.json(mockLifecycle)),
  http.get(`${BASE}/monitoring/rate-limits`, () => HttpResponse.json(mockRateLimits)),
  // Conversation detail
  http.get(`${BASE}/communication/conversation/:id`, () => HttpResponse.json(mockConversations[0])),
  http.get(`${BASE}/communication/conversation/:id/messages`, () => HttpResponse.json([])),
  http.get(`${BASE}/communication/conversation/:id/iocs`, () => HttpResponse.json([])),
  // Scambaiting
  http.get(`${BASE}/scambaiting/stats`, () => HttpResponse.json([])),
  // Campaign
  http.get(`${BASE}/campaign/candidates`, () => HttpResponse.json({ candidates: [] })),
  // IOCs
  http.get(`${BASE}/iocs`, () => HttpResponse.json([])),
  // Personas
  http.get(`${BASE}/scambaiting/persona/:code/performance`, () =>
    HttpResponse.json({
      success: true,
      data: { persona_code: 'elderly_person', sessions: 5, reward_avg: 0.72 },
    }),
  ),
  // Pipeline
  http.get(`${BASE}/monitoring/pipeline-health`, () =>
    HttpResponse.json({ status: 'healthy', metrics: {} }),
  ),
  http.get(`${BASE}/monitoring/pipeline-traces`, () => HttpResponse.json({ traces: [] })),
  // Stix
  http.get(`${BASE}/conversations/:id/export/stix`, () =>
    HttpResponse.json({ type: 'bundle', objects: [] }),
  ),
];
