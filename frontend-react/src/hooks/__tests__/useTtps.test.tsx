import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { server } from '@/__tests__/mocks/server';
import { http, HttpResponse } from 'msw';
import {
  useClusterTtpMatrix,
  useClusterTtps,
  useConversationTtps,
  useIocsForTtp,
  useTtpPhaseTransitions,
  useTtpPhaseTrend,
  useTtpSequences,
  useTtpsForIoc,
  useTtpTaxonomy,
} from '../useTtps';

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

const clusterProfile = {
  cluster_id: 'cl-1',
  ttps: [
    {
      ttp_code: 'SB-T017',
      ttp_label: 'Payment demand',
      phase: 'payment-request',
      observation_count: 5,
      conversation_count: 3,
      avg_confidence: 0.84,
      first_seen: '2026-01-01T00:00:00Z',
      last_seen: '2026-03-01T00:00:00Z',
    },
  ],
  top_sequences: [{ sequence: ['SB-T001', 'SB-T017'], count: 2 }],
};

describe('useClusterTtps', () => {
  it('fetches a cluster TTP profile', async () => {
    server.use(http.get(`${BASE}/clusters/cl-1/ttps`, () => HttpResponse.json(clusterProfile)));

    const { result } = renderHook(() => useClusterTtps('cl-1'), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data!.ttps[0].ttp_code).toBe('SB-T017');
    expect(result.current.data!.top_sequences).toHaveLength(1);
  });

  it('resolves to null on a 404 (unknown cluster)', async () => {
    server.use(
      http.get(`${BASE}/clusters/cl-404/ttps`, () =>
        HttpResponse.json({ error: 'Cluster not found' }, { status: 404 })),
    );

    const { result } = renderHook(() => useClusterTtps('cl-404'), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data).toBeNull();
  });

  it('does not fetch when clusterId is empty', () => {
    const { result } = renderHook(() => useClusterTtps(''), { wrapper: createWrapper() });
    expect(result.current.fetchStatus).toBe('idle');
  });
});

describe('useConversationTtps', () => {
  it('fetches conversation observations and timeline', async () => {
    server.use(
      http.get(`${BASE}/conversations/co-1/ttps`, () =>
        HttpResponse.json({
          conv_id: 'co-1',
          observations: [
            {
              msg_id: 'm-1',
              ts_msg: '2026-03-01T00:00:00Z',
              ttp_code: 'SB-T001',
              ttp_label: 'Cold outreach',
              phase: 'hook',
              confidence: 0.9,
              status: 'confirmed',
              evidence_start: 4,
              evidence_end: 9,
            },
          ],
          timeline: [
            {
              msg_id: 'm-1',
              direction: 'in',
              ts_msg: '2026-03-01T00:00:00Z',
              subject: 'Hi',
              ttps: [
                { ttp_code: 'SB-T001', phase: 'hook', confidence: 0.9, status: 'confirmed', evidence_start: 4, evidence_end: 9 },
              ],
              iocs_revealed: [{ type: 'email', value_norm: 'a@b.test' }],
              stimulus_type: null,
            },
          ],
        })),
    );

    const { result } = renderHook(() => useConversationTtps('co-1'), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data!.timeline[0].ttps[0].ttp_code).toBe('SB-T001');
    expect(result.current.data!.observations).toHaveLength(1);
  });

  it('resolves to null on a 404 (unknown conversation)', async () => {
    server.use(
      http.get(`${BASE}/conversations/co-404/ttps`, () =>
        HttpResponse.json({ error: 'Conversation not found' }, { status: 404 })),
    );

    const { result } = renderHook(() => useConversationTtps('co-404'), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data).toBeNull();
  });

  it('does not fetch when conversationId is empty', () => {
    const { result } = renderHook(() => useConversationTtps(''), { wrapper: createWrapper() });
    expect(result.current.fetchStatus).toBe('idle');
  });
});

