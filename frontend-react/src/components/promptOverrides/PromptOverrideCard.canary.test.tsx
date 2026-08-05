import { describe, it, expect, beforeAll, beforeEach, afterAll, afterEach } from 'vitest';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { http, HttpResponse } from 'msw';
import type { ReactNode } from 'react';
import { server } from '@/__tests__/mocks/server';
import { PromptOverrideCard } from './PromptOverrideCard';
import type { PromptOverrideRow } from '@/types/api';

const BASE = '/api/v1';

function rewardRow(overrides: Partial<PromptOverrideRow> = {}): PromptOverrideRow {
  return {
    key: 'reward_judge',
    description: 'Reward rubric',
    canary_validatable: true,
    canary_available: true,
    required_placeholders: [],
    has_override: true,
    enabled: true,
    valid: true,
    missing_placeholders: [],
    active: true,
    body: 'CUSTOM RUBRIC',
    default_body: 'SHIPPED DEFAULT',
    updated_at: '2026-07-29T10:00:00+00:00',
    updated_by: 'alice',
    ...overrides,
  };
}

function wrapper({ children }: { children: ReactNode }) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

async function expandAndFindValidate() {
  // The card starts collapsed; expand it to reveal the editor + Validate button.
  fireEvent.click(screen.getByText('reward_judge'));

  return waitFor(() => screen.getByRole('button', { name: /Validate this prompt/i }));
}

beforeAll(() => server.listen({ onUnhandledRequest: 'warn' }));
// Default: the card fetches the latest job on mount to re-attach; no prior job unless a test
// overrides this. Keeps the existing Validate-flow tests from hitting an unmocked endpoint.
beforeEach(() =>
  server.use(
    http.get(`${BASE}/prompt-overrides/:key/canary/latest`, () =>
      HttpResponse.json({ success: true, data: null }),
    ),
  ),
);
afterEach(() => server.resetHandlers());
afterAll(() => server.close());

