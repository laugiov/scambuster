import { describe, it, expect, beforeAll, afterAll, afterEach, vi } from 'vitest';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { http, HttpResponse } from 'msw';
import type { ReactNode } from 'react';
import { server } from '@/__tests__/mocks/server';
import Conversations from './Conversations';
import { mockMetaConfig } from '@/__tests__/fixtures';

const BASE = '/api/v1';

const mockConversations = [
  {
    conv_id: 'aaaa-bbbb-cccc-dddd',
    status: 'open',
    score_risk: 50,
    persona: 'elderly_person',
    scam_type: 'PHISHING',
    turns: 4,
    message_count: 8,
    ioc_count: 3,
    ts_first: '2026-03-20T10:00:00Z',
    ts_last: '2026-03-20T12:00:00Z',
    updated_at: '2026-03-20T12:00:00Z',
  },
  {
    conv_id: 'eeee-ffff-0000-1111',
    status: 'closed',
    score_risk: 80,
    persona: 'bank_customer',
    scam_type: 'ROMANCE',
    turns: 12,
    message_count: 24,
    ioc_count: 7,
    ts_first: '2026-03-19T08:00:00Z',
    ts_last: '2026-03-19T16:00:00Z',
    updated_at: '2026-03-19T16:00:00Z',
  },
  {
    conv_id: '2222-3333-4444-5555',
    status: 'abandoned',
    score_risk: 20,
    persona: null,
    scam_type: null,
    turns: 2,
    message_count: null,
    ioc_count: null,
    ts_first: '2026-03-18T08:00:00Z',
    ts_last: null,
    updated_at: '2026-03-18T08:00:00Z',
  },
];

const mockStats = {
  status: 'operational',
  conversations: { total: 15, active: 3, open: 3, closed: 10, abandoned: 2 },
  messages: { total: 42, inbound: 20, outbound: 22 },
  iocs: { total: 89, unique_types: 6 },
  convergence: { status: 'converging', best_persona: 'elderly_person', best_score: 0.82, exploration_rate: 0.15 },
  kill_switch: false,
  checked_at: new Date().toISOString(),
};

function setupHandlers() {
  server.use(
    http.get(`${BASE}/communication/conversation`, () => HttpResponse.json(mockConversations)),
    http.get(`${BASE}/meta/config`, () => HttpResponse.json(mockMetaConfig)),
    http.get(`${BASE}/monitoring/autonomy`, () => HttpResponse.json(mockStats)),
  );
}

beforeAll(() => server.listen({ onUnhandledRequest: 'warn' }));
afterEach(() => server.resetHandlers());
afterAll(() => server.close());

const mockNavigate = vi.fn();
vi.mock('react-router-dom', async () => {
  const actual = await vi.importActual<typeof import('react-router-dom')>('react-router-dom');
  return { ...actual, useNavigate: () => mockNavigate };
});

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

