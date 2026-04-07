import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { server } from '@/__tests__/mocks/server';
import { mockMetaConfig } from '@/__tests__/mocks/handlers';
import { useMetaConfig, personaDisplayName, scamTypeDisplayName } from './useMetaConfig';

beforeAll(() => server.listen({ onUnhandledRequest: 'bypass' }));
afterEach(() => server.resetHandlers());
afterAll(() => server.close());

function createWrapper() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>;
  };
}

describe('useMetaConfig', () => {
  it('fetches and returns config', async () => {
    const { result } = renderHook(() => useMetaConfig(), { wrapper: createWrapper() });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));

    const config = result.current.data;
    expect(config).toBeDefined();
    expect(config?.personas).toHaveLength(2);
    expect(config?.scam_types).toHaveLength(2);
    expect(config?.ioc_types).toContain('email');
    expect(config?.bandit.strategy).toBe('epsilon-greedy');
    expect(config?.bandit.epsilon).toBe(0.2);
  });
});

describe('personaDisplayName', () => {
  it('returns label for known persona code', () => {
    expect(personaDisplayName(mockMetaConfig, 'elderly_person')).toBe('Personne agee');
  });

  it('returns code when persona not found', () => {
    expect(personaDisplayName(mockMetaConfig, 'unknown_code')).toBe('unknown_code');
  });

  it('returns code when config is undefined', () => {
    expect(personaDisplayName(undefined, 'elderly_person')).toBe('elderly_person');
  });
});

describe('scamTypeDisplayName', () => {
  it('returns label for known scam type', () => {
    expect(scamTypeDisplayName(mockMetaConfig, 'PHISHING')).toBe('Phishing');
  });

  it('returns code when not found', () => {
    expect(scamTypeDisplayName(mockMetaConfig, 'UNKNOWN')).toBe('UNKNOWN');
  });
});
