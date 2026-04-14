import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { server } from '@/__tests__/mocks/server';
import { http, HttpResponse } from 'msw';
import { useAllIocs, useIocGraph, useIocDetail, useIocContext } from '../useIocs';

const BASE = '/api/v1';

beforeAll(() => server.listen({ onUnhandledRequest: 'warn' }));
afterEach(() => server.resetHandlers());
afterAll(() => server.close());

function createWrapper() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
  };
}

describe('useAllIocs', () => {
  it('fetches all IOCs', async () => {
    server.use(
      http.get(`${BASE}/iocs`, () =>
        HttpResponse.json([
          { obs_id: 'o1', ioc_id: 'i1', type: 'email', value: 'a@b.com', value_norm: 'a@b.com', category: 'network', ts_observed: '2026-01-01T00:00:00Z' },
        ]),
      ),
    );

    const { result } = renderHook(() => useAllIocs(), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data).toHaveLength(1);
  });

  it('handles error', async () => {
    server.use(http.get(`${BASE}/iocs`, () => HttpResponse.json({}, { status: 500 })));
    const { result } = renderHook(() => useAllIocs(), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isError).toBe(true));
  });
});

describe('useIocGraph', () => {
  it('fetches co-occurrence graph', async () => {
    server.use(
      http.get(`${BASE}/iocs/co-occurrence`, () =>
        HttpResponse.json({
          nodes: [{ id: 'n1', type: 'email', value: 'a@b.com', score: 5, center: true }],
          edges: [],
        }),
      ),
    );

    const { result } = renderHook(() => useIocGraph('ind-123'), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data!.nodes).toHaveLength(1);
  });

  it('does not fetch when indicatorId is empty', () => {
    const { result } = renderHook(() => useIocGraph(''), { wrapper: createWrapper() });
    expect(result.current.fetchStatus).toBe('idle');
  });
});

describe('useIocDetail', () => {
  it('fetches IOC detail', async () => {
    server.use(
      http.get(`${BASE}/iocs/ind-123/detail`, () =>
        HttpResponse.json({
          indicator_id: 'ind-123',
          type: 'email',
          value: 'a@b.com',
          value_norm: 'a@b.com',
          first_seen: '2026-01-01T00:00:00Z',
          last_seen: '2026-01-02T00:00:00Z',
          occurrences: 3,
          tlp: 'AMBER',
          enrichment: {},
          score: { vt: 0, urlscan: 0, agg: 0, explain: '' },
          confidence: 0.8,
          decay_factor: 1,
          effective_score: 0.8,
          category: 'network',
          misp: null,
          stix: null,
          observations: [],
          related_iocs: [],
        }),
      ),
    );

    const { result } = renderHook(() => useIocDetail('ind-123'), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data!.indicator_id).toBe('ind-123');
  });

  it('does not fetch when indicatorId is empty', () => {
    const { result } = renderHook(() => useIocDetail(''), { wrapper: createWrapper() });
    expect(result.current.fetchStatus).toBe('idle');
  });
});

describe('useIocContext', () => {
  it('fetches IOC context', async () => {
    server.use(
      http.get(`${BASE}/iocs/ind-123/context`, () =>
        HttpResponse.json({
          indicator_id: 'ind-123',
          contexts: [
            {
              obs_id: 'o1',
              enrichment_status: 'enriched',
              structural: {
                scam_type: 'PHISHING',
                attck_technique: 'T1566.002',
                persona_code: 'elderly_person',
                persona_label: 'Elderly Person',
                extraction_method: 'regex',
                revelation_turn: 3,
                total_turns: 10,
                revelation_turn_ratio: 0.3,
                engagement_hours: 2.5,
                reward_value: 0.8,
                co_revealed_types: ['email', 'url'],
                co_revealed_count: 2,
                campaign_id: null,
              },
              semantic: {
                role: 'PAYMENT_DESTINATION',
                stimulus_type: 'URGENCY_PRESSURE',
                urgency_score: 0.8,
                language_switch: false,
                hesitation_detected: true,
                context_excerpt: 'Send money now',
                enrichment_confidence: 0.9,
                enrichment_model: 'gpt-4o-mini',
              },
              computed_at: '2026-01-01T00:00:00Z',
            },
          ],
        }),
      ),
    );

    const { result } = renderHook(() => useIocContext('ind-123'), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data!.contexts).toHaveLength(1);
    expect(result.current.data!.contexts[0].enrichment_status).toBe('enriched');
  });

  it('does not fetch when indicatorId is empty', () => {
    const { result } = renderHook(() => useIocContext(''), { wrapper: createWrapper() });
    expect(result.current.fetchStatus).toBe('idle');
  });
});
