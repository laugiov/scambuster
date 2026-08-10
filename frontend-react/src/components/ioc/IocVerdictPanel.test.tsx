import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { http, HttpResponse } from 'msw';
import type { ReactNode } from 'react';
import { server } from '@/__tests__/mocks/server';
import { IocVerdictPanel } from './IocVerdictPanel';
import '../../i18n';

const BASE = '/api/v1';

beforeAll(() => server.listen());
afterEach(() => server.resetHandlers());
afterAll(() => server.close());

function renderPanel(props: Parameters<typeof IocVerdictPanel>[0]) {
  const qc = new QueryClient({ defaultOptions: { mutations: { retry: false } } });
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={qc}>{children}</QueryClientProvider>
  );
  return render(<IocVerdictPanel {...props} />, { wrapper });
}

describe('IocVerdictPanel', () => {
  // Operator-reported: the recorded note never showed up again.
  it('prefills the input with the recorded note', () => {
    renderPanel({ indicatorId: 'ind-1', verdict: 'confirmed', note: 'seen in cluster X' });
    expect(screen.getByTestId('verdict-note')).toHaveValue('seen in cluster X');
  });

  // Operator-reported: Confirm was unclickable on an already-confirmed IOC,
  // making a note update impossible. The server upserts — resubmit is legal.
  it('lets an analyst re-confirm to update the note', async () => {
    const calls: unknown[] = [];
    server.use(
      http.post(`${BASE}/iocs/:id/feedback`, async ({ request }) => {
        calls.push(await request.json());
        return HttpResponse.json({ indicator_id: 'ind-1', verdict: 'confirmed' });
      }),
    );

    renderPanel({ indicatorId: 'ind-1', verdict: 'confirmed', note: 'old note' });
    const confirmBtn = screen.getByTestId('verdict-confirm');
    expect(confirmBtn).not.toBeDisabled();

    fireEvent.change(screen.getByTestId('verdict-note'), { target: { value: 'updated note' } });
    fireEvent.click(confirmBtn);

    await waitFor(() => expect(calls).toHaveLength(1));
    expect(calls[0]).toEqual({ verdict: 'confirmed', note: 'updated note' });

    // Operator-reported: re-confirming an unchanged IOC looked like a no-op —
    // the panel must acknowledge the recorded verdict explicitly.
    await waitFor(() => expect(screen.getByTestId('verdict-saved')).toBeInTheDocument());
  });
});
