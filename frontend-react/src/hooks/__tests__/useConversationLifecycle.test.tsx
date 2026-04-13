import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { server } from '@/__tests__/mocks/server';
import { http, HttpResponse } from 'msw';
import { useConversationLifecycle } from '../useConversationLifecycle';

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

describe('useConversationLifecycle', () => {
  it('fetches conversation lifecycle stats', async () => {
    server.use(
      http.get(`${BASE}/monitoring/conversation-lifecycle`, () =>
        HttpResponse.json({
          active: 10,
          about_to_timeout: 2,
          completed_today: 3,
          reopened_today: 1,
          by_scam_type: {
            PHISHING: { active: 5, about_to_timeout: 1, policy_timeout_hours: 72 },
          },
          about_to_timeout_list: [
            {
              conv_id: 'conv-1',
              scam_type: 'PHISHING',
              persona: 'elderly_person',
              last_activity: '2026-03-22T10:00:00Z',
              timeout_hours: 72,
              hours_remaining: 4,
            },
          ],
        }),
      ),
    );

    const { result } = renderHook(() => useConversationLifecycle(), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));

    expect(result.current.data!.active).toBe(10);
    expect(result.current.data!.about_to_timeout).toBe(2);
    expect(result.current.data!.about_to_timeout_list).toHaveLength(1);
    expect(result.current.data!.by_scam_type.PHISHING.active).toBe(5);
  });

  it('handles error', async () => {
    server.use(
      http.get(`${BASE}/monitoring/conversation-lifecycle`, () =>
        HttpResponse.json({}, { status: 500 }),
      ),
    );

    const { result } = renderHook(() => useConversationLifecycle(), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isError).toBe(true));
  });
});
