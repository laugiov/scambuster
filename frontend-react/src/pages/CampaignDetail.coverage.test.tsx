import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
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
  profile_yaml: 'name: test\ntype: phishing',
  rule: {
    rule_id: 'rule-1111-2222-3333',
    ppv: 0.92,
    hits_total: 12,
    lead_time_hours: 48,
    promoted_at: null,
  },
};

const mockMessages = [
  { msg_id: 'msg-1', subject: 'Urgent invoice', from: 'scammer@evil.com', received_at: '2026-03-20T10:00:00Z', body_preview: 'Please pay attached' },
  { msg_id: 'msg-2', subject: null, from: null, received_at: '2026-03-21T10:00:00Z', body_preview: 'Follow up message' },
];

function setupHandlers(campaign?: object) {
  server.use(
    http.get(`${BASE}/campaign/${CAMPAIGN_ID}/detail`, () => HttpResponse.json(campaign ?? mockCampaign)),
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

describe('CampaignDetail — coverage gaps', () => {
  it('renders profile YAML when campaign has existing profile', async () => {
    setupHandlers();
    render(<CampaignDetail />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/name: test/)).toBeInTheDocument();
    });
  });

  it('shows no profile message when no profile_yaml', async () => {
    setupHandlers({ ...mockCampaign, profile_yaml: null });
    render(<CampaignDetail />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/No profile/i)).toBeInTheDocument();
    });
  });

  it('renders messages with null subject and from as --', async () => {
    setupHandlers();
    render(<CampaignDetail />, { wrapper: createWrapper() });
    await waitFor(() => {
      const dashes = screen.getAllByText('--');
      expect(dashes.length).toBeGreaterThanOrEqual(2);
    });
  });

  it('renders empty messages table', async () => {
    server.use(
      http.get(`${BASE}/campaign/${CAMPAIGN_ID}/detail`, () => HttpResponse.json(mockCampaign)),
      http.get(`${BASE}/campaign/${CAMPAIGN_ID}/messages`, () => HttpResponse.json([])),
    );
    render(<CampaignDetail />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/No messages/i)).toBeInTheDocument();
    });
  });

  it('shows confirm dialog when promote button is clicked', async () => {
    setupHandlers();
    render(<CampaignDetail />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/Promote to Active Detection/i)).toBeInTheDocument();
    });
    fireEvent.click(screen.getByText(/Promote to Active Detection/i));
    await waitFor(() => {
      expect(screen.getByText(/Promote this rule/i)).toBeInTheDocument();
    });
  });

  it('cancels promote when Cancel is clicked', async () => {
    setupHandlers();
    render(<CampaignDetail />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/Promote to Active Detection/i)).toBeInTheDocument();
    });
    fireEvent.click(screen.getByText(/Promote to Active Detection/i));
    await waitFor(() => {
      expect(screen.getByText(/Promote this rule/i)).toBeInTheDocument();
    });
    const cancelBtn = screen.getByText('Cancel');
    fireEvent.click(cancelBtn);
    await waitFor(() => {
      expect(screen.getByText(/Promote to Active Detection/i)).toBeInTheDocument();
    });
  });

  it('confirms promote and calls API', async () => {
    setupHandlers();
    server.use(
      http.post(`${BASE}/campaign/rule/rule-1111-2222-3333/promote`, () => HttpResponse.json({ success: true })),
    );
    render(<CampaignDetail />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/Promote to Active Detection/i)).toBeInTheDocument();
    });
    fireEvent.click(screen.getByText(/Promote to Active Detection/i));
    await waitFor(() => {
      expect(screen.getByText(/Promote this rule/i)).toBeInTheDocument();
    });
    const confirmBtn = screen.getByText('Confirm');
    fireEvent.click(confirmBtn);
    await waitFor(() => {
      expect(screen.getByText(/Promoted/i)).toBeInTheDocument();
    });
  });

  it('renders generate profile button when no profile exists', async () => {
    setupHandlers({ ...mockCampaign, profile_yaml: null });
    render(<CampaignDetail />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getAllByText(/Generate Profile/i).length).toBeGreaterThan(0);
    });
    expect(screen.getByText(/No profile/i)).toBeInTheDocument();
  });

  it('renders promoted status badge', async () => {
    setupHandlers({ ...mockCampaign, status: 'promoted', rule: { ...mockCampaign.rule, promoted_at: '2026-03-21T00:00:00Z' } });
    render(<CampaignDetail />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('PROMOTED')).toBeInTheDocument();
    });
  });

  it('shows not promotable message for low PPV', async () => {
    setupHandlers({ ...mockCampaign, rule: { ...mockCampaign.rule, ppv: 0.5, hits_total: 2 } });
    render(<CampaignDetail />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/does not meet promotion/i)).toBeInTheDocument();
    });
  });

  it('renders rule info section', async () => {
    setupHandlers();
    render(<CampaignDetail />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/Detection Rule/i)).toBeInTheDocument();
      expect(screen.getByText('rule-1111-22')).toBeInTheDocument();
    });
  });

  it('triggers STIX export from actions panel', async () => {
    setupHandlers();
    server.use(
      http.post(`${BASE}/campaign/${CAMPAIGN_ID}/export/stix`, () =>
        HttpResponse.json({ bundle_id: 'b--1', file_path: '/tmp/bundle.json', bundle: { objects: [] } }),
      ),
    );
    render(<CampaignDetail />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/Export STIX/i)).toBeInTheDocument();
    });
    fireEvent.click(screen.getByText(/Export STIX/i));
    await waitFor(() => {
      expect(screen.getByText(/exported|success/i)).toBeInTheDocument();
    });
  });
});