describe('useTtpTaxonomy', () => {
  it('fetches the full taxonomy overview with a version stamp', async () => {
    server.use(
      http.get(`${BASE}/ttps`, () =>
        HttpResponse.json({
          taxonomy_version: '1.0',
          ttps: [
            {
              ttp_code: 'SB-T001',
              ttp_label: 'Cold outreach',
              phase: 'hook',
              definition: 'Unsolicited first contact.',
              observation_count: 12,
              conversation_count: 8,
              first_seen: '2026-01-01T00:00:00Z',
              last_seen: '2026-03-01T00:00:00Z',
              review_count: 2,
            },
            {
              ttp_code: 'SB-T027',
              ttp_label: 'Ghosting',
              phase: 'exit',
              definition: 'Silent disappearance.',
              observation_count: 0,
              conversation_count: 0,
              first_seen: null,
              last_seen: null,
              review_count: 0,
            },
          ],
        })),
    );

    const { result } = renderHook(() => useTtpTaxonomy(), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data!.taxonomy_version).toBe('1.0');
    expect(result.current.data!.ttps).toHaveLength(2);
    // Zero-observation entries are kept so coverage is honest.
    expect(result.current.data!.ttps[1].observation_count).toBe(0);
    expect(result.current.data!.ttps[0].review_count).toBe(2);
  });

  it('surfaces an error on a 500', async () => {
    server.use(
      http.get(`${BASE}/ttps`, () => HttpResponse.json({ error: 'boom' }, { status: 500 })),
    );

    const { result } = renderHook(() => useTtpTaxonomy(), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isError).toBe(true));
  });
});

describe('useClusterTtpMatrix', () => {
  it('fetches the shared-playbook matrix', async () => {
    server.use(
      http.get(`${BASE}/ttps/cluster-matrix`, () =>
        HttpResponse.json({
          clusters: [{ cluster_id: 'cl-1', label: 'Cluster One', observation_total: 7, conversation_total: 5 }],
          ttps: [{ ttp_code: 'SB-T017', ttp_label: 'Payment demand', phase: 'payment-request' }],
          cells: [{ cluster_id: 'cl-1', ttp_code: 'SB-T017', count: 7, conversation_count: 5 }],
          truncated: true,
          total_clusters: 63,
        })),
    );

    const { result } = renderHook(() => useClusterTtpMatrix(), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data!.cells[0].count).toBe(7);
    expect(result.current.data!.truncated).toBe(true);
    expect(result.current.data!.total_clusters).toBe(63);
  });

  it('surfaces an error on a 500 (consumer degrades to an empty note)', async () => {
    server.use(
      http.get(`${BASE}/ttps/cluster-matrix`, () => HttpResponse.json({}, { status: 500 })),
    );

    const { result } = renderHook(() => useClusterTtpMatrix(), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isError).toBe(true));
  });
});

describe('useTtpPhaseTrend', () => {
  it('fetches the zero-filled weekly phase trend', async () => {
    server.use(
      http.get(`${BASE}/ttps/phase-trend`, () =>
        HttpResponse.json({
          weeks: [
            {
              week: '2026-07-20',
              counts: { hook: 3, 'trust-building': 0, 'payment-request': 1, escalation: 0, 'channel-switch': 0, exit: 0 },
            },
          ],
        })),
    );

    const { result } = renderHook(() => useTtpPhaseTrend(), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data!.weeks).toHaveLength(1);
    expect(result.current.data!.weeks[0].week).toBe('2026-07-20');
    expect(result.current.data!.weeks[0].counts.hook).toBe(3);
    expect(result.current.data!.weeks[0].counts.exit).toBe(0);
  });

  it('resolves to null on a 404 (endpoint absent)', async () => {
    server.use(
      http.get(`${BASE}/ttps/phase-trend`, () =>
        HttpResponse.json({ error: 'Not found' }, { status: 404 })),
    );

    const { result } = renderHook(() => useTtpPhaseTrend(), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data).toBeNull();
  });

  it('surfaces an error on a 500 (consumer degrades to a failure note)', async () => {
    server.use(
      http.get(`${BASE}/ttps/phase-trend`, () => HttpResponse.json({}, { status: 500 })),
    );

    const { result } = renderHook(() => useTtpPhaseTrend(), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isError).toBe(true));
  });
});

