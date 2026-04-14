import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { renderHook, waitFor, act } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { server } from '@/__tests__/mocks/server';
import { http, HttpResponse } from 'msw';
import {
  useCampaignDetail,
  useCampaignMessages,
  useCampaignProfile,
  usePromoteRule,
  useHunt,
} from '../useCampaigns';

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

describe('useCampaignDetail', () => {
  it('fetches campaign detail', async () => {
    server.use(
      http.get(`${BASE}/campaign/camp-1/detail`, () =>
        HttpResponse.json({
          campaign_id: 'camp-1',
          status: 'active',
          severity: 7,
          tlp: 'AMBER',
          first_seen: '2026-01-01T00:00:00Z',
          profile_yaml: null,
          notes: null,
          created_at: '2026-01-01T00:00:00Z',
          rule: null,
        }),
      ),
    );

    const { result } = renderHook(() => useCampaignDetail('camp-1'), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data!.campaign_id).toBe('camp-1');
    expect(result.current.data!.severity).toBe(7);
  });

  it('does not fetch when campaignId is empty', () => {
    const { result } = renderHook(() => useCampaignDetail(''), { wrapper: createWrapper() });
    expect(result.current.fetchStatus).toBe('idle');
  });
});

describe('useCampaignMessages', () => {
  it('fetches campaign messages', async () => {
    server.use(
      http.get(`${BASE}/campaign/camp-1/messages`, () =>
        HttpResponse.json({
          messages: [
            { msg_id: 'm1', subject: 'Test', from: 'a@b.com', received_at: '2026-01-01T00:00:00Z', body_preview: 'Hello' },
          ],
        }),
      ),
    );

    const { result } = renderHook(() => useCampaignMessages('camp-1'), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data).toHaveLength(1);
  });

  it('does not fetch when campaignId is empty', () => {
    const { result } = renderHook(() => useCampaignMessages(''), { wrapper: createWrapper() });
    expect(result.current.fetchStatus).toBe('idle');
  });
});

describe('useCampaignProfile', () => {
  it('generates campaign profile via mutation', async () => {
    server.use(
      http.post(`${BASE}/campaign/camp-1/profile`, () =>
        HttpResponse.json({ profile_yaml: 'name: Test', cache_hit: false, attempts: 1 }),
      ),
    );

    const { result } = renderHook(() => useCampaignProfile('camp-1'), { wrapper: createWrapper() });

    await act(async () => {
      result.current.mutate();
    });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data!.profile_yaml).toBe('name: Test');
  });
});

describe('usePromoteRule', () => {
  it('promotes a rule via mutation', async () => {
    server.use(
      http.post(`${BASE}/campaign/rule/rule-1/promote`, () =>
        HttpResponse.json({ message: 'Promoted', campaign_id: 'camp-1', rule_id: 'rule-1' }),
      ),
    );

    const { result } = renderHook(() => usePromoteRule(), { wrapper: createWrapper() });

    await act(async () => {
      result.current.mutate('rule-1');
    });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data!.rule_id).toBe('rule-1');
  });
});

describe('useHunt', () => {
  it('triggers hunt via mutation', async () => {
    server.use(
      http.post(`${BASE}/campaign/hunt`, () =>
        HttpResponse.json({ total_rules: 3, total_hits: 10, results: [] }),
      ),
    );

    const { result } = renderHook(() => useHunt(), { wrapper: createWrapper() });

    await act(async () => {
      result.current.mutate();
    });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data!.total_rules).toBe(3);
  });
});
