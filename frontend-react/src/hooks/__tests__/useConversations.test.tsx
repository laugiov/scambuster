import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { server } from '@/__tests__/mocks/server';
import { http, HttpResponse } from 'msw';
import {
  useConversations,
  useAllConversations,
  useConversationDetail,
  useConversationMessages,
  useConversationIocs,
  PAGE_SIZE,
} from '../useConversations';

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

describe('useConversations', () => {
  it('fetches conversations with default page', async () => {
    server.use(
      http.get(`${BASE}/communication/conversation`, ({ request }) => {
        const url = new URL(request.url);
        expect(url.searchParams.get('page')).toBe('1');
        expect(url.searchParams.get('limit')).toBe(String(PAGE_SIZE));
        return HttpResponse.json([{ conv_id: 'c1', status: 'open' }]);
      }),
    );

    const { result } = renderHook(() => useConversations(), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data).toHaveLength(1);
    expect(result.current.data![0].conv_id).toBe('c1');
  });

  it('fetches conversations with custom page', async () => {
    server.use(
      http.get(`${BASE}/communication/conversation`, ({ request }) => {
        const url = new URL(request.url);
        expect(url.searchParams.get('page')).toBe('3');
        return HttpResponse.json([]);
      }),
    );

    const { result } = renderHook(() => useConversations(3), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
  });

  it('handles API error', async () => {
    server.use(
      http.get(`${BASE}/communication/conversation`, () => HttpResponse.json({}, { status: 500 })),
    );

    const { result } = renderHook(() => useConversations(), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isError).toBe(true));
  });
});

describe('useAllConversations', () => {
  it('fetches all conversations with high limit', async () => {
    server.use(
      http.get(`${BASE}/communication/conversation`, ({ request }) => {
        const url = new URL(request.url);
        expect(url.searchParams.get('limit')).toBe('5000');
        return HttpResponse.json([{ conv_id: 'c1' }, { conv_id: 'c2' }]);
      }),
    );

    const { result } = renderHook(() => useAllConversations(), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data).toHaveLength(2);
  });
});

describe('useConversationDetail', () => {
  it('fetches detail for a conversation', async () => {
    server.use(
      http.get(`${BASE}/communication/conversation/conv-123`, () =>
        HttpResponse.json({ conv_id: 'conv-123', status: 'open', score_risk: 42 }),
      ),
    );

    const { result } = renderHook(() => useConversationDetail('conv-123'), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data!.conv_id).toBe('conv-123');
    expect(result.current.data!.score_risk).toBe(42);
  });

  it('does not fetch when conversationId is empty', () => {
    const { result } = renderHook(() => useConversationDetail(''), { wrapper: createWrapper() });
    expect(result.current.fetchStatus).toBe('idle');
  });
});

describe('useConversationMessages', () => {
  it('fetches messages for a conversation', async () => {
    server.use(
      http.get(`${BASE}/communication/conversation/conv-123/messages`, () =>
        HttpResponse.json([
          { message_id: 'm1', direction: 'in', body_text: 'Hello', ts_msg: '2026-01-01T00:00:00Z', subject: null },
        ]),
      ),
    );

    const { result } = renderHook(() => useConversationMessages('conv-123'), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data).toHaveLength(1);
  });

  it('does not fetch when conversationId is empty', () => {
    const { result } = renderHook(() => useConversationMessages(''), { wrapper: createWrapper() });
    expect(result.current.fetchStatus).toBe('idle');
  });
});

describe('useConversationIocs', () => {
  it('fetches IOCs for a conversation', async () => {
    server.use(
      http.get(`${BASE}/communication/conversation/conv-123/iocs`, () =>
        HttpResponse.json([
          { obs_id: 'o1', ioc_id: 'i1', type: 'email', value: 'a@b.com', value_norm: 'a@b.com', category: 'network', ts_observed: '2026-01-01T00:00:00Z' },
        ]),
      ),
    );

    const { result } = renderHook(() => useConversationIocs('conv-123'), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data).toHaveLength(1);
  });

  it('does not fetch when conversationId is empty', () => {
    const { result } = renderHook(() => useConversationIocs(''), { wrapper: createWrapper() });
    expect(result.current.fetchStatus).toBe('idle');
  });
});

describe('PAGE_SIZE', () => {
  it('is 50', () => {
    expect(PAGE_SIZE).toBe(50);
  });
});
