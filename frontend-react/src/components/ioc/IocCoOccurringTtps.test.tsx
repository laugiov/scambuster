import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { http, HttpResponse } from 'msw';
import type { ReactNode } from 'react';
import { server } from '@/__tests__/mocks/server';
import { IocCoOccurringTtps } from './IocCoOccurringTtps';
import '../../i18n';

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

describe('IocCoOccurringTtps', () => {
  it('renders a phase-coloured badge per co-occurring TTP', async () => {
    server.use(
      http.get(`${BASE}/iocs/ind-1/ttps`, () =>
        HttpResponse.json({
          ioc: 'ind-1',
          ttps: [
            { ttp_code: 'SB-T017', ttp_label: 'Payment demand', phase: 'payment-request', co_occurrence_count: 4, conversation_count: 2 },
            { ttp_code: 'SB-T001', ttp_label: 'Cold outreach', phase: 'hook', co_occurrence_count: 1, conversation_count: 1 },
          ],
        })),
    );

    render(<IocCoOccurringTtps indicatorId="ind-1" />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getAllByTestId('ioc-ttp-badge')).toHaveLength(2);
    });
    expect(screen.getByText('Payment demand')).toBeInTheDocument();
    expect(screen.getByText('Cold outreach')).toBeInTheDocument();
    expect(screen.getByText('SB-T017')).toBeInTheDocument();
    // Co-occurrence count label ("4 msg").
    expect(screen.getByText(/4 msg/)).toBeInTheDocument();
  });

  it('shows a dashed empty note when the IOC has no co-observed TTPs', async () => {
    server.use(
      http.get(`${BASE}/iocs/ind-2/ttps`, () => HttpResponse.json({ ioc: 'ind-2', ttps: [] })),
    );

    render(<IocCoOccurringTtps indicatorId="ind-2" />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getByTestId('ioc-ttps-empty')).toBeInTheDocument();
    });
    expect(screen.queryByTestId('ioc-ttp-badge')).toBeNull();
  });

  it('shows the empty note (not an error) on a 404', async () => {
    server.use(
      http.get(`${BASE}/iocs/ind-404/ttps`, () =>
        HttpResponse.json({ error: 'Indicator not found' }, { status: 404 })),
    );

    render(<IocCoOccurringTtps indicatorId="ind-404" />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getByTestId('ioc-ttps-empty')).toBeInTheDocument();
    });
  });
});
