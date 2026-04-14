import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
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
  type: 'email',
  value: 'scammer@evil.com',
  value_norm: 'scammer@evil[.]com',
  first_seen: '2026-01-15T10:00:00Z',
  last_seen: '2026-03-20T14:30:00Z',
  occurrences: 5,
  tlp: 'RED',
  enrichment: {},
  score: { vt: 0, urlscan: 0, agg: 0, explain: '' },
  confidence: 0.95,
  decay_factor: 0.82,
  effective_score: 0.78,
  category: 'Unknown',
  misp: null,
  stix: null,
  observations: [
    { obs_id: 'obs-1', msg_id: 'msg-1', conv_id: 'conv-1', conv_subject: null, conv_status: 'closed', conv_scam_type: 'PHISHING', extraction_method: 'regex', ts_observed: '2026-01-15T10:00:00Z' },
    { obs_id: 'obs-2', msg_id: 'msg-2', conv_id: 'conv-2', conv_subject: 'Follow up', conv_status: 'abandoned', conv_scam_type: 'ROMANCE', extraction_method: 'header', ts_observed: '2026-02-15T10:00:00Z' },
  ],
  related_iocs: [],
};

const mockUrlIoc: IocDetailType = {
  ...mockIocDetail,
  type: 'url',
  value: 'https://evil.com/phish',
  value_norm: 'https://evil[.]com/phish',
  score: { vt: 70, urlscan: 50, agg: 70, explain: 'VT flagged malicious' },
  observations: [
    { obs_id: 'obs-1', msg_id: 'msg-1', conv_id: 'conv-1', conv_subject: 'Test', conv_status: 'open', conv_scam_type: 'PHISHING', extraction_method: 'llm', ts_observed: '2026-01-15T10:00:00Z' },
    { obs_id: 'obs-2', msg_id: 'msg-2', conv_id: 'conv-2', conv_subject: 'Test2', conv_status: 'open', conv_scam_type: 'PHISHING', extraction_method: 'llm', ts_observed: '2026-02-15T10:00:00Z' },
    { obs_id: 'obs-3', msg_id: 'msg-3', conv_id: 'conv-3', conv_subject: 'Test3', conv_status: 'open', conv_scam_type: 'PHISHING', extraction_method: 'llm', ts_observed: '2026-03-15T10:00:00Z' },
  ],
  related_iocs: [
    { indicator_id: 'rel-1', type: 'domain', value_norm: 'evil[.]com', score: { vt: 50, urlscan: 0 }, co_occurrence_count: 5 },
  ],
  misp: { category: 'Network activity', type: 'url', to_ids: false },
  stix: { sco_type: 'url', pattern: "[url:value = 'https://evil.com/phish']" },
};

const mockContextSkipped = {
  contexts: [
    {
      obs_id: 'obs-1',
      enrichment_status: 'skipped',
      structural: { revelation_turn: null, revelation_turn_ratio: null, total_turns: null, engagement_hours: null, reward_value: null, co_revealed_types: [], co_revealed_count: 0, scam_type: null, scam_type_attck: null, persona_used: null, extraction_method: null },
      semantic: null,
      computed_at: null,
    },
  ],
};

const mockContextPending = {
  contexts: [
    {
      obs_id: 'obs-1',
      enrichment_status: 'pending',
      structural: { revelation_turn: null, revelation_turn_ratio: null, total_turns: null, engagement_hours: null, reward_value: null, co_revealed_types: [], co_revealed_count: 0, scam_type: null, scam_type_attck: null, persona_used: null, extraction_method: null },
      semantic: null,
      computed_at: null,
    },
  ],
};

const mockContextFailed = {
  contexts: [
    {
      obs_id: 'obs-1',
      enrichment_status: 'failed',
      structural: { revelation_turn: 1, revelation_turn_ratio: 0.5, total_turns: 2, engagement_hours: 0, reward_value: null, co_revealed_types: ['domain', 'ipv4'], co_revealed_count: 3, scam_type: 'PHISHING', scam_type_attck: 'T1566', persona_used: 'elderly_person', persona_label: 'Elderly Person', extraction_method: 'llm' },
      semantic: null,
      computed_at: '2026-03-20T10:00:00Z',
    },
  ],
};

const mockContextStructural = {
  contexts: [
    {
      obs_id: 'obs-1',
      enrichment_status: 'structural',
      structural: { revelation_turn: 1, revelation_turn_ratio: 0.5, total_turns: 2, engagement_hours: 2, reward_value: 0.5, co_revealed_types: [], co_revealed_count: 0, scam_type: 'PHISHING', scam_type_attck: 'T1566', persona_used: 'elderly_person', persona_label: 'Elderly Person', extraction_method: 'regex' },
      semantic: null,
      computed_at: '2026-03-20T10:00:00Z',
    },
  ],
};

beforeAll(() => server.listen({ onUnhandledRequest: 'warn' }));
afterEach(() => server.resetHandlers());
afterAll(() => server.close());

