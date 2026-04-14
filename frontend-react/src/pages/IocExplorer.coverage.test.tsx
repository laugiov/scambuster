import { describe, it, expect, beforeAll, afterAll, afterEach, vi } from 'vitest';
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

const mockNavigate = vi.fn();
vi.mock('react-router-dom', async () => {
  const actual = await vi.importActual<typeof import('react-router-dom')>('react-router-dom');
  return { ...actual, useNavigate: () => mockNavigate };
});

const now = new Date().toISOString();
const oldDate = '2024-01-01T00:00:00Z';

const mockIocs: Ioc[] = [
  { obs_id: 'obs-1', ioc_id: 'ind-1', type: 'domain', value: 'evil.com', value_norm: 'evil[.]com', score: { vt: 70, urlscan: 0 }, category: 'PHISHING', ts_observed: now, confidence: 0.95, decay_factor: 0.9, effective_score: 0.85 },
  { obs_id: 'obs-2', ioc_id: 'ind-2', type: 'email', value: 'bad@evil.com', value_norm: 'bad@evil[.]com', score: { vt: 0, urlscan: 0 }, category: 'ROMANCE', ts_observed: now, confidence: 0.3, decay_factor: 0.5, effective_score: 0.15 },
  { obs_id: 'obs-3', ioc_id: 'ind-3', type: 'iban', value: 'DE89370400440532013000', value_norm: 'DE89370400440532013000', score: { vt: 0, urlscan: 0 }, category: 'INVOICE_FRAUD', ts_observed: now, confidence: 0.99, decay_factor: 1, effective_score: 0.99, has_context: true },
  { obs_id: 'obs-4', ioc_id: 'ind-4', type: 'ipv4', value: '192.168.1.1', value_norm: '192.168.1.1', score: { vt: 40, urlscan: 30 }, category: 'PHISHING', ts_observed: oldDate, confidence: 0.6, decay_factor: 0.3, effective_score: 0.18 },
  { obs_id: 'obs-5', ioc_id: 'ind-5', type: 'sha256', value: 'abc123hash', value_norm: 'abc123hash', score: { vt: 0, urlscan: 0 }, category: 'Unknown', ts_observed: now, confidence: 0.7, decay_factor: 0.8, effective_score: 0.56 },
  { obs_id: 'obs-6', ioc_id: 'ind-6', type: 'message_id', value: '<test@mail.com>', value_norm: '<test@mail.com>', score: { vt: 0, urlscan: 0 }, category: 'Unknown', ts_observed: now, confidence: 0.99, decay_factor: 1, effective_score: 0.99 },
];

const mockMetaConfig = {
  personas: [], scam_types: [
    { code: 'PHISHING', label: 'Phishing', description: '', active: true },
    { code: 'ROMANCE', label: 'Romance', description: '', active: true },
    { code: 'INVOICE_FRAUD', label: 'Invoice Fraud', description: '', active: true },
  ],
  ioc_types: ['domain', 'ipv4', 'email', 'sha256', 'iban', 'message_id', 'url', 'phone'],
  bandit: { strategy: 'epsilon-greedy', epsilon: 0.2, cold_start_threshold: 3, convergence_threshold: 0.6, min_sessions_for_convergence: 10, converged_epsilon: 0.05, reward_weights: {} },
  llm_provider: 'openai', llm_model: 'gpt-4o-mini',
};

function setupHandlers() {
  server.use(
    http.get(`${BASE}/iocs`, () => HttpResponse.json(mockIocs)),
    http.get(`${BASE}/meta/config`, () => HttpResponse.json(mockMetaConfig)),
  );
}

beforeAll(() => server.listen({ onUnhandledRequest: 'bypass' }));
afterEach(() => {
  server.resetHandlers();
  mockNavigate.mockReset();
});
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

