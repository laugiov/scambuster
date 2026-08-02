import { describe, it, expect, beforeAll, afterAll, afterEach, vi } from 'vitest';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { http, HttpResponse } from 'msw';
import type { ReactNode } from 'react';
import { server } from '@/__tests__/mocks/server';
import { PromptCustomization } from './PromptCustomization';
import type { PromptOverrideRow } from '@/types/api';

const BASE = '/api/v1';

function row(overrides: Partial<PromptOverrideRow> & { key: string }): PromptOverrideRow {
  return {
    description: 'desc',
    canary_validatable: false,
    canary_available: false,
    required_placeholders: [],
    has_override: false,
    enabled: false,
    valid: true,
    missing_placeholders: [],
    active: false,
    body: null,
    default_body: 'SHIPPED DEFAULT TEXT',
    updated_at: null,
    updated_by: null,
    ...overrides,
  };
}

const ROWS: PromptOverrideRow[] = [
  row({ key: 'reward_judge', description: 'Reward rubric', has_override: true, enabled: true, valid: true, active: true, body: 'CUSTOM' }),
  row({ key: 'contextual_enrichment', description: 'IOC enrichment', required_placeholders: ['{{SCAM_TYPE}}'], has_override: true, valid: false, missing_placeholders: ['{{SCAM_TYPE}}'], body: 'broken' }),
  row({ key: 'other_prompt', description: 'Another', has_override: false }),
];

function wrapper({ children }: { children: ReactNode }) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return (
    <QueryClientProvider client={qc}>
      <MemoryRouter>{children}</MemoryRouter>
    </QueryClientProvider>
  );
}

beforeAll(() => server.listen({ onUnhandledRequest: 'warn' }));
afterEach(() => server.resetHandlers());
afterAll(() => server.close());

