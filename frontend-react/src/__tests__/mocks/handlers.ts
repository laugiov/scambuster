import { http, HttpResponse } from 'msw';
import type { MetaConfig, AutonomyStats, Conversation } from '@/types/api';

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

export const handlers = [
  http.get(`${BASE}/meta/config`, () => HttpResponse.json(mockMetaConfig)),
  http.get(`${BASE}/monitoring/autonomy`, () => HttpResponse.json(mockAutonomyStats)),
  http.get(`${BASE}/communication/conversation`, () => HttpResponse.json(mockConversations)),
  http.post(`${BASE}/auth/login`, () => HttpResponse.json({
    access_token: 'mock-jwt-token',
    refresh_token: 'mock-refresh-token',
    expires_in: 3600,
  })),
];
