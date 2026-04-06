import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { http, HttpResponse } from 'msw';
import type { ReactNode } from 'react';
import { server } from '@/__tests__/mocks/server';
import type { Ioc } from '@/types/api';
import { IocExplorer } from './IocExplorer';
import '../i18n';

const BASE = '/api/v1';

const mockIocs: Ioc[] = [
  {
    obs_id: 'obs-1', ioc_id: 'ind-1', type: 'domain', value: 'evil.com',
    value_norm: 'evil[.]com', score: { vt: 70, urlscan: 0, agg: 70, explain: '' },
    category: 'Credential_phish', ts_observed: new Date().toISOString(),
    confidence: 0.95, decay_factor: 0.9, effective_score: 0.85,
  },
  {
    obs_id: 'obs-2', ioc_id: 'ind-2', type: 'message_id', value: '<abc@mail.com>',
    value_norm: '<abc@mail.com>', score: { vt: 0, urlscan: 0, agg: 0, explain: '' },
    category: 'Unknown', ts_observed: new Date().toISOString(),
    confidence: 0.99, decay_factor: 1, effective_score: 0.99,
  },
  {
    obs_id: 'obs-3', ioc_id: 'ind-3', type: 'ipv4', value: '192.0.2.1',
    value_norm: '192.0.2.1', score: { vt: 45, urlscan: 0, agg: 45, explain: '' },
    category: 'Unknown', ts_observed: '2025-01-01T00:00:00Z',
    confidence: 0.5, decay_factor: 0.3, effective_score: 0.15,
  },
];

const mockMetaConfig = {
  personas: [], scam_types: [],
  ioc_types: ['domain', 'ipv4', 'message_id', 'email'],
  bandit: { strategy: 'epsilon-greedy', epsilon: 0.2, cold_start_threshold: 3, convergence_threshold: 0.6, min_sessions_for_convergence: 10, converged_epsilon: 0.05, reward_weights: {} },
  llm_provider: 'openai', llm_model: 'gpt-4o-mini',
};

const iocHandler = http.get(`${BASE}/iocs`, () => HttpResponse.json(mockIocs));
const metaHandler = http.get(`${BASE}/meta/config`, () => HttpResponse.json(mockMetaConfig));

beforeAll(() => server.listen({ onUnhandledRequest: 'bypass' }));
afterEach(() => server.resetHandlers());
afterAll(() => server.close());

function createWrapper() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return function Wrapper({ children }: { children: ReactNode }) {
    return (
      <QueryClientProvider client={queryClient}>
        <MemoryRouter>
          {children}
        </MemoryRouter>
      </QueryClientProvider>
    );
  };
}

describe('IocExplorer Advanced Filters', () => {
  it('hides header IOCs by default', async () => {
    server.use(iocHandler, metaHandler);
    render(<IocExplorer />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getByText('evil.com')).toBeDefined();
    });

    // message_id IOC should be hidden by default
    expect(screen.queryByText('<abc@mail.com>')).toBeNull();

    // domain and ipv4 should be visible
    expect(screen.getByText('evil.com')).toBeDefined();
    expect(screen.getByText('192.0.2.1')).toBeDefined();
  });

  it('shows header IOCs when toggle is unchecked', async () => {
    server.use(iocHandler, metaHandler);
    render(<IocExplorer />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getByText('evil.com')).toBeDefined();
    });

    const toggles = screen.getAllByRole('checkbox');
    const hideHeadersToggle = toggles.find(el => el.getAttribute('checked') !== null) ?? toggles[toggles.length - 1];
    fireEvent.click(hideHeadersToggle);

    await waitFor(() => {
      expect(screen.getByText('<abc@mail.com>')).toBeDefined();
    });
  });

  it('filters by severity High', async () => {
    server.use(iocHandler, metaHandler);
    render(<IocExplorer />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getByText('evil.com')).toBeDefined();
    });

    // Click "High" severity filter
    const highBtn = screen.getAllByText('High').find(
      (el) => el.tagName === 'BUTTON'
    );
    if (highBtn) fireEvent.click(highBtn);

    await waitFor(() => {
      // evil.com has agg=70 (High), should be visible
      expect(screen.getByText('evil.com')).toBeDefined();
      // ipv4 has agg=45 (Medium), should be hidden
      expect(screen.queryByText('192.0.2.1')).toBeNull();
    });
  });
});