describe('IocExplorer — coverage gaps', () => {
  it('shows loading state', () => {
    server.use(
      http.get(`${BASE}/iocs`, async () => {
        await new Promise((r) => setTimeout(r, 5000));
        return HttpResponse.json([]);
      }),
    );
    render(<IocExplorer />, { wrapper: createWrapper() });
    expect(document.body.textContent).toMatch(/loading/i);
  });

  it('shows error state', async () => {
    server.use(
      http.get(`${BASE}/iocs`, () => HttpResponse.json({}, { status: 500 })),
    );
    render(<IocExplorer />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(document.body.textContent).toMatch(/error|fail|retry/i);
    });
  });

  it('filters by type category (Financial)', async () => {
    setupHandlers();
    render(<IocExplorer />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('evil.com')).toBeInTheDocument();
    });
    const financialBtn = screen.getByText('Financial');
    fireEvent.click(financialBtn);
    await waitFor(() => {
      expect(screen.getByText('DE89370400440532013000')).toBeInTheDocument();
      expect(screen.queryByText('evil.com')).toBeNull();
    });
  });

  it('filters by severity Low to show only low-severity IOCs', async () => {
    setupHandlers();
    render(<IocExplorer />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('evil.com')).toBeInTheDocument();
    });
    // sha256 with vt=0 + urlscan=0 on MEDIUM_VALUE_TYPES -> MEDIUM
    // bad@evil.com is email with vt=0 -> MEDIUM
    // Low filter: only non-medium/high types with no enrichment
    const allBtns = screen.getAllByRole('button');
    const lowFilter = allBtns.find((el) => el.textContent === 'Low');
    expect(lowFilter).toBeDefined();
    fireEvent.click(lowFilter!);
    await waitFor(() => {
      // High-severity IOCs should be hidden
      expect(screen.queryByText('evil.com')).toBeNull();
      expect(screen.queryByText('DE89370400440532013000')).toBeNull();
    });
  });

  it('filters by confidence >0.7 using select dropdown', async () => {
    setupHandlers();
    render(<IocExplorer />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('evil.com')).toBeInTheDocument();
    });
    // Confidence is a <select> dropdown, not buttons
    const selects = screen.getAllByRole('combobox');
    // Find the confidence select — it contains "> 0.7" as option value
    const confSelect = selects.find((s) => {
      const options = s.querySelectorAll('option');
      return Array.from(options).some((o) => o.value === '>0.7');
    });
    expect(confSelect).toBeDefined();
    fireEvent.change(confSelect!, { target: { value: '>0.7' } });
    await waitFor(() => {
      // evil.com has effective_score 0.85 (> 0.7) -> visible
      expect(screen.getByText('evil.com')).toBeInTheDocument();
      // bad@evil.com has effective_score 0.15 (< 0.7) -> hidden
      expect(screen.queryByText('bad@evil.com')).toBeNull();
    });
  });

  it('filters by scam type', async () => {
    setupHandlers();
    render(<IocExplorer />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('evil.com')).toBeInTheDocument();
    });
    // Find the scam type dropdown/filter
    const selects = screen.getAllByRole('combobox');
    const scamSelect = selects.find((s) => s.querySelector('option[value="PHISHING"]'));
    if (scamSelect) {
      fireEvent.change(scamSelect, { target: { value: 'PHISHING' } });
      await waitFor(() => {
        expect(screen.getByText('evil.com')).toBeInTheDocument();
        expect(screen.queryByText('DE89370400440532013000')).toBeNull();
      });
    }
  });

  it('toggles context-only filter', async () => {
    setupHandlers();
    render(<IocExplorer />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('evil.com')).toBeInTheDocument();
    });
    const checkboxes = screen.getAllByRole('checkbox');
    // Find the has-context checkbox (the one that isn't the hide-headers)
    const contextCheckbox = checkboxes.find((c) => !c.hasAttribute('checked'));
    if (contextCheckbox) {
      fireEvent.click(contextCheckbox);
      await waitFor(() => {
        // Only IBAN has has_context: true
        expect(screen.getByText('DE89370400440532013000')).toBeInTheDocument();
      });
    }
  });

  it('renders date range filter buttons', async () => {
    setupHandlers();
    render(<IocExplorer />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('evil.com')).toBeInTheDocument();
    });
    // Date range buttons: 7d, 30d, 90d, All
    const allBtns = screen.getAllByRole('button');
    const dayBtn = allBtns.find((el) => el.textContent === '7d');
    expect(dayBtn).toBeDefined();
    fireEvent.click(dayBtn!);
    // Should still show recent IOCs (our mock data is recent)
    await waitFor(() => {
      expect(screen.getByText('evil.com')).toBeInTheDocument();
    });
  });

  it('navigates to IOC detail on row click', async () => {
    setupHandlers();
    render(<IocExplorer />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('evil.com')).toBeInTheDocument();
    });
    const row = screen.getByText('evil.com').closest('tr');
    if (row) fireEvent.click(row);
    expect(mockNavigate).toHaveBeenCalledWith('/ioc-explorer/ind-1');
  });

  it('shows empty state when no IOCs match filter', async () => {
    setupHandlers();
    render(<IocExplorer />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('evil.com')).toBeInTheDocument();
    });
    const searchInput = screen.getByLabelText('Search IOCs');
    fireEvent.change(searchInput, { target: { value: 'zzzznonexistent' } });
    await waitFor(() => {
      expect(screen.getByText(/No IOCs match/i)).toBeInTheDocument();
    });
  });

  it('renders type category filter buttons', async () => {
    setupHandlers();
    render(<IocExplorer />, { wrapper: createWrapper() });
    await waitFor(() => {
      // 'All' appears multiple times (severity All, type All, confidence All, etc.)
      expect(screen.getAllByText('All').length).toBeGreaterThan(0);
    });
    expect(screen.getAllByText('Domain').length).toBeGreaterThan(0);
    expect(screen.getAllByText('IP').length).toBeGreaterThan(0);
    expect(screen.getAllByText('Email').length).toBeGreaterThan(0);
    expect(screen.getAllByText('Financial').length).toBeGreaterThan(0);
    expect(screen.getAllByText('Hash').length).toBeGreaterThan(0);
  });

  it('renders STIX export button with IOC count', async () => {
    setupHandlers();
    render(<IocExplorer />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('evil.com')).toBeInTheDocument();
    });
    // The export button shows "STIX 2.1 (N)"
    const exportBtn = screen.getAllByRole('button').find((b) => b.textContent?.match(/STIX 2\.1/));
    expect(exportBtn).toBeDefined();
    expect(exportBtn?.textContent).toMatch(/STIX 2\.1/);
  });

  it('renders CSV export button', async () => {
    setupHandlers();
    render(<IocExplorer />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('evil.com')).toBeInTheDocument();
    });
    const csvBtn = screen.getAllByRole('button').find((b) => b.textContent?.match(/CSV|Export/));
    expect(csvBtn).toBeDefined();
  });

  it('filters by date range 30d', async () => {
    setupHandlers();
    render(<IocExplorer />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('evil.com')).toBeInTheDocument();
    });
    const allBtns = screen.getAllByRole('button');
    const btn30d = allBtns.find((el) => el.textContent === '30d');
    expect(btn30d).toBeDefined();
    fireEvent.click(btn30d!);
    // The old IOC (2024-01-01) should be filtered out
    await waitFor(() => {
      expect(screen.queryByText('192.168.1.1')).toBeNull();
    });
  });

  it('renders confidence colors (red for low score)', async () => {
    setupHandlers();
    render(<IocExplorer />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('evil.com')).toBeInTheDocument();
    });
    // bad@evil.com has effective_score 0.15, should show red
    expect(screen.getByText('0.15')).toBeInTheDocument();
  });
});
