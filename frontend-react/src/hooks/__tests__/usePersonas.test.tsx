import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { renderHook, waitFor, act } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { server } from '@/__tests__/mocks/server';
import { http, HttpResponse } from 'msw';
import {
  usePersonaPerformance,
  useAllPersonaPerformances,
  usePersonaDetail,
  useCreatePersona,
  useUpdatePersona,
  useTogglePersonaActive,
} from '../usePersonas';

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

const mockPerformance = {
  persona_code: 'elderly_person',
  persona_label: 'Elderly Person',
  total_sessions: 20,
  global_avg_reward: 0.75,
  performance_by_scam_type: [
    { scam_type_code: 'PHISHING', sessions_count: 10, avg_reward: 0.8 },
  ],
};

describe('usePersonaPerformance', () => {
  it('fetches persona performance', async () => {
    server.use(
      http.get(`${BASE}/scambaiting/persona/elderly_person/performance`, () =>
        HttpResponse.json({ success: true, data: mockPerformance }),
      ),
    );

    const { result } = renderHook(() => usePersonaPerformance('elderly_person'), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data!.persona_code).toBe('elderly_person');
    expect(result.current.data!.total_sessions).toBe(20);
  });

  it('does not fetch when code is empty', () => {
    const { result } = renderHook(() => usePersonaPerformance(''), { wrapper: createWrapper() });
    expect(result.current.fetchStatus).toBe('idle');
  });
});

describe('useAllPersonaPerformances', () => {
  it('fetches performances for multiple personas', async () => {
    server.use(
      http.get(`${BASE}/scambaiting/persona/:code/performance`, ({ params }) => {
        const code = params.code as string;
        return HttpResponse.json({
          success: true,
          data: { ...mockPerformance, persona_code: code },
        });
      }),
    );

    const { result } = renderHook(
      () => useAllPersonaPerformances(['elderly_person', 'bank_customer']),
      { wrapper: createWrapper() },
    );
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data).toHaveLength(2);
  });

  it('filters out failed requests', async () => {
    server.use(
      http.get(`${BASE}/scambaiting/persona/elderly_person/performance`, () =>
        HttpResponse.json({ success: true, data: mockPerformance }),
      ),
      http.get(`${BASE}/scambaiting/persona/bad_code/performance`, () =>
        HttpResponse.json({}, { status: 500 }),
      ),
    );

    const { result } = renderHook(
      () => useAllPersonaPerformances(['elderly_person', 'bad_code']),
      { wrapper: createWrapper() },
    );
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data).toHaveLength(1);
  });

  it('does not fetch when array is empty', () => {
    const { result } = renderHook(
      () => useAllPersonaPerformances([]),
      { wrapper: createWrapper() },
    );
    expect(result.current.fetchStatus).toBe('idle');
  });
});

describe('usePersonaDetail', () => {
  it('fetches persona detail', async () => {
    server.use(
      http.get(`${BASE}/personas/elderly_person`, () =>
        HttpResponse.json({
          success: true,
          data: {
            persona_code: 'elderly_person',
            persona_label: 'Elderly Person',
            persona_tone: 'Familiar',
            system_prompt: 'You are an elderly person...',
            is_active: true,
            created_by: 'admin',
            created_at: '2026-01-01T00:00:00Z',
          },
        }),
      ),
    );

    const { result } = renderHook(() => usePersonaDetail('elderly_person'), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data!.persona_code).toBe('elderly_person');
  });

  it('does not fetch when code is null', () => {
    const { result } = renderHook(() => usePersonaDetail(null), { wrapper: createWrapper() });
    expect(result.current.fetchStatus).toBe('idle');
  });
});

describe('useUpdatePersona', () => {
  it('updates persona via mutation', async () => {
    server.use(
      http.put(`${BASE}/personas/elderly_person`, () =>
        HttpResponse.json({
          success: true,
          data: {
            persona_code: 'elderly_person',
            persona_label: 'Updated Label',
            persona_tone: 'Formal',
            system_prompt: 'Updated prompt',
            is_active: true,
            created_by: 'admin',
            created_at: '2026-01-01T00:00:00Z',
          },
        }),
      ),
    );

    const { result } = renderHook(() => useUpdatePersona(), { wrapper: createWrapper() });

    await act(async () => {
      result.current.mutate({ code: 'elderly_person', updates: { persona_label: 'Updated Label' } });
    });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data!.persona_label).toBe('Updated Label');
  });
});

describe('useTogglePersonaActive', () => {
  it('toggles persona active state', async () => {
    server.use(
      http.patch(`${BASE}/personas/elderly_person/active`, () =>
        HttpResponse.json({
          success: true,
          data: { persona_code: 'elderly_person', is_active: false },
        }),
      ),
    );

    const { result } = renderHook(() => useTogglePersonaActive(), { wrapper: createWrapper() });

    await act(async () => {
      result.current.mutate({ code: 'elderly_person', active: false });
    });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data!.is_active).toBe(false);
  });
});

describe('useCreatePersona', () => {
  it('creates a persona via POST', async () => {
    let posted: unknown = null;
    server.use(
      http.post(`${BASE}/personas`, async ({ request }) => {
        posted = await request.json();
        return HttpResponse.json(
          {
            success: true,
            data: {
              persona_code: 'logistics_dispatcher',
              persona_label: 'Logistics dispatcher',
              persona_tone: 'formal',
              system_prompt: 'x',
              is_active: true,
              created_by: 'operator',
              created_at: '2026-01-01T00:00:00Z',
            },
          },
          { status: 201 },
        );
      }),
    );

    const { result } = renderHook(() => useCreatePersona(), { wrapper: createWrapper() });

    await act(async () => {
      result.current.mutate({
        persona_code: 'logistics_dispatcher',
        persona_label: 'Logistics dispatcher',
        persona_tone: 'formal',
        system_prompt: 'x'.repeat(120),
        scam_type_codes: ['PHISHING'],
      });
    });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect((posted as { persona_code: string }).persona_code).toBe('logistics_dispatcher');
    expect(result.current.data?.created_by).toBe('operator');
  });
});