describe('PromptCustomization', () => {
  it('lists every prompt with its status', async () => {
    server.use(http.get(`${BASE}/prompt-overrides`, () => HttpResponse.json({ success: true, data: ROWS })));

    render(<PromptCustomization />, { wrapper });

    await waitFor(() => expect(screen.getByText('reward_judge')).toBeInTheDocument());
    expect(screen.getByText('contextual_enrichment')).toBeInTheDocument();
    expect(screen.getByText('other_prompt')).toBeInTheDocument();
    // active override + rejected + default statuses are all shown
    expect(screen.getByText('Active')).toBeInTheDocument();
    expect(screen.getByText('Rejected')).toBeInTheDocument();
    expect(screen.getByText('Shipped default')).toBeInTheDocument();
  });

  it('shows the missing placeholders on a rejected override', async () => {
    server.use(http.get(`${BASE}/prompt-overrides`, () => HttpResponse.json({ success: true, data: ROWS })));

    render(<PromptCustomization />, { wrapper });

    await waitFor(() => expect(screen.getByText('contextual_enrichment')).toBeInTheDocument());
    expect(screen.getByText(/\{\{SCAM_TYPE\}\}/)).toBeInTheDocument();
  });

  it('expands a card and saves an edit via PUT', async () => {
    let putBody: unknown = null;
    server.use(
      http.get(`${BASE}/prompt-overrides`, () => HttpResponse.json({ success: true, data: ROWS })),
      http.put(`${BASE}/prompt-overrides/reward_judge`, async ({ request }) => {
        putBody = await request.json();
        return HttpResponse.json({ success: true, data: row({ key: 'reward_judge', has_override: true, enabled: true, active: true, body: 'EDITED' }) });
      }),
    );

    render(<PromptCustomization />, { wrapper });

    await waitFor(() => expect(screen.getByText('reward_judge')).toBeInTheDocument());
    fireEvent.click(screen.getByText('reward_judge'));

    const textarea = await screen.findByLabelText('Prompt body');
    fireEvent.change(textarea, { target: { value: 'EDITED' } });
    fireEvent.click(screen.getByText('Save'));

    await waitFor(() => expect(putBody).toEqual({ body: 'EDITED', enabled: true }));
  });

  it('clears the editor after a revert (no stale override text)', async () => {
    const active = row({ key: 'reward_judge', has_override: true, enabled: true, valid: true, active: true, body: 'CUSTOM', updated_at: '2026-07-01T10:00:00Z' });
    const reverted = row({ key: 'reward_judge' }); // shipped default, no override
    let listData: PromptOverrideRow[] = [active];
    server.use(
      http.get(`${BASE}/prompt-overrides`, () => HttpResponse.json({ success: true, data: listData })),
      http.delete(`${BASE}/prompt-overrides/reward_judge`, () => {
        listData = [reverted];
        return new HttpResponse(null, { status: 200 });
      }),
    );

    render(<PromptCustomization />, { wrapper });

    await waitFor(() => expect(screen.getByText('reward_judge')).toBeInTheDocument());
    fireEvent.click(screen.getByText('reward_judge'));

    const textarea = (await screen.findByLabelText('Prompt body')) as HTMLTextAreaElement;
    expect(textarea.value).toBe('CUSTOM');

    fireEvent.click(screen.getByText('Revert to default'));

    // After the refetch the editor must reflect the reverted (empty) state,
    // not the stale override text that a later save would silently re-persist.
    await waitFor(() =>
      expect((screen.getByLabelText('Prompt body') as HTMLTextAreaElement).value).toBe(''),
    );
  });

  it('surfaces the shipped default by default when there is no override, and can start from it', async () => {
    server.use(
      http.get(`${BASE}/prompt-overrides`, () =>
        HttpResponse.json({ success: true, data: [row({ key: 'reward_judge', description: 'Reward', default_body: 'DEFAULT-RUBRIC-XYZ' })] }),
      ),
    );

    render(<PromptCustomization />, { wrapper });

    await waitFor(() => expect(screen.getByText('reward_judge')).toBeInTheDocument());
    fireEvent.click(screen.getByText('reward_judge'));

    // No override → the shipped default is shown read-only immediately (no toggle needed),
    // labelled "currently in use" (visible label and accessible name match).
    const readOnly = (await screen.findByLabelText('Default prompt currently in use (read-only)')) as HTMLTextAreaElement;
    expect(readOnly.value).toBe('DEFAULT-RUBRIC-XYZ');
    expect(readOnly.readOnly).toBe(true);

    // "Start from the default" pre-fills the editable textarea with the shipped default
    fireEvent.click(screen.getByText('Start from the default'));
    expect((screen.getByLabelText('Prompt body') as HTMLTextAreaElement).value).toBe('DEFAULT-RUBRIC-XYZ');
  });

  it('hides the Enabled toggle when there is no override', async () => {
    server.use(
      http.get(`${BASE}/prompt-overrides`, () => HttpResponse.json({ success: true, data: [row({ key: 'reward_judge' })] })),
    );

    render(<PromptCustomization />, { wrapper });
    await waitFor(() => expect(screen.getByText('reward_judge')).toBeInTheDocument());
    fireEvent.click(screen.getByText('reward_judge'));

    await screen.findByLabelText('Prompt body');
    // "Shipped default" card → nothing to enable, so no "Enabled" checkbox.
    expect(screen.queryByText('Enabled')).not.toBeInTheDocument();
  });

  it('confirms before overwriting an in-progress edit with the default', async () => {
    server.use(
      http.get(`${BASE}/prompt-overrides`, () =>
        HttpResponse.json({ success: true, data: [row({ key: 'reward_judge', has_override: true, enabled: true, active: true, body: 'MY WORK', default_body: 'THE DEFAULT' })] }),
      ),
    );

    render(<PromptCustomization />, { wrapper });
    await waitFor(() => expect(screen.getByText('reward_judge')).toBeInTheDocument());
    fireEvent.click(screen.getByText('reward_judge'));

    const textarea = (await screen.findByLabelText('Prompt body')) as HTMLTextAreaElement;
    expect(textarea.value).toBe('MY WORK');

    // An existing override → the Enabled toggle IS shown (counterpart to the no-override case).
    expect(screen.getByText('Enabled')).toBeInTheDocument();

    // Declining the confirm keeps the in-progress edit intact.
    const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false);
    fireEvent.click(screen.getByText('Start from the default'));
    expect(confirmSpy).toHaveBeenCalledTimes(1);
    expect(textarea.value).toBe('MY WORK');

    // Accepting it replaces the editor with the shipped default.
    confirmSpy.mockReturnValue(true);
    fireEvent.click(screen.getByText('Start from the default'));
    expect(textarea.value).toBe('THE DEFAULT');

    confirmSpy.mockRestore();
  });

  it('shows per-prompt guidance and a placeholder glossary', async () => {
    server.use(
      http.get(`${BASE}/prompt-overrides`, () =>
        HttpResponse.json({
          success: true,
          data: [
            row({ key: 'contextual_enrichment', required_placeholders: ['{{SCAM_TYPE}}', '{{IOC_TYPES}}'] }),
            row({ key: 'reward_judge' }),
          ],
        }),
      ),
    );

    render(<PromptCustomization />, { wrapper });
    await waitFor(() => expect(screen.getByText('contextual_enrichment')).toBeInTheDocument());

    // reward_judge → when/impact guidance
    fireEvent.click(screen.getByText('reward_judge'));
    expect(screen.getByText(/trains the persona-selection bandit/)).toBeInTheDocument();

    // contextual_enrichment → placeholder glossary (token + its meaning)
    fireEvent.click(screen.getByText('contextual_enrichment'));
    expect(screen.getByText('{{SCAM_TYPE}}')).toBeInTheDocument();
    expect(screen.getByText(/Detected scam type/)).toBeInTheDocument();
  });

  it('renders the error state when the list fails', async () => {
    server.use(http.get(`${BASE}/prompt-overrides`, () => new HttpResponse(null, { status: 500 })));

    render(<PromptCustomization />, { wrapper });

    await waitFor(() => expect(screen.getByText('Failed to load prompts')).toBeInTheDocument());
  });
});