describe('useTtpSequences', () => {
  it('fetches the top sequences for the requested grouping', async () => {
    server.use(
      http.get(`${BASE}/ttps/sequences`, ({ request }) => {
        const group = new URL(request.url).searchParams.get('group');
        if (group !== 'scam_type') {
          return HttpResponse.json({ error: 'unexpected group' }, { status: 500 });
        }
        return HttpResponse.json({
          groups: [
            {
              key: 'ADVANCE_FEE',
              label: 'Advance fee',
              sequences: [{ sequence: ['SB-T001', 'SB-T017'], count: 4, conversation_count: 3 }],
            },
          ],
          min_support: 2,
          truncated: false,
        });
      }),
    );

    const { result } = renderHook(() => useTtpSequences('scam_type'), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data!.groups[0].key).toBe('ADVANCE_FEE');
    expect(result.current.data!.groups[0].sequences[0].count).toBe(4);
    expect(result.current.data!.groups[0].sequences[0].conversation_count).toBe(3);
    expect(result.current.data!.min_support).toBe(2);
  });

  it('resolves to null on a 404 (endpoint absent)', async () => {
    server.use(
      http.get(`${BASE}/ttps/sequences`, () =>
        HttpResponse.json({ error: 'Not found' }, { status: 404 })),
    );

    const { result } = renderHook(() => useTtpSequences('cluster'), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data).toBeNull();
  });

  it('surfaces an error on a 500 (consumer degrades to an empty note)', async () => {
    server.use(
      http.get(`${BASE}/ttps/sequences`, () => HttpResponse.json({}, { status: 500 })),
    );

    const { result } = renderHook(() => useTtpSequences('cluster'), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isError).toBe(true));
  });
});

describe('useTtpPhaseTransitions', () => {
  it('fetches the global phase-transition aggregate', async () => {
    server.use(
      http.get(`${BASE}/ttps/phase-transitions`, () =>
        HttpResponse.json({
          transitions: [{ from_phase: 'hook', to_phase: 'trust-building', count: 6 }],
          total_pairs: 6,
        })),
    );

    const { result } = renderHook(() => useTtpPhaseTransitions(), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data!.transitions[0].count).toBe(6);
    expect(result.current.data!.total_pairs).toBe(6);
  });

  it('resolves to null on a 404 (endpoint absent)', async () => {
    server.use(
      http.get(`${BASE}/ttps/phase-transitions`, () =>
        HttpResponse.json({ error: 'Not found' }, { status: 404 })),
    );

    const { result } = renderHook(() => useTtpPhaseTransitions(), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data).toBeNull();
  });

  it('surfaces an error on a 500 (consumer degrades to an empty note)', async () => {
    server.use(
      http.get(`${BASE}/ttps/phase-transitions`, () => HttpResponse.json({}, { status: 500 })),
    );

    const { result } = renderHook(() => useTtpPhaseTransitions(), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isError).toBe(true));
  });
});

describe('useIocsForTtp', () => {
  it('fetches IOCs co-observed with a TTP', async () => {
    server.use(
      http.get(`${BASE}/ttps/SB-T017/iocs`, () =>
        HttpResponse.json({
          ttp_code: 'SB-T017',
          iocs: [
            { indicator_id: 'ind-1', type: 'iban', value_norm: 'de00...', co_occurrence_count: 4, conversation_count: 2 },
          ],
        })),
    );

    const { result } = renderHook(() => useIocsForTtp('SB-T017'), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data!.iocs[0].indicator_id).toBe('ind-1');
  });

  it('resolves to null on a 404 (unknown TTP)', async () => {
    server.use(
      http.get(`${BASE}/ttps/SB-T999/iocs`, () =>
        HttpResponse.json({ error: 'TTP not found' }, { status: 404 })),
    );

    const { result } = renderHook(() => useIocsForTtp('SB-T999'), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data).toBeNull();
  });

  it('does not fetch when the code is empty', () => {
    const { result } = renderHook(() => useIocsForTtp(''), { wrapper: createWrapper() });
    expect(result.current.fetchStatus).toBe('idle');
  });
});

describe('useTtpsForIoc', () => {
  it('fetches TTPs co-observed with an IOC', async () => {
    server.use(
      http.get(`${BASE}/iocs/ind-1/ttps`, () =>
        HttpResponse.json({
          ioc: 'ind-1',
          ttps: [
            { ttp_code: 'SB-T017', ttp_label: 'Payment demand', phase: 'payment-request', co_occurrence_count: 4, conversation_count: 2 },
          ],
        })),
    );

    const { result } = renderHook(() => useTtpsForIoc('ind-1'), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data!.ttps[0].ttp_code).toBe('SB-T017');
  });

  it('resolves to null on a 404 (unknown indicator)', async () => {
    server.use(
      http.get(`${BASE}/iocs/ind-404/ttps`, () =>
        HttpResponse.json({ error: 'Indicator not found' }, { status: 404 })),
    );

    const { result } = renderHook(() => useTtpsForIoc('ind-404'), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data).toBeNull();
  });

  it('does not fetch when the indicatorId is empty', () => {
    const { result } = renderHook(() => useTtpsForIoc(''), { wrapper: createWrapper() });
    expect(result.current.fetchStatus).toBe('idle');
  });
});
