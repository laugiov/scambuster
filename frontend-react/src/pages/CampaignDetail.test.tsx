import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { http, HttpResponse } from 'msw';
import type { ReactNode } from 'react';
import { server } from '@/__tests__/mocks/server';
import { CampaignDetail } from './CampaignDetail';

const BASE = '/api/v1';
const CAMPAIGN_ID = 'camp-aaaa-bbbb-cccc';

const mockCampaign = {
  campaign_id: CAMPAIGN_ID,
  status: 'candidate',
  created_at: '2026-03-20T10:00:00Z',
  profile_yaml: null,
  rule: {
    rule_id: 'rule-1111-2222-3333',
    ppv: 0.92,
    hits_total: 12,
    lead_time_hours: 48,
    promoted_at: null,
  },
};

const mockMessages = [
  { msg_id: 'msg-1', subject: 'Urgent invoice', from: 'scammer@evil.com', received_at: '2026-03-20T10:00:00Z', body_preview: 'Please pay the attached invoice' },
];

function setupHandlers() {
  server.use(
    http.get(`${BASE}/campaign/${CAMPAIGN_ID}/detail`, () => HttpResponse.json(mockCampaign)),
    http.get(`${BASE}/campaign/${CAMPAIGN_ID}/messages`, () => HttpResponse.json(mockMessages)),
  );
}

beforeAll(() => server.listen({ onUnhandledRequest: 'warn' }));
afterEach(() => server.resetHandlers());
afterAll(() => server.close());

function createWrapper() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return function Wrapper({ children }: { children: ReactNode }) {
    return (
      <QueryClientProvider client={qc}>
        <MemoryRouter initialEntries={[`/campaigns/${CAMPAIGN_ID}`]}>
          <Routes>
            <Route path="/campaigns/:id" element={children} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>
    );
  };
}

describe('CampaignDetail', () => {
  it('renders the campaign detail with title and ID', async () => {
    setupHandlers();
    render(<CampaignDetail />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getAllByText(/Campaign/i).length).toBeGreaterThan(0);
      expect(screen.getByText(/#camp-aaa/)).toBeInTheDocument();
    });
  });

  it('shows loading state', () => {
    server.use(
      http.get(`${BASE}/campaign/${CAMPAIGN_ID}/detail`, async () => {
        await new Promise((r) => setTimeout(r, 5000));
        return HttpResponse.json(mockCampaign);
      }),
    );
    render(<CampaignDetail />, { wrapper: createWrapper() });
    expect(document.body.textContent).toMatch(/loading/i);
  });

  it('renders metadata with PPV and hits', async () => {
    setupHandlers();
    render(<CampaignDetail />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getAllByText('92.0%').length).toBeGreaterThan(0);
      expect(screen.getAllByText('12').length).toBeGreaterThan(0);
    });
  });

  it('renders messages table', async () => {
    setupHandlers();
    render(<CampaignDetail />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('Urgent invoice')).toBeInTheDocument();
      expect(screen.getByText('scammer@evil.com')).toBeInTheDocument();
    });
  });

  it('shows error state when not found', async () => {
    server.use(
      http.get(`${BASE}/campaign/${CAMPAIGN_ID}/detail`, () =>
        HttpResponse.json({ error: 'Not found' }, { status: 404 }),
      ),
    );
    render(<CampaignDetail />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(document.body.textContent).toMatch(/not found|error/i);
    });
  });

  it('renders STIX export button', async () => {
    setupHandlers();
    render(<CampaignDetail />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/Export STIX/i)).toBeInTheDocument();
    });
  });
});
