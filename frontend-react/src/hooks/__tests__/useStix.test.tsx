import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { renderHook, waitFor, act } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { server } from '@/__tests__/mocks/server';
import { http, HttpResponse } from 'msw';
import { useCampaignCandidates, useStixExport } from '../useStix';

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

describe('useCampaignCandidates', () => {
  it('fetches campaign candidates', async () => {
    server.use(
      http.get(`${BASE}/campaign/candidates`, () =>
        HttpResponse.json({
          candidates: [
            {
              campaign_id: 'camp-1',
              rule_id: 'rule-1',
              ppv: 0.85,
              hits_total: 10,
              lead_time_sec: 3600,
              lead_time_hours: 1,
              created_at: '2026-01-01T00:00:00Z',
            },
          ],
        }),
      ),
    );

    const { result } = renderHook(() => useCampaignCandidates(), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data).toHaveLength(1);
    expect(result.current.data![0].ppv).toBe(0.85);
  });

  it('handles error', async () => {
    server.use(
      http.get(`${BASE}/campaign/candidates`, () => HttpResponse.json({}, { status: 500 })),
    );

    const { result } = renderHook(() => useCampaignCandidates(), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isError).toBe(true));
  });
});

describe('useStixExport', () => {
  it('exports STIX bundle via mutation', async () => {
    server.use(
      http.post(`${BASE}/campaign/camp-1/export/stix`, () =>
        HttpResponse.json({
          message: 'Exported',
          file_path: '/tmp/stix.json',
          bundle_id: 'bundle--123',
          bundle: { type: 'bundle', objects: [] },
        }),
      ),
    );

    const { result } = renderHook(() => useStixExport(), { wrapper: createWrapper() });

    await act(async () => {
      result.current.mutate('camp-1');
    });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data!.bundle_id).toBe('bundle--123');
  });
});
