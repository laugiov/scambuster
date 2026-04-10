import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { http, HttpResponse } from 'msw';
import type { ReactNode } from 'react';
import { server } from '@/__tests__/mocks/server';
import type { IocDetail as IocDetailType } from '@/types/api';
import { IocDetail } from './IocDetail';
import '../i18n';

const BASE = '/api/v1';

const mockIocDetail: IocDetailType = {
  indicator_id: 'aaa-bbb-ccc',
  type: 'domain',
  value: 'evil-phishing.com',
  value_norm: 'evil-phishing[.]com',
  first_seen: '2026-01-15T10:00:00Z',
  last_seen: '2026-03-20T14:30:00Z',
  occurrences: 5,
  tlp: 'AMBER',
  enrichment: { virustotal: { malicious: 3 } },
  score: { vt: 70, urlscan: 0, agg: 70, explain: 'VT flagged malicious' },
  confidence: 0.95,
  decay_factor: 0.82,
  effective_score: 0.78,
  category: 'Credential_phish',
  misp: { category: 'Network activity', type: 'domain', to_ids: true },
  stix: { sco_type: 'domain-name', pattern: "[domain-name:value = 'evil-phishing.com']" },
  observations: [
    {
      obs_id: 'obs-1',
      msg_id: 'msg-1',
      conv_id: 'conv-1',
      conv_subject: 'Verify your account',
      conv_status: 'open',
      conv_scam_type: 'PHISHING',
      extraction_method: 'llm',
      ts_observed: '2026-01-15T10:00:00Z',
    },
  ],
  related_iocs: [
    {
      indicator_id: 'rel-1',
      type: 'email',
      value_norm: 'support@evil-phishing[.]com',
      score: { vt: 0, urlscan: 0, agg: 0, explain: '' },
      co_occurrence_count: 3,
    },
  ],
};

const mockContextResponse = {
  contexts: [
    {
      obs_id: 'obs-1',
      enrichment_status: 'enriched',
      structural: {
        revelation_turn: 1,
        revelation_turn_ratio: 1.0,
        total_turns: 3,
        engagement_hours: 2.5,
        reward_value: 0.8,
        co_revealed_types: [],
        co_revealed_count: 0,
        scam_type: 'PHISHING',
        scam_type_attck: 'T1566',
        persona_used: 'generic_user',
        extraction_method: 'llm',
      },
      semantic: {
        semantic_role: 'Payment Destination',
        stimulus_type: 'urgency-pressure',
        urgency_score: 0.8,
        language_switch: false,
        hesitation_detected: false,
        context_excerpt: 'Test excerpt',
        enrichment_confidence: 0.9,
      },
    },
  ],
};

const detailHandler = http.get(`${BASE}/iocs/:indicatorId/detail`, () =>
  HttpResponse.json(mockIocDetail)
);

const contextHandler = http.get(`${BASE}/iocs/:indicatorId/context`, () =>
  HttpResponse.json(mockContextResponse)
);

beforeAll(() => server.listen({ onUnhandledRequest: 'bypass' }));
afterEach(() => server.resetHandlers());
afterAll(() => server.close());

function createWrapper(initialEntry: string) {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return function Wrapper({ children }: { children: ReactNode }) {
    return (
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={[initialEntry]}>
          <Routes>
            <Route path="/ioc-explorer/:indicatorId" element={children} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>
    );
  };
}

describe('IocDetail', () => {
  it('renders overview tab with metadata', async () => {
    server.use(detailHandler);
    render(<IocDetail />, { wrapper: createWrapper('/ioc-explorer/aaa-bbb-ccc') });

    await waitFor(() => {
      expect(screen.getByText('evil-phishing.com')).toBeDefined();
    });

    expect(screen.getAllByText(/domain/i).length).toBeGreaterThan(0);
    expect(screen.getAllByText(/TLP:AMBER/).length).toBeGreaterThan(0);
    expect(screen.getByText('Credential Phish')).toBeDefined();
  });

  it('renders observations tab with conversation links', async () => {
    server.use(detailHandler);
    render(<IocDetail />, { wrapper: createWrapper('/ioc-explorer/aaa-bbb-ccc') });

    await waitFor(() => {
      expect(screen.getByText('evil-phishing.com')).toBeDefined();
    });

    // Click observations tab
    const obsTab = screen.getByText(/Observations/);
    obsTab.click();

    await waitFor(() => {
      expect(screen.getByText('Verify your account')).toBeDefined();
    });

    expect(screen.getByText('Phishing')).toBeDefined();
    expect(screen.getByText('LLM')).toBeDefined();
  });

  it('renders related IOCs tab', async () => {
    server.use(detailHandler);
    render(<IocDetail />, { wrapper: createWrapper('/ioc-explorer/aaa-bbb-ccc') });

    await waitFor(() => {
      expect(screen.getByText('evil-phishing.com')).toBeDefined();
    });

    // Click related tab
    const relTab = screen.getByText(/Related IOCs/);
    relTab.click();

    await waitFor(() => {
      expect(screen.getByText('support@evil-phishing[.]com')).toBeDefined();
    });

    expect(screen.getByText('3')).toBeDefined();
  });

  it('renders Behavioral Signals "Not detected" without bullet prefix character', async () => {
    server.use(detailHandler, contextHandler);
    render(<IocDetail />, { wrapper: createWrapper('/ioc-explorer/aaa-bbb-ccc') });

    await waitFor(() => {
      expect(screen.getByText('evil-phishing.com')).toBeDefined();
    });

    // Click Context tab
    const ctxTab = screen.getByText(/Context/);
    ctxTab.click();

    // Wait for "Not detected" to appear (hesitation + language switch)
    await waitFor(() => {
      const matches = screen.getAllByText('Not detected');
      expect(matches.length).toBeGreaterThanOrEqual(2);
    });

    // Assert no element contains "= Not detected" or "○ Not detected"
    expect(screen.queryByText(/=\s*Not detected/)).toBeNull();
    expect(screen.queryByText(/○\s*Not detected/)).toBeNull();
  });

  it('shows 404 for nonexistent indicator', async () => {
    server.use(
      http.get(`${BASE}/iocs/:indicatorId/detail`, () =>
        HttpResponse.json({ error: 'Not found' }, { status: 404 })
      ),
    );

    render(<IocDetail />, { wrapper: createWrapper('/ioc-explorer/nonexistent') });

    await waitFor(() => {
      expect(screen.getByText('IOC not found')).toBeDefined();
    });
  });
});