describe('PromptOverrideCard — canary validation', () => {
  it('hides the Validate button for a prompt the canary cannot exercise', async () => {
    render(<PromptOverrideCard row={rewardRow({ canary_validatable: false })} />, { wrapper });

    fireEvent.click(screen.getByText('reward_judge')); // expand the card
    await waitFor(() => screen.getByLabelText(/Prompt body/i)); // editor is visible

    expect(screen.queryByRole('button', { name: /Validate this prompt/i })).not.toBeInTheDocument();
  });

  it('hides Validate and explains why when the deployment has no LLM (canary unavailable)', async () => {
    // e.g. the public demo: validatable prompt, but no LLM configured → a job could only hang.
    render(<PromptOverrideCard row={rewardRow({ canary_available: false })} />, { wrapper });

    fireEvent.click(screen.getByText('reward_judge')); // expand the card
    await waitFor(() => screen.getByLabelText(/Prompt body/i));

    expect(screen.queryByRole('button', { name: /Validate this prompt/i })).not.toBeInTheDocument();
    expect(screen.getByText(/Validation unavailable/i)).toBeInTheDocument();
  });

  it('requests a validation and shows a clean verdict', async () => {
    server.use(
      http.post(`${BASE}/prompt-overrides/reward_judge/canary`, () =>
        HttpResponse.json({ success: true, data: { job_id: 7, status: 'pending' } }, { status: 202 }),
      ),
      http.get(`${BASE}/prompt-overrides/canary/7`, () =>
        HttpResponse.json({
          success: true,
          data: {
            job_id: 7,
            prompt_key: 'reward_judge',
            status: 'succeeded',
            verdict: { ok: true, fingerprint_ok: true, regressions: [] },
            error: null,
            created_at: '2026-07-29T10:00:00+00:00',
            started_at: '2026-07-29T10:01:00+00:00',
            finished_at: '2026-07-29T10:35:00+00:00',
          },
        }),
      ),
    );

    render(<PromptOverrideCard row={rewardRow()} />, { wrapper });

    fireEvent.click(await expandAndFindValidate());

    await waitFor(() => expect(screen.getByText(/No regression/i)).toBeInTheDocument());
  });

  it('surfaces the regression signals when the verdict is not ok', async () => {
    server.use(
      http.post(`${BASE}/prompt-overrides/reward_judge/canary`, () =>
        HttpResponse.json({ success: true, data: { job_id: 8, status: 'pending' } }, { status: 202 }),
      ),
      http.get(`${BASE}/prompt-overrides/canary/8`, () =>
        HttpResponse.json({
          success: true,
          data: {
            job_id: 8,
            prompt_key: 'reward_judge',
            status: 'succeeded',
            verdict: {
              ok: false,
              fingerprint_ok: true,
              regressions: [
                { signal: 'crypto_wallet', baseline: 0, candidate: 1, delta: 1, reason: 'safety invariant violated (absent from baseline)' },
              ],
            },
            error: null,
            created_at: '2026-07-29T10:00:00+00:00',
            started_at: '2026-07-29T10:01:00+00:00',
            finished_at: '2026-07-29T10:35:00+00:00',
          },
        }),
      ),
    );

    render(<PromptOverrideCard row={rewardRow()} />, { wrapper });

    fireEvent.click(await expandAndFindValidate());

    await waitFor(() => expect(screen.getByText(/Regression detected/i)).toBeInTheDocument());
    expect(screen.getByText('crypto_wallet')).toBeInTheDocument();
  });

  it('keeps the panel and blocks re-validation when the status poll errors', async () => {
    server.use(
      http.post(`${BASE}/prompt-overrides/reward_judge/canary`, () =>
        HttpResponse.json({ success: true, data: { job_id: 11, status: 'pending' } }, { status: 202 }),
      ),
      // The status poll fails (500). The panel must not silently vanish, and the button must
      // stay disabled so an impatient operator can't spawn a duplicate real-LLM run.
      http.get(`${BASE}/prompt-overrides/canary/11`, () => new HttpResponse(null, { status: 500 })),
    );

    render(<PromptOverrideCard row={rewardRow()} />, { wrapper });

    fireEvent.click(await expandAndFindValidate());

    await waitFor(() => expect(screen.getByText(/still running in the background/i)).toBeInTheDocument());
    // While the job is live the button shows "Validating…" and stays disabled (no duplicate).
    expect(screen.getByRole('button', { name: /Validating/i })).toBeDisabled();
  });

  it('shows the queued phase before the worker picks up the job', async () => {
    server.use(
      http.post(`${BASE}/prompt-overrides/reward_judge/canary`, () =>
        HttpResponse.json({ success: true, data: { job_id: 12, status: 'pending' } }, { status: 202 }),
      ),
      http.get(`${BASE}/prompt-overrides/canary/12`, () =>
        HttpResponse.json({
          success: true,
          data: { job_id: 12, prompt_key: 'reward_judge', status: 'pending', verdict: null, error: null, created_at: '2026-07-29T10:00:00+00:00', started_at: null, finished_at: null },
        }),
      ),
    );

    render(<PromptOverrideCard row={rewardRow()} />, { wrapper });
    fireEvent.click(await expandAndFindValidate());

    await waitFor(() => expect(screen.getByText(/Queued/i)).toBeInTheDocument());
  });

  it('shows the running phase (with the where-the-verdict-appears note) while the job runs', async () => {
    server.use(
      http.post(`${BASE}/prompt-overrides/reward_judge/canary`, () =>
        HttpResponse.json({ success: true, data: { job_id: 13, status: 'pending' } }, { status: 202 }),
      ),
      http.get(`${BASE}/prompt-overrides/canary/13`, () =>
        HttpResponse.json({
          success: true,
          data: { job_id: 13, prompt_key: 'reward_judge', status: 'running', verdict: null, error: null, created_at: '2026-07-29T10:00:00+00:00', started_at: '2026-07-29T10:01:00+00:00', finished_at: null },
        }),
      ),
    );

    render(<PromptOverrideCard row={rewardRow()} />, { wrapper });
    fireEvent.click(await expandAndFindValidate());

    await waitFor(() => expect(screen.getByText(/Validating now/i)).toBeInTheDocument());
    expect(screen.getByText(/verdict.*will replace this message right here/i)).toBeInTheDocument();
  });

  it('reports a failed validation job', async () => {
    server.use(
      http.post(`${BASE}/prompt-overrides/reward_judge/canary`, () =>
        HttpResponse.json({ success: true, data: { job_id: 9, status: 'pending' } }, { status: 202 }),
      ),
      http.get(`${BASE}/prompt-overrides/canary/9`, () =>
        HttpResponse.json({
          success: true,
          data: {
            job_id: 9,
            prompt_key: 'reward_judge',
            status: 'failed',
            verdict: null,
            error: 'llm timeout',
            created_at: '2026-07-29T10:00:00+00:00',
            started_at: '2026-07-29T10:01:00+00:00',
            finished_at: '2026-07-29T10:02:00+00:00',
          },
        }),
      ),
    );

    render(<PromptOverrideCard row={rewardRow()} />, { wrapper });

    fireEvent.click(await expandAndFindValidate());

    await waitFor(() => expect(screen.getByText(/Validation could not complete/i)).toBeInTheDocument());
  });

  // ─── re-attach on load (survives refresh / navigation) ───────────────

  it('re-attaches to a running validation on load, without clicking Validate', async () => {
    const running = {
      job_id: 42, prompt_key: 'reward_judge', status: 'running', verdict: null, error: null,
      created_at: '2026-07-29T10:00:00+00:00', started_at: '2026-07-29T10:01:00+00:00', finished_at: null,
    };
    server.use(
      http.get(`${BASE}/prompt-overrides/:key/canary/latest`, () =>
        HttpResponse.json({ success: true, data: { ...running, candidate_body: 'CUSTOM RUBRIC' } }),
      ),
      http.get(`${BASE}/prompt-overrides/canary/42`, () => HttpResponse.json({ success: true, data: running })),
    );

    render(<PromptOverrideCard row={rewardRow()} />, { wrapper });
    fireEvent.click(screen.getByText('reward_judge')); // expand — no Validate click

    await waitFor(() => expect(screen.getByText(/Validating now/i)).toBeInTheDocument());
  });

  it('re-attaches a running validation of an UNSAVED candidate and restores it into the editor', async () => {
    // The recommended flow: validate an unsaved candidate, then refresh mid-run. The candidate
    // (different from the saved override) is restored and the run resumes, in lockstep.
    const running = {
      job_id: 45, prompt_key: 'reward_judge', status: 'running', verdict: null, error: null,
      created_at: '2026-07-29T10:00:00+00:00', started_at: '2026-07-29T10:01:00+00:00', finished_at: null,
    };
    server.use(
      http.get(`${BASE}/prompt-overrides/:key/canary/latest`, () =>
        HttpResponse.json({ success: true, data: { ...running, candidate_body: 'UNSAVED WIP CANDIDATE' } }),
      ),
      http.get(`${BASE}/prompt-overrides/canary/45`, () => HttpResponse.json({ success: true, data: running })),
    );

    render(<PromptOverrideCard row={rewardRow()} />, { wrapper }); // saved override is 'CUSTOM RUBRIC'
    fireEvent.click(screen.getByText('reward_judge'));

    await waitFor(() => expect(screen.getByText(/Validating now/i)).toBeInTheDocument());
    const editor = screen.getByLabelText(/Prompt body/i) as HTMLTextAreaElement;
    expect(editor.value).toBe('UNSAVED WIP CANDIDATE');
  });

  it('shows a matching terminal verdict on load (survives refresh)', async () => {
    const done = {
      job_id: 43, prompt_key: 'reward_judge', status: 'succeeded',
      verdict: { ok: true, fingerprint_ok: true, regressions: [] }, error: null,
      created_at: '2026-07-29T10:00:00+00:00', started_at: '2026-07-29T10:01:00+00:00', finished_at: '2026-07-29T10:35:00+00:00',
    };
    server.use(
      http.get(`${BASE}/prompt-overrides/:key/canary/latest`, () =>
        HttpResponse.json({ success: true, data: { ...done, candidate_body: 'CUSTOM RUBRIC' } }),
      ),
      http.get(`${BASE}/prompt-overrides/canary/43`, () => HttpResponse.json({ success: true, data: done })),
    );

    render(<PromptOverrideCard row={rewardRow()} />, { wrapper });
    fireEvent.click(screen.getByText('reward_judge'));

    await waitFor(() => expect(screen.getByText(/No regression/i)).toBeInTheDocument());
  });

  it('ignores a verdict whose candidate no longer matches the saved override', async () => {
    server.use(
      http.get(`${BASE}/prompt-overrides/:key/canary/latest`, () =>
        HttpResponse.json({ success: true, data: {
          job_id: 44, prompt_key: 'reward_judge', status: 'succeeded',
          verdict: { ok: true, fingerprint_ok: true, regressions: [] }, error: null,
          candidate_body: 'A DIFFERENT, OLDER CANDIDATE',
          created_at: '2026-07-29T10:00:00+00:00', started_at: '2026-07-29T10:01:00+00:00', finished_at: '2026-07-29T10:35:00+00:00',
        } }),
      ),
    );

    render(<PromptOverrideCard row={rewardRow()} />, { wrapper });
    fireEvent.click(screen.getByText('reward_judge'));
    await waitFor(() => screen.getByLabelText(/Prompt body/i)); // expanded

    // The verdict is for a since-changed candidate → not re-adopted → no verdict panel.
    expect(screen.queryByText(/No regression/i)).not.toBeInTheDocument();
    expect(screen.queryByText(/Validating/i)).not.toBeInTheDocument();
  });
});