describe('Conversations page — coverage gaps', () => {
  afterEach(() => mockNavigate.mockReset());

  it('shows loading state', () => {
    server.use(
      http.get(`${BASE}/communication/conversation`, async () => {
        await new Promise((r) => setTimeout(r, 5000));
        return HttpResponse.json([]);
      }),
    );
    render(<Conversations />, { wrapper: createWrapper() });
    expect(document.body.textContent).toMatch(/loading/i);
  });

  it('shows error state when conversations fail', async () => {
    server.use(
      http.get(`${BASE}/communication/conversation`, () =>
        HttpResponse.json({ error: 'fail' }, { status: 500 }),
      ),
    );
    render(<Conversations />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(document.body.textContent).toMatch(/error|fail|retry/i);
    });
  });

  it('displays stat counters (total, active, closed, abandoned)', async () => {
    setupHandlers();
    render(<Conversations />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/15 total/i)).toBeInTheDocument();
    });
    expect(screen.getByText(/3 active/i)).toBeInTheDocument();
    expect(screen.getByText(/10 closed/i)).toBeInTheDocument();
    expect(screen.getByText(/2 abandoned/i)).toBeInTheDocument();
  });

  it('renders conversation rows with scam type and persona', async () => {
    setupHandlers();
    render(<Conversations />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('aaaa-bbb')).toBeInTheDocument();
    });
    expect(screen.getByText('eeee-fff')).toBeInTheDocument();
    expect(screen.getAllByText('Phishing').length).toBeGreaterThan(0);
  });

  it('renders -- for null scam_type and persona', async () => {
    setupHandlers();
    render(<Conversations />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('2222-333')).toBeInTheDocument();
    });
    // The row for 2222-3333 should display '--' for scam_type and persona
    const dashCells = screen.getAllByText('--');
    expect(dashCells.length).toBeGreaterThanOrEqual(2);
  });

  it('navigates to conversation detail on row click', async () => {
    setupHandlers();
    render(<Conversations />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('aaaa-bbb')).toBeInTheDocument();
    });
    const row = screen.getByText('aaaa-bbb').closest('tr');
    fireEvent.click(row!);
    expect(mockNavigate).toHaveBeenCalledWith('/conversations/aaaa-bbbb-cccc-dddd');
  });

  it('sorts by risk score when Risk column header is clicked', async () => {
    setupHandlers();
    render(<Conversations />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('aaaa-bbb')).toBeInTheDocument();
    });
    const riskHeader = screen.getByText(/Risk/i).closest('th');
    fireEvent.click(riskHeader!);
    // After clicking, the sort should toggle to score_risk desc
    await waitFor(() => {
      const rows = screen.getAllByRole('link');
      // eeee-ffff (risk 80) should be first
      expect(rows[0].textContent).toContain('eeee-fff');
    });
  });

  it('sorts by IOCs when IOCs column header is clicked', async () => {
    setupHandlers();
    render(<Conversations />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('aaaa-bbb')).toBeInTheDocument();
    });
    const iocHeader = screen.getByText('IOCs').closest('th');
    fireEvent.click(iocHeader!);
    await waitFor(() => {
      const rows = screen.getAllByRole('link');
      expect(rows[0].textContent).toContain('eeee-fff');
    });
  });

  it('toggles sort direction when clicking same column header twice', async () => {
    setupHandlers();
    render(<Conversations />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('aaaa-bbb')).toBeInTheDocument();
    });
    const riskHeader = screen.getByText(/Risk/i).closest('th');
    fireEvent.click(riskHeader!);
    fireEvent.click(riskHeader!);
    // Now ascending, risk 20 first
    await waitFor(() => {
      const rows = screen.getAllByRole('link');
      expect(rows[0].textContent).toContain('2222-333');
    });
  });

  it('filters by search query matching conv_id', async () => {
    setupHandlers();
    render(<Conversations />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('aaaa-bbb')).toBeInTheDocument();
    });
    const searchInput = screen.getByLabelText('Search conversations');
    fireEvent.change(searchInput, { target: { value: 'aaaa' } });
    await waitFor(() => {
      expect(screen.getByText('aaaa-bbb')).toBeInTheDocument();
      expect(screen.queryByText('eeee-fff')).toBeNull();
    });
  });

  it('filters by search query matching persona display name', async () => {
    setupHandlers();
    render(<Conversations />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('aaaa-bbb')).toBeInTheDocument();
    });
    const searchInput = screen.getByLabelText('Search conversations');
    fireEvent.change(searchInput, { target: { value: 'Elderly' } });
    await waitFor(() => {
      expect(screen.getByText('aaaa-bbb')).toBeInTheDocument();
      expect(screen.queryByText('eeee-fff')).toBeNull();
    });
  });

  it('shows filter summary text when filters are active', async () => {
    setupHandlers();
    render(<Conversations />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('aaaa-bbb')).toBeInTheDocument();
    });
    const searchInput = screen.getByLabelText('Search conversations');
    fireEvent.change(searchInput, { target: { value: 'aaaa' } });
    await waitFor(() => {
      expect(screen.getByText(/1 conversation/)).toBeInTheDocument();
      expect(screen.getByText(/"aaaa"/)).toBeInTheDocument();
    });
  });

  it('shows empty state when no conversations match filters', async () => {
    setupHandlers();
    render(<Conversations />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('aaaa-bbb')).toBeInTheDocument();
    });
    const searchInput = screen.getByLabelText('Search conversations');
    fireEvent.change(searchInput, { target: { value: 'zzzznonexistent' } });
    await waitFor(() => {
      expect(screen.getByText(/No conversations/i)).toBeInTheDocument();
    });
  });

  it('renders message_count with fallback to turns', async () => {
    setupHandlers();
    render(<Conversations />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('aaaa-bbb')).toBeInTheDocument();
    });
    // conv 1 has message_count 8, conv 2 has message_count 24
    expect(screen.getByText('8')).toBeInTheDocument();
    expect(screen.getByText('24')).toBeInTheDocument();
  });

  it('renders ioc_count 0 for null', async () => {
    setupHandlers();
    render(<Conversations />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('2222-333')).toBeInTheDocument();
    });
    // ioc_count null should display 0
    const zeros = screen.getAllByText('0');
    expect(zeros.length).toBeGreaterThanOrEqual(1);
  });
});
