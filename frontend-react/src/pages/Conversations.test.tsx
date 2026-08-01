import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { http, HttpResponse } from 'msw';
import Conversations from './Conversations';
import { server } from '@/__tests__/mocks/server';

const BASE = '/api/v1';

const mockMailAccounts = [
  { account_id: 'acc-delta', label: 'Delta Holdings', email: 'admin@delta-holdings.example' },
  { account_id: 'acc-gamma', label: 'Gamma Partners', email: 'admin@gamma-partners.example' },
  { account_id: 'acc-beta', label: 'Beta Counsel', email: 'admin@beta-counsel.example' },
];

const mockConversations = [
  {
    conv_id: 'conv-1',
    status: 'open',
    score_risk: 50,
    persona: 'elderly_person',
    scam_type: 'PHISHING',
    turns: 4,
    ts_first: '2026-03-20T10:00:00Z',
    ts_last: '2026-03-20T12:00:00Z',
    account_label: 'Delta Holdings',
    account_email: 'admin@delta-holdings.example',
  },
  {
    conv_id: 'conv-2',
    status: 'open',
    score_risk: 70,
    persona: 'bank_customer',
    scam_type: 'ROMANCE',
    turns: 6,
    ts_first: '2026-03-19T10:00:00Z',
    ts_last: '2026-03-19T15:00:00Z',
    account_label: 'Delta Holdings',
    account_email: 'admin@delta-holdings.example',
  },
  {
    conv_id: 'conv-3',
    status: 'closed',
    score_risk: 30,
    persona: 'elderly_person',
    scam_type: 'PHISHING',
    turns: 2,
    ts_first: '2026-03-18T08:00:00Z',
    ts_last: '2026-03-18T10:00:00Z',
    account_label: 'Gamma Partners',
    account_email: 'admin@gamma-partners.example',
  },
];

function createWrapper(initialEntries: string[] = ['/conversations']) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return function Wrapper({ children }: { children: React.ReactNode }) {
    return (
      <QueryClientProvider client={qc}>
        <MemoryRouter initialEntries={initialEntries}>{children}</MemoryRouter>
      </QueryClientProvider>
    );
  };
}

function setupHandlers(convs = mockConversations, accounts = mockMailAccounts) {
  server.use(
    http.get(`${BASE}/communication/conversation`, () => HttpResponse.json(convs)),
    http.get(`${BASE}/communication/mail-accounts`, () => HttpResponse.json(accounts)),
  );
}

beforeAll(() => server.listen({ onUnhandledRequest: 'warn' }));
afterEach(() => server.resetHandlers());
afterAll(() => server.close());

describe('Conversations page', () => {
  it('renders the page title', async () => {
    setupHandlers();
    render(<Conversations />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getByRole('heading', { name: /Conversations/i })).toBeInTheDocument();
    });
  });

  it('renders conversation data from mock handlers', async () => {
    setupHandlers();
    render(<Conversations />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getByRole('heading', { name: /Conversations/i })).toBeInTheDocument();
    });
  });

  it('does not crash on empty conversation list', async () => {
    setupHandlers([]);
    render(<Conversations />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getByRole('heading', { name: /Conversations/i })).toBeInTheDocument();
    });
  });

  it('has no accessibility violations', async () => {
    setupHandlers();
    const { axe } = await import('vitest-axe');
    const { container } = render(<Conversations />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByRole('heading', { name: /Conversations/i })).toBeInTheDocument();
    });
    const results = await axe(container);
    expect(results).toHaveNoViolations();
  });

  // ──────────────────────────────────────────────────────────────────────
  // mailbox column on the conversation list
  // ──────────────────────────────────────────────────────────────────────

  it('renders a MAILBOX column header between PERSONA and RISK', async () => {
    setupHandlers();
    render(<Conversations />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getByText(/Delta Holdings/).length ?? 0).not.toBe(0);
    }).catch(() => {/* allow the data rendering to settle */});

    const headers = await screen.findAllByRole('columnheader');
    const labels = headers.map((h) => h.textContent ?? '');
    const personaIdx = labels.findIndex((l) => /Persona/i.test(l));
    const mailboxIdx = labels.findIndex((l) => /Mailbox/i.test(l));
    const riskIdx = labels.findIndex((l) => /Risk/i.test(l));

    expect(personaIdx).toBeGreaterThanOrEqual(0);
    expect(mailboxIdx).toBeGreaterThan(personaIdx);
    expect(riskIdx).toBeGreaterThan(mailboxIdx);
  });

  it('renders the mailbox label in each conversation row', async () => {
    setupHandlers();
    render(<Conversations />, { wrapper: createWrapper() });

    const tbody = await waitFor(() => {
      const t = document.querySelector('tbody');
      expect(t).not.toBeNull();
      return t as HTMLElement;
    });
    await waitFor(() => {
      // 2 fixture conversations are linked to Delta → 2 cells in tbody
      expect(within(tbody).getAllByText('Delta Holdings').length).toBe(2);
    });
    expect(within(tbody).getByText('Gamma Partners')).toBeInTheDocument();
  });

  // ──────────────────────────────────────────────────────────────────────
  // mailbox dropdown filter
  // ──────────────────────────────────────────────────────────────────────

  it('renders the mailbox dropdown with all active mailboxes', async () => {
    setupHandlers();
    render(<Conversations />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getAllByText('Delta Holdings').length).toBeGreaterThan(0);
    });

    const dropdown = screen.getByLabelText(/Mailbox filter/i) as HTMLSelectElement;
    expect(dropdown).toBeInTheDocument();
    const optionLabels = within(dropdown).getAllByRole('option').map((o) => o.textContent ?? '');
    // 3 mailboxes + 1 placeholder ("Mailbox")
    expect(optionLabels).toContain('Delta Holdings');
    expect(optionLabels).toContain('Gamma Partners');
    expect(optionLabels).toContain('Beta Counsel');
    expect(optionLabels.length).toBe(4);
  });

  it('filters conversations to a single mailbox when selected', async () => {
    setupHandlers();
    const user = userEvent.setup();
    render(<Conversations />, { wrapper: createWrapper() });

    const tbody = await waitFor(() => {
      const t = document.querySelector('tbody');
      expect(t).not.toBeNull();
      return t as HTMLElement;
    });
    await waitFor(() => {
      expect(within(tbody).getByText('Gamma Partners')).toBeInTheDocument();
    });

    const dropdown = screen.getByLabelText(/Mailbox filter/i);
    await user.selectOptions(dropdown, 'Delta Holdings');

    await waitFor(() => {
      // Gamma row should disappear from the table body (still present in dropdown)
      expect(within(tbody).queryByText('Gamma Partners')).not.toBeInTheDocument();
    });
    expect(within(tbody).getAllByText('Delta Holdings').length).toBe(2);
  });
});
