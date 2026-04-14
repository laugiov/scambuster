/**
 * Shared mock data for tests.
 *
 * Only data duplicated across 3+ test files lives here.
 * Import and spread-override when a test needs slight variations.
 */

/** Meta configuration — superset with personas, scam types, ioc types */
export const mockMetaConfig = {
  personas: [
    { code: 'elderly_person', label: 'Elderly Person', tone: 'Familiar', active: true },
    { code: 'bank_customer', label: 'Bank Customer', tone: 'Formal', active: true },
  ],
  scam_types: [
    { code: 'PHISHING', label: 'Phishing', description: '', active: true },
    { code: 'ROMANCE', label: 'Romance', description: '', active: true },
  ],
  ioc_types: ['email', 'domain'],
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

/** Autonomy stats — superset with all optional fields */
export const mockStats = {
  status: 'operational',
  conversations: { total: 15, open: 3, active: 3, closed: 10, abandoned: 2 },
  messages: { total: 42, inbound: 20, outbound: 22 },
  iocs: { total: 89, unique_types: 6 },
  convergence: {
    status: 'converging',
    best_persona: 'elderly_person',
    best_score: 0.82,
    exploration_rate: 0.15,
    converged_types: 0,
    total_types: 5,
  },
  kill_switch: false,
  kill_switch_active: false,
  checked_at: new Date().toISOString(),
};

/** Basic conversation list */
export const mockConversations = [
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
