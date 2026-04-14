import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { http, HttpResponse } from 'msw';
import type { ReactNode } from 'react';
import { server } from '@/__tests__/mocks/server';
import { StixExport } from './StixExport';

const BASE = '/api/v1';

const mockCandidates = [
  { campaign_id: 'camp-aaaa-bbbb', rule_id: 'rule-1111', ppv: 0.92, hits_total: 12, lead_time_hours: 48, created_at: '2026-03-20T10:00:00Z' },
  { campaign_id: 'camp-cccc-dddd', rule_id: 'rule-2222', ppv: 0.78, hits_total: 5, lead_time_hours: 24, created_at: '2026-03-21T10:00:00Z' },
];

const mockStats = {
  status: 'operational',
  conversations: { total: 15, active: 3, closed: 10, abandoned: 2 },
  messages: { total: 42, inbound: 20, outbound: 22 },
  iocs: { total: 89, unique_types: 6 },
  convergence: { status: 'converging', best_persona: 'elderly_person', best_score: 0.82, exploration_rate: 0.15 },
  kill_switch: false,
  checked_at: new Date().toISOString(),
};

const mockBundle = {
  bundle_id: 'bundle--12345678-1234',
  file_path: '/tmp/stix-export.json',
  bundle: {
    type: 'bundle',
    id: 'bundle--12345678-1234',
    objects: [
      { type: 'indicator', id: 'indicator--1', pattern: "[domain:value='evil.com']" },
      { type: 'indicator', id: 'indicator--2', pattern: "[email:value='bad@evil.com']" },
      { type: 'identity', id: 'identity--1', name: 'ScamBuster' },
    ],
  },
};

function setupHandlers() {
  server.use(
    http.get(`${BASE}/campaign/candidates`, () => HttpResponse.json({ candidates: mockCandidates })),
    http.get(`${BASE}/monitoring/autonomy`, () => HttpResponse.json(mockStats)),
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
        <MemoryRouter>{children}</MemoryRouter>
      </QueryClientProvider>
    );
  };
}

describe('StixExport — coverage gaps', () => {
  it('renders no campaigns message when list is empty', async () => {
    server.use(
      http.get(`${BASE}/campaign/candidates`, () => HttpResponse.json({ candidates: [] })),
      http.get(`${BASE}/monitoring/autonomy`, () => HttpResponse.json(mockStats)),
    );
    render(<StixExport />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/No campaigns available/i)).toBeInTheDocument();
    });
  });

  it('renders multiple campaign rows with PPV and hits', async () => {
    setupHandlers();
    render(<StixExport />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/camp-aaa/)).toBeInTheDocument();
      expect(screen.getByText(/camp-ccc/)).toBeInTheDocument();
    });
    expect(screen.getByText('PPV: 92%')).toBeInTheDocument();
    expect(screen.getByText('PPV: 78%')).toBeInTheDocument();
  });

  it('triggers export and shows bundle preview', async () => {
    setupHandlers();
    server.use(
      http.post(`${BASE}/campaign/:id/export/stix`, () => HttpResponse.json(mockBundle)),
    );
    render(<StixExport />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/camp-aaa/)).toBeInTheDocument();
    });
    const exportButtons = screen.getAllByText('Export STIX');
    fireEvent.click(exportButtons[0]);
    await waitFor(() => {
      // Bundle preview should show
      expect(screen.getByText(/Download/i)).toBeInTheDocument();
    });
  });

  it('shows indicator count after successful export', async () => {
    setupHandlers();
    server.use(
      http.post(`${BASE}/campaign/:id/export/stix`, () => HttpResponse.json(mockBundle)),
    );
    render(<StixExport />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/camp-aaa/)).toBeInTheDocument();
    });
    const exportButtons = screen.getAllByText('Export STIX');
    fireEvent.click(exportButtons[0]);
    await waitFor(() => {
      // Should show "2" for 2 indicators in the stat card
      expect(screen.getByText('2')).toBeInTheDocument();
    });
  });

  it('shows export error message', async () => {
    setupHandlers();
    server.use(
      http.post(`${BASE}/campaign/:id/export/stix`, () =>
        HttpResponse.json({ error: 'export failed' }, { status: 500 }),
      ),
    );
    render(<StixExport />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/camp-aaa/)).toBeInTheDocument();
    });
    const exportButtons = screen.getAllByText('Export STIX');
    fireEvent.click(exportButtons[0]);
    await waitFor(() => {
      expect(document.body.textContent).toMatch(/error|fail/i);
    });
  });

  it('triggers download of STIX bundle', async () => {
    setupHandlers();
    server.use(
      http.post(`${BASE}/campaign/:id/export/stix`, () => HttpResponse.json(mockBundle)),
    );
    // Mock URL and DOM APIs
    const createObjectURLSpy = vi.fn(() => 'blob:mock-url');
    const revokeObjectURLSpy = vi.fn();
    const origCreate = URL.createObjectURL;
    const origRevoke = URL.revokeObjectURL;
    URL.createObjectURL = createObjectURLSpy;
    URL.revokeObjectURL = revokeObjectURLSpy;

    render(<StixExport />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/camp-aaa/)).toBeInTheDocument();
    });
    // Export the bundle first
    const exportButtons = screen.getAllByText('Export STIX');
    fireEvent.click(exportButtons[0]);
    // Wait for the bundle JSON to render in the preview
    await waitFor(() => {
      expect(screen.getByText(/stix-export\.json/)).toBeInTheDocument();
    });
    // Now the Download link should be visible — it's a <button> in the preview panel header
    const allButtons = document.querySelectorAll('button');
    const downloadBtn = Array.from(allButtons).find((b) => b.textContent?.trim() === 'Download JSON');
    expect(downloadBtn).toBeDefined();
    fireEvent.click(downloadBtn!);
    expect(createObjectURLSpy).toHaveBeenCalled();

    URL.createObjectURL = origCreate;
    URL.revokeObjectURL = origRevoke;
  });

  it('shows success path message after export', async () => {
    setupHandlers();
    server.use(
      http.post(`${BASE}/campaign/:id/export/stix`, () => HttpResponse.json(mockBundle)),
    );
    render(<StixExport />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/camp-aaa/)).toBeInTheDocument();
    });
    const exportButtons = screen.getAllByText('Export STIX');
    fireEvent.click(exportButtons[0]);
    await waitFor(() => {
      expect(screen.getByText(/stix-export\.json/)).toBeInTheDocument();
    });
  });
});