function createWrapper(path = '/ioc-explorer/aaa-bbb-ccc') {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return function Wrapper({ children }: { children: ReactNode }) {
    return (
      <QueryClientProvider client={qc}>
        <MemoryRouter initialEntries={[path]}>
          <Routes>
            <Route path="/ioc-explorer/:indicatorId" element={children} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>
    );
  };
}

describe('IocDetail — coverage gaps', () => {
  it('renders email type IOC (non-url/domain path)', async () => {
    server.use(
      http.get(`${BASE}/iocs/:id/detail`, () => HttpResponse.json(mockIocDetail)),
      http.get(`${BASE}/iocs/:id/context`, () => HttpResponse.json({ contexts: [] })),
    );
    render(<IocDetail />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('scammer@evil.com')).toBeInTheDocument();
    });
    // TLP:RED should be shown
    expect(screen.getAllByText(/TLP:RED/).length).toBeGreaterThan(0);
  });

  it('renders URL type with active warning', async () => {
    server.use(
      http.get(`${BASE}/iocs/:id/detail`, () => HttpResponse.json(mockUrlIoc)),
      http.get(`${BASE}/iocs/:id/context`, () => HttpResponse.json({ contexts: [] })),
    );
    render(<IocDetail />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/Active — do not open/)).toBeInTheDocument();
    });
  });

  it('renders Regex and Header extraction methods in observations tab', async () => {
    server.use(
      http.get(`${BASE}/iocs/:id/detail`, () => HttpResponse.json(mockIocDetail)),
      http.get(`${BASE}/iocs/:id/context`, () => HttpResponse.json({ contexts: [] })),
    );
    render(<IocDetail />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('scammer@evil.com')).toBeInTheDocument();
    });
    fireEvent.click(screen.getByText(/Observations/));
    await waitFor(() => {
      expect(screen.getByText('Regex')).toBeInTheDocument();
      expect(screen.getByText('Header')).toBeInTheDocument();
    });
  });

  it('renders conv_id fallback when no subject', async () => {
    server.use(
      http.get(`${BASE}/iocs/:id/detail`, () => HttpResponse.json(mockIocDetail)),
      http.get(`${BASE}/iocs/:id/context`, () => HttpResponse.json({ contexts: [] })),
    );
    render(<IocDetail />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('scammer@evil.com')).toBeInTheDocument();
    });
    fireEvent.click(screen.getByText(/Observations/));
    await waitFor(() => {
      // First observation has null subject, should show conv_id slice
      expect(screen.getByText('conv-1')).toBeInTheDocument();
    });
  });

  it('renders abandoned status badge in observations', async () => {
    server.use(
      http.get(`${BASE}/iocs/:id/detail`, () => HttpResponse.json(mockIocDetail)),
      http.get(`${BASE}/iocs/:id/context`, () => HttpResponse.json({ contexts: [] })),
    );
    render(<IocDetail />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('scammer@evil.com')).toBeInTheDocument();
    });
    fireEvent.click(screen.getByText(/Observations/));
    await waitFor(() => {
      expect(screen.getByText('abandoned')).toBeInTheDocument();
    });
  });

  it('shows no related IOCs message', async () => {
    server.use(
      http.get(`${BASE}/iocs/:id/detail`, () => HttpResponse.json(mockIocDetail)),
      http.get(`${BASE}/iocs/:id/context`, () => HttpResponse.json({ contexts: [] })),
    );
    render(<IocDetail />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('scammer@evil.com')).toBeInTheDocument();
    });
    fireEvent.click(screen.getByText(/Related IOCs/));
    await waitFor(() => {
      expect(screen.getByText(/No related IOCs/i)).toBeInTheDocument();
    });
  });

  it('renders MISP mapping section with to_ids false', async () => {
    server.use(
      http.get(`${BASE}/iocs/:id/detail`, () => HttpResponse.json(mockUrlIoc)),
      http.get(`${BASE}/iocs/:id/context`, () => HttpResponse.json({ contexts: [] })),
    );
    render(<IocDetail />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/MISP/i)).toBeInTheDocument();
    });
    expect(screen.getByText('false')).toBeInTheDocument();
  });

  it('renders STIX pattern section', async () => {
    server.use(
      http.get(`${BASE}/iocs/:id/detail`, () => HttpResponse.json(mockUrlIoc)),
      http.get(`${BASE}/iocs/:id/context`, () => HttpResponse.json({ contexts: [] })),
    );
    render(<IocDetail />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/STIX Pattern/i)).toBeInTheDocument();
    });
  });

  it('renders ScoringExplain for recent no-detection IOC', async () => {
    const recentIoc = {
      ...mockIocDetail,
      first_seen: new Date().toISOString(),
    };
    server.use(
      http.get(`${BASE}/iocs/:id/detail`, () => HttpResponse.json(recentIoc)),
      http.get(`${BASE}/iocs/:id/context`, () => HttpResponse.json({ contexts: [] })),
    );
    render(<IocDetail />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/scanners may not have indexed/i)).toBeInTheDocument();
    });
  });

  it('renders ScoringExplain for old no-detection IOC', async () => {
    const oldIoc = {
      ...mockIocDetail,
      first_seen: '2025-01-01T00:00:00Z',
    };
    server.use(
      http.get(`${BASE}/iocs/:id/detail`, () => HttpResponse.json(oldIoc)),
      http.get(`${BASE}/iocs/:id/context`, () => HttpResponse.json({ contexts: [] })),
    );
    render(<IocDetail />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/No external detections after/i)).toBeInTheDocument();
    });
  });

  it('renders explain text when VT has detections', async () => {
    server.use(
      http.get(`${BASE}/iocs/:id/detail`, () => HttpResponse.json(mockUrlIoc)),
      http.get(`${BASE}/iocs/:id/context`, () => HttpResponse.json({ contexts: [] })),
    );
    render(<IocDetail />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('VT flagged malicious')).toBeInTheDocument();
    });
  });

  it('renders context tab with skipped status', async () => {
    server.use(
      http.get(`${BASE}/iocs/:id/detail`, () => HttpResponse.json(mockIocDetail)),
      http.get(`${BASE}/iocs/:id/context`, () => HttpResponse.json(mockContextSkipped)),
    );
    render(<IocDetail />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('scammer@evil.com')).toBeInTheDocument();
    });
    fireEvent.click(screen.getByText(/Context/));
    await waitFor(() => {
      expect(screen.getByText('skipped')).toBeInTheDocument();
    });
  });

  it('renders context tab with pending status', async () => {
    server.use(
      http.get(`${BASE}/iocs/:id/detail`, () => HttpResponse.json(mockIocDetail)),
      http.get(`${BASE}/iocs/:id/context`, () => HttpResponse.json(mockContextPending)),
    );
    render(<IocDetail />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('scammer@evil.com')).toBeInTheDocument();
    });
    fireEvent.click(screen.getByText(/Context/));
    await waitFor(() => {
      expect(screen.getByText('pending')).toBeInTheDocument();
    });
  });

  it('renders context tab with failed status and co-revealed types', async () => {
    server.use(
      http.get(`${BASE}/iocs/:id/detail`, () => HttpResponse.json(mockIocDetail)),
      http.get(`${BASE}/iocs/:id/context`, () => HttpResponse.json(mockContextFailed)),
    );
    render(<IocDetail />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('scammer@evil.com')).toBeInTheDocument();
    });
    fireEvent.click(screen.getByText(/Context/));
    await waitFor(() => {
      expect(screen.getByText('failed')).toBeInTheDocument();
      expect(screen.getByText('domain')).toBeInTheDocument();
      expect(screen.getByText('ipv4')).toBeInTheDocument();
    });
  });

  it('renders context tab with structural-only status', async () => {
    server.use(
      http.get(`${BASE}/iocs/:id/detail`, () => HttpResponse.json(mockIocDetail)),
      http.get(`${BASE}/iocs/:id/context`, () => HttpResponse.json(mockContextStructural)),
    );
    render(<IocDetail />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('scammer@evil.com')).toBeInTheDocument();
    });
    fireEvent.click(screen.getByText(/Context/));
    await waitFor(() => {
      expect(screen.getByText('structural')).toBeInTheDocument();
    });
  });

  it('renders no context message', async () => {
    server.use(
      http.get(`${BASE}/iocs/:id/detail`, () => HttpResponse.json(mockIocDetail)),
      http.get(`${BASE}/iocs/:id/context`, () => HttpResponse.json({ contexts: [] })),
    );
    render(<IocDetail />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('scammer@evil.com')).toBeInTheDocument();
    });
    fireEvent.click(screen.getByText(/Context/));
    await waitFor(() => {
      expect(screen.getByText(/No contextual data/i)).toBeInTheDocument();
    });
  });

  it('renders observation timeline for 3+ observations', async () => {
    server.use(
      http.get(`${BASE}/iocs/:id/detail`, () => HttpResponse.json(mockUrlIoc)),
      http.get(`${BASE}/iocs/:id/context`, () => HttpResponse.json({ contexts: [] })),
    );
    render(<IocDetail />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/Observation Timeline/i)).toBeInTheDocument();
    });
  });

  it('renders simplified observation text for 1-2 observations', async () => {
    server.use(
      http.get(`${BASE}/iocs/:id/detail`, () => HttpResponse.json(mockIocDetail)),
      http.get(`${BASE}/iocs/:id/context`, () => HttpResponse.json({ contexts: [] })),
    );
    render(<IocDetail />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/Observed 5 times/i)).toBeInTheDocument();
    });
  });

  it('renders TLP colors for GREEN', async () => {
    server.use(
      http.get(`${BASE}/iocs/:id/detail`, () => HttpResponse.json({ ...mockIocDetail, tlp: 'GREEN' })),
      http.get(`${BASE}/iocs/:id/context`, () => HttpResponse.json({ contexts: [] })),
    );
    render(<IocDetail />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getAllByText(/TLP:GREEN/).length).toBeGreaterThan(0);
    });
  });
});
