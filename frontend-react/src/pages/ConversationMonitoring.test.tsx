import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { http, HttpResponse } from 'msw';
import type { ReactNode } from 'react';
import { server } from '@/__tests__/mocks/server';
import { ConversationMonitoring } from './ConversationMonitoring';

const BASE = '/api/v1';

const mockLifecycle = {
  active: 10,
  about_to_timeout: 2,
  completed_today: 3,
  reopened_today: 0,
  about_to_timeout_list: [
    { conv_id: 'aaaa-bbbb-cccc-dddd', scam_type: 'PHISHING', persona: 'elderly_person', last_activity: '2026-03-20T10:00:00Z', hours_remaining: 4, timeout_hours: 48 },
  ],
  by_scam_type: {
    PHISHING: { active: 5, about_to_timeout: 1, policy_timeout_hours: 48 },
    ROMANCE: { active: 3, about_to_timeout: 0, policy_timeout_hours: 72 },
  },
};

const mockRateLimits = {
  llm_calls_limit: 100,
  active_conversations_limit: 50,
  quarantined_senders_today: 0,
  rate_limited_today: [{ endpoint: '/api/v1/reply', count: 3 }],
};

function setupHandlers() {
  server.use(
    http.get(`${BASE}/monitoring/conversation-lifecycle`, () => HttpResponse.json(mockLifecycle)),
    http.get(`${BASE}/monitoring/rate-limits`, () => HttpResponse.json(mockRateLimits)),
  );
}

beforeAll(() => server.listen({ onUnhandledRequest: 'bypass' }));
afterEach(() => server.resetHandlers());
afterAll(() => server.close());

function createWrapper() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return function Wrapper({ children }: { children: ReactNode }) {
    return (
      <QueryClientProvider client={qc}>
        <MemoryRouter>{children}</MemoryRouter>
      </QueryClientProvider>
    );
  };
}

describe('ConversationMonitoring', () => {
  it('renders without crashing', async () => {
    setupHandlers();
    render(<ConversationMonitoring />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(document.body.textContent?.length).toBeGreaterThan(0);
    });
  });

  it('displays the page title', async () => {
    setupHandlers();
    render(<ConversationMonitoring />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/Conversation Monitoring|monitoring/i)).toBeInTheDocument();
    });
  });

  it('shows loading state', () => {
    server.use(
      http.get(`${BASE}/monitoring/conversation-lifecycle`, async () => {
        await new Promise((r) => setTimeout(r, 5000));
        return HttpResponse.json(mockLifecycle);
      }),
    );
    render(<ConversationMonitoring />, { wrapper: createWrapper() });
    expect(document.body.textContent).toMatch(/loading/i);
  });

  it('shows stat cards with correct values', async () => {
    setupHandlers();
    render(<ConversationMonitoring />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('10')).toBeInTheDocument();
      expect(screen.getByText('2')).toBeInTheDocument();
    });
  });

  it('renders timeout table with conversation data', async () => {
    setupHandlers();
    render(<ConversationMonitoring />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('aaaa-bbb')).toBeInTheDocument();
    });
  });

  it('shows error state when data fails', async () => {
    server.use(
      http.get(`${BASE}/monitoring/conversation-lifecycle`, () =>
        HttpResponse.json({ error: 'fail' }, { status: 500 }),
      ),
    );
    render(<ConversationMonitoring />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(document.body.textContent).toMatch(/error/i);
    });
  });

  it('renders rate limits section', async () => {
    setupHandlers();
    render(<ConversationMonitoring />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/Rate Limits/i)).toBeInTheDocument();
    });
  });
});
