import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { http, HttpResponse } from 'msw';
import type { ReactNode } from 'react';
import { server } from '@/__tests__/mocks/server';
import type { Ioc } from '@/types/api';
import { IocExplorer } from './IocExplorer';
import { mockMetaConfig as baseMockMetaConfig } from '@/__tests__/fixtures';
import '../i18n';

const BASE = '/api/v1';

const heldIban: Ioc = {
  obs_id: 'obs-h1', ioc_id: 'ind-h1', type: 'iban', value: 'AT611904300234573201',
  value_norm: 'AT611904300234573201', score: { vt: 0, urlscan: 0, agg: 0, explain: '' },
  category: 'Unknown', ts_observed: new Date().toISOString(),
  confidence: 0.9, decay_factor: 1, effective_score: 0.9,
  analyst_verdict: null, export_held: true,
};

const confirmedDomain: Ioc = {
  obs_id: 'obs-d1', ioc_id: 'ind-d1', type: 'domain', value: 'evil.example',
  value_norm: 'evil[.]example', score: { vt: 70, urlscan: 0, agg: 70, explain: '' },
  category: 'Unknown', ts_observed: new Date().toISOString(),
  confidence: 0.95, decay_factor: 1, effective_score: 0.95,
  analyst_verdict: 'confirmed', export_held: false,
};

const mockMetaConfig = {
  ...baseMockMetaConfig,
  personas: [], scam_types: [],
  ioc_types: ['domain', 'iban'],
};

const feedbackCalls: Array<{ id: string; body: unknown }> = [];

beforeAll(() => server.listen());
afterEach(() => { server.resetHandlers(); feedbackCalls.length = 0; });
afterAll(() => server.close());

function setupHandlers() {
  server.use(
    http.get(`${BASE}/iocs`, () => HttpResponse.json([heldIban, confirmedDomain])),
    http.get(`${BASE}/meta/config`, () => HttpResponse.json(mockMetaConfig)),
    http.post(`${BASE}/iocs/:id/feedback`, async ({ params, request }) => {
      const body = await request.json();
      feedbackCalls.push({ id: String(params.id), body });
      return HttpResponse.json({ indicator_id: String(params.id), verdict: 'confirmed' });
    }),
  );
}

function renderPage() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={qc}>
      <MemoryRouter>{children}</MemoryRouter>
    </QueryClientProvider>
  );
  return render(<IocExplorer />, { wrapper });
}

describe('IocExplorer — analyst review queue', () => {
  it('badges a held financial IOC and filters on review-only', async () => {
    setupHandlers();
    renderPage();

    await waitFor(() => expect(screen.getByText('AT611904300234573201')).toBeInTheDocument());
    expect(screen.getAllByText(/HELD|RETENU/).length).toBeGreaterThan(0);

    fireEvent.click(screen.getByTestId('review-only-toggle'));
    await waitFor(() => expect(screen.queryByText('evil.example')).not.toBeInTheDocument());
    expect(screen.getByText('AT611904300234573201')).toBeInTheDocument();
  });

  it('bulk-confirms the selection with a mandatory note', async () => {
    setupHandlers();
    renderPage();

    await waitFor(() => expect(screen.getByTestId('select-ind-h1')).toBeInTheDocument());
    // Only the held IOC is selectable — the confirmed domain has no checkbox.
    expect(screen.queryByTestId('select-ind-d1')).not.toBeInTheDocument();

    fireEvent.click(screen.getByTestId('select-ind-h1'));
    const confirmBtn = await screen.findByTestId('bulk-confirm');
    expect(confirmBtn).toBeDisabled(); // note is mandatory

    fireEvent.change(screen.getByTestId('bulk-note'), { target: { value: 'cluster reviewed' } });
    expect(confirmBtn).not.toBeDisabled();

    fireEvent.click(confirmBtn);
    await waitFor(() => expect(screen.getByTestId('bulk-result')).toBeInTheDocument());

    expect(feedbackCalls).toHaveLength(1);
    expect(feedbackCalls[0].id).toBe('ind-h1');
    expect(feedbackCalls[0].body).toEqual({ verdict: 'confirmed', note: 'cluster reviewed' });
  });
});
