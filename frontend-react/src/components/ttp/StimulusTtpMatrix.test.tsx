import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { http, HttpResponse } from 'msw';
import type { ReactNode } from 'react';
import { server } from '@/__tests__/mocks/server';
import type { StimulusTtpMatrix as StimulusTtpMatrixData } from '@/types/ttp';
import { StimulusTtpMatrix } from './StimulusTtpMatrix';
import '../../i18n';

const BASE = '/api/v1';

const populated: StimulusTtpMatrixData = {
  stimuli: ['URGENCY_PRESSURE', 'DIRECT_REQUEST', 'UNKNOWN'],
  ttps: [
    { code: 'SB-T001', label: 'Cold outreach', phase: 'hook' },
    { code: 'SB-T017', label: 'Payment demand', phase: 'payment-request' },
  ],
  cells: [
    { stimulus_type: 'URGENCY_PRESSURE', ttp_code: 'SB-T001', message_count: 5, conversation_count: 4 },
    { stimulus_type: 'URGENCY_PRESSURE', ttp_code: 'SB-T017', message_count: 2, conversation_count: 2 },
    { stimulus_type: 'DIRECT_REQUEST', ttp_code: 'SB-T001', message_count: 3, conversation_count: 3 },
    { stimulus_type: 'UNKNOWN', ttp_code: 'SB-T017', message_count: 9, conversation_count: 7 },
  ],
  population_messages: 42,
};

beforeAll(() => server.listen({ onUnhandledRequest: 'warn' }));
afterEach(() => server.resetHandlers());
afterAll(() => server.close());

function createWrapper() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
  };
}

function rowStimuli(): string[] {
  return screen.getAllByTestId('stimulus-ttp-matrix-row').map((r) => r.getAttribute('data-stimulus') ?? '');
}

describe('StimulusTtpMatrix', () => {
  it('renders stimulus rows, TTP columns and sparse cells', async () => {
    server.use(http.get(`${BASE}/ttps/stimulus-matrix`, () => HttpResponse.json(populated)));
    render(<StimulusTtpMatrix />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getByTestId('stimulus-ttp-matrix-table')).toBeInTheDocument();
    });

    // Stimulus chips (translated labels) and TTP column codes.
    expect(screen.getByText('Urgency pressure')).toBeInTheDocument();
    expect(screen.getByText('Direct request')).toBeInTheDocument();
    expect(screen.getByText('SB-T001')).toBeInTheDocument();

    // Cells default to message_count, row-major in server order:
    // URGENCY [5, 2], DIRECT [3, ·], UNKNOWN [·, 9].
    const cells = screen.getAllByTestId('stimulus-ttp-matrix-cell').map((c) => c.textContent);
    expect(cells).toEqual(['5', '2', '3', '·', '·', '9']);
  });

  it('states the revelation-message population under the matrix', async () => {
    server.use(http.get(`${BASE}/ttps/stimulus-matrix`, () => HttpResponse.json(populated)));
    render(<StimulusTtpMatrix />, { wrapper: createWrapper() });

    await waitFor(() => expect(screen.getByTestId('stimulus-ttp-matrix-population')).toBeInTheDocument());
    expect(screen.getByTestId('stimulus-ttp-matrix-population')).toHaveTextContent(
      'Population: 42 revelation messages carrying both an enriched stimulus context and a confirmed TTP — messages without a tagged TTP are not shown.',
    );
  });

  it('collapses and re-expands the UNKNOWN row via the toggle', async () => {
    server.use(http.get(`${BASE}/ttps/stimulus-matrix`, () => HttpResponse.json(populated)));
    render(<StimulusTtpMatrix />, { wrapper: createWrapper() });

    await waitFor(() => expect(screen.getByTestId('stimulus-ttp-matrix-table')).toBeInTheDocument());

    // Shown by default — nothing is hidden silently.
    expect(rowStimuli()).toEqual(['URGENCY_PRESSURE', 'DIRECT_REQUEST', 'UNKNOWN']);

    fireEvent.click(screen.getByTestId('stimulus-ttp-matrix-unknown-toggle'));
    await waitFor(() => {
      expect(rowStimuli()).toEqual(['URGENCY_PRESSURE', 'DIRECT_REQUEST']);
    });
    // The collapsed cells drop with the row.
    expect(screen.getAllByTestId('stimulus-ttp-matrix-cell').map((c) => c.textContent)).toEqual(['5', '2', '3', '·']);

    fireEvent.click(screen.getByTestId('stimulus-ttp-matrix-unknown-toggle'));
    await waitFor(() => {
      expect(rowStimuli()).toContain('UNKNOWN');
    });
  });

  it('shows an empty note (not an error) when there are no cells (population still stated)', async () => {
    server.use(
      http.get(`${BASE}/ttps/stimulus-matrix`, () =>
        HttpResponse.json({ stimuli: [], ttps: [], cells: [], population_messages: 0 })),
    );
    render(<StimulusTtpMatrix />, { wrapper: createWrapper() });

    await waitFor(() => expect(screen.getByTestId('stimulus-ttp-matrix-empty')).toBeInTheDocument());
    expect(screen.queryByTestId('stimulus-ttp-matrix-table')).toBeNull();
    // The honest scope sentence stays visible even when empty.
    expect(screen.getByTestId('stimulus-ttp-matrix-population')).toBeInTheDocument();
  });

  it('degrades to an empty note (not an error) on a 500', async () => {
    server.use(http.get(`${BASE}/ttps/stimulus-matrix`, () => HttpResponse.json({}, { status: 500 })));
    render(<StimulusTtpMatrix />, { wrapper: createWrapper() });

    await waitFor(() => expect(screen.getByTestId('stimulus-ttp-matrix-empty')).toBeInTheDocument());
    expect(screen.queryByTestId('stimulus-ttp-matrix-table')).toBeNull();
  });
});
