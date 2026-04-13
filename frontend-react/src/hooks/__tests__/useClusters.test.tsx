import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { server } from '@/__tests__/mocks/server';
import { http, HttpResponse } from 'msw';
import { useClusters, useClusterStats, useClusterDetail, useClusterForIoc } from '../useClusters';

const BASE = '/api/v1';

beforeAll(() => server.listen({ onUnhandledRequest: 'bypass' }));
afterEach(() => server.resetHandlers());
afterAll(() => server.close());

function createWrapper() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
  };
}

const mockCluster = {
  cluster_id: 'cl-1',
  stix_id: 'threat-actor--abc',
  name: 'Test Cluster',
  status: 'active',
  conversation_count: 5,
  anchor_ioc_count: 3,
  anchor_ioc_types: ['iban', 'email'],
  sophistication: 'intermediate',
  primary_scam_types: ['PHISHING'],
  first_seen: '2026-01-01T00:00:00Z',
  last_seen: '2026-03-01T00:00:00Z',
};

describe('useClusters', () => {
  it('fetches cluster list', async () => {
    server.use(
      http.get(`${BASE}/clusters`, () => HttpResponse.json([mockCluster])),
    );

    const { result } = renderHook(() => useClusters(), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data).toHaveLength(1);
    expect(result.current.data![0].cluster_id).toBe('cl-1');
  });
});

describe('useClusterStats', () => {
  it('fetches cluster stats', async () => {
    server.use(
      http.get(`${BASE}/clusters/stats`, () =>
        HttpResponse.json({
          total_conversations: 100,
          clustered_conversations: 80,
          singleton_conversations: 20,
          total_clusters: 10,
          suspect_clusters: 2,
          taxii_noise_reduction_pct: 75,
          avg_cluster_size: 8,
          largest_cluster_size: 15,
          anchor_ioc_coverage: { iban: 5, email: 3 },
          last_clustered_at: '2026-03-01T00:00:00Z',
        }),
      ),
    );

    const { result } = renderHook(() => useClusterStats(), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data!.total_clusters).toBe(10);
    expect(result.current.data!.taxii_noise_reduction_pct).toBe(75);
  });
});

describe('useClusterDetail', () => {
  it('fetches cluster detail', async () => {
    server.use(
      http.get(`${BASE}/clusters/cl-1`, () =>
        HttpResponse.json({
          ...mockCluster,
          algorithm_version: 'v1.0',
          anchor_iocs: [],
          conversations: [],
          sample_excerpts: [],
          behavioral_profile: null,
        }),
      ),
    );

    const { result } = renderHook(() => useClusterDetail('cl-1'), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data!.algorithm_version).toBe('v1.0');
  });

  it('does not fetch when clusterId is empty', () => {
    const { result } = renderHook(() => useClusterDetail(''), { wrapper: createWrapper() });
    expect(result.current.fetchStatus).toBe('idle');
  });
});

describe('useClusterForIoc', () => {
  it('fetches cluster for an IOC', async () => {
    server.use(
      http.get(`${BASE}/iocs/ind-123/cluster`, () =>
        HttpResponse.json({
          cluster_id: 'cl-1',
          stix_id: 'threat-actor--abc',
          name: 'Test Cluster',
          status: 'active',
          conversation_count: 5,
          sophistication: 'intermediate',
        }),
      ),
    );

    const { result } = renderHook(() => useClusterForIoc('ind-123'), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data!.cluster_id).toBe('cl-1');
  });

  it('returns null when API returns error', async () => {
    server.use(
      http.get(`${BASE}/iocs/ind-404/cluster`, () => HttpResponse.json({}, { status: 404 })),
    );

    const { result } = renderHook(() => useClusterForIoc('ind-404'), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data).toBeNull();
  });

  it('does not fetch when indicatorId is empty', () => {
    const { result } = renderHook(() => useClusterForIoc(''), { wrapper: createWrapper() });
    expect(result.current.fetchStatus).toBe('idle');
  });
});
