import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import {
  useUpsertPromptOverride,
  useDeletePromptOverride,
  useRequestPromptCanary,
  useCanaryJob,
  useLatestCanaryJob,
} from '@/hooks/usePromptOverrides';
import type { PromptOverrideRow } from '@/types/api';

interface Props {
  row: PromptOverrideRow;
}

type Status = 'active' | 'rejected' | 'disabled' | 'default';

function statusOf(row: PromptOverrideRow): Status {
  if (!row.has_override) return 'default';
  if (!row.valid) return 'rejected';
  if (!row.enabled) return 'disabled';
  return 'active';
}

const BADGE: Record<Status, string> = {
  active: 'bg-green-500/15 text-green-400',
  rejected: 'bg-amber-500/15 text-amber-400',
  disabled: 'bg-surface-base text-on-surface-dim',
  default: 'bg-surface-base text-on-surface-dim',
};

function extractError(e: unknown): string | undefined {
  return (e as { response?: { data?: { error?: string } } })?.response?.data?.error;
}

export function PromptOverrideCard({ row }: Props) {
  const { t } = useTranslation();
  const upsert = useUpsertPromptOverride();
  const remove = useDeletePromptOverride();
  const requestCanary = useRequestPromptCanary();

  // The canary job for the candidate currently in the editor (null until "Validate" is clicked;
  // cleared whenever the body changes so a verdict never outlives the text it was run against).
  const [jobId, setJobId] = useState<number | null>(null);
  const job = useCanaryJob(jobId);
  const latestJob = useLatestCanaryJob(row.key, row.canary_validatable && row.canary_available);

  const [expanded, setExpanded] = useState(false);
  // With no override the shipped default is what actually runs, so surface it by default
  // to guide the operator; once an override exists it is collapsed (available to compare).
  const [showDefault, setShowDefault] = useState(!row.has_override);
  const [body, setBody] = useState(row.body ?? '');
  const [enabled, setEnabled] = useState(row.has_override ? row.enabled : true);
  const [error, setError] = useState<string | null>(null);

  // Re-sync the editable fields to server truth whenever the persisted row
  // changes (after a save or a revert). Otherwise a revert would leave the
  // stale text in the textarea and a following save would silently re-create
  // the override. Tracking updated_at avoids clobbering in-progress edits on
  // unrelated refetches.
  const [syncedRev, setSyncedRev] = useState(row.updated_at);
  if (row.updated_at !== syncedRev) {
    setSyncedRev(row.updated_at);
    setBody(row.body ?? '');
    setEnabled(row.has_override ? row.enabled : true);
    // Keep the default panel surfaced-when-no-override rule after a save/revert too.
    setShowDefault(!row.has_override);
    setError(null);
    setJobId(null); // a saved/reverted row means any prior verdict no longer matches the editor
  }

  // Any edit to the candidate invalidates a prior verdict — clear it so a stale result is never
  // shown against changed text.
  function editBody(next: string) {
    setBody(next);
    setJobId(null);
  }

  // Re-attach on load: the job handle is client-only state, so a refresh/navigation would otherwise
  // drop an in-progress validation or a fresh verdict. Re-adopt the latest job for this prompt once
  // its lookup resolves — using the same render-phase adjust-state idiom as the sync block above
  // (not an effect). Guarded by "the editor still shows the saved value" so a fetch that resolves
  // after the operator started editing never clobbers their text:
  //  - a RUNNING job is the validation the operator just started — resume it AND restore the exact
  //    candidate it runs against (even if never saved: the recommended flow), keeping the editor and
  //    the progress/verdict in lockstep;
  //  - a FINISHED verdict is re-shown only when it still matches the saved override, so a stale
  //    result for a since-replaced prompt is never presented as current.
  const [reattachChecked, setReattachChecked] = useState(false);
  if (!reattachChecked && latestJob.data !== undefined && jobId === null && body === (row.body ?? '')) {
    setReattachChecked(true);
    const latest = latestJob.data;
    if (latest && (latest.status === 'pending' || latest.status === 'running')) {
      setBody(latest.candidate_body);
      setJobId(latest.job_id);
    } else if (latest && latest.candidate_body === (row.body ?? '')) {
      setJobId(latest.job_id);
    }
  }

  const status = statusOf(row);
  const label: Record<Status, string> = {
    active: t('promptCustomization.status.active', 'Active'),
    rejected: t('promptCustomization.status.rejected', 'Rejected'),
    disabled: t('promptCustomization.status.disabled', 'Disabled'),
    default: t('promptCustomization.status.default', 'Shipped default'),
  };
  // Single source for the read-only default panel's name so the visible label and the
  // textarea's accessible name never diverge (WCAG 2.5.3 Label in Name).
  const defaultPanelLabel = row.has_override
    ? t('promptCustomization.defaultLabel', 'Shipped default (read-only)')
    : t('promptCustomization.defaultInUseLabel', 'Default prompt currently in use (read-only)');
  // Per-prompt "when it runs / what it affects" help (empty string when none is defined).
  const guidance = t(`promptCustomization.guidance.${row.key}`, '');

  // Canary validation state for the current editor content.
  const jobStatus = job.data?.status;
  // A job is "live" from the moment it is requested until it reaches a terminal state — this
  // spans the POST-resolved / first-GET-pending gap and any transient poll error, so the button
  // stays disabled throughout and a second click can never spawn a duplicate (billed) run.
  const jobLive = jobId !== null && jobStatus !== 'succeeded' && jobStatus !== 'failed';
  const validating = requestCanary.isPending || jobLive;
  // The status poll itself failed (500/timeout/network) while the job is still live — surfaced so
  // the panel never silently vanishes; the poll keeps retrying.
  const pollError = jobLive && job.isError;
  const verdict = jobStatus === 'succeeded' ? job.data?.verdict ?? null : null;
  const jobFailed = jobStatus === 'failed';
  // Distinct in-progress phases so the panel tells the operator exactly where the job is: queued
  // (submitted / waiting for the worker) vs actively running the real-LLM check. The verdict then
  // replaces this message in the same panel.
  const queued = validating && !pollError && jobStatus !== 'running';
  const runningNow = validating && !pollError && jobStatus === 'running';

  function save() {
    setError(null);
    upsert.mutate(
      { key: row.key, body, enabled },
      { onError: (e) => setError(extractError(e) ?? t('promptCustomization.saveError', 'Failed to save')) },
    );
  }

  function del() {
    setError(null);
    remove.mutate(row.key, {
      onError: (e) => setError(extractError(e) ?? t('promptCustomization.deleteError', 'Failed to delete')),
    });
  }

  function validate() {
    setError(null);
    requestCanary.mutate(
      { key: row.key, body },
      {
        onSuccess: (data) => setJobId(data.job_id),
        onError: (e) => setError(extractError(e) ?? t('promptCustomization.canary.requestError', 'Failed to start validation')),
      },
    );
  }

  function startFromDefault() {
    // Overwriting the editor wipes any unsaved edit, so confirm only when there is real
    // work to lose (a non-empty body that already differs from the default).
    if (
      body.trim() !== '' &&
      body !== row.default_body &&
      !window.confirm(t('promptCustomization.copyDefaultConfirm', 'Replace the editor content with the default prompt?'))
    ) {
      return;
    }

    editBody(row.default_body);
  }

  return (
    <div className="bg-surface-low rounded-lg p-4 space-y-3">
      <button
        type="button"
        onClick={() => setExpanded((v) => !v)}
        aria-expanded={expanded}
        className="w-full flex items-center justify-between text-left cursor-pointer"
      >
        <span className="block">
          <span className="block font-mono text-sm text-on-surface">{row.key}</span>
          <span className="block text-xs text-on-surface-dim">{row.description}</span>
        </span>
        <span className={`text-xs px-2 py-1 rounded ${BADGE[status]}`}>{label[status]}</span>
      </button>

      {status === 'rejected' && (
        <p className="text-xs text-amber-400">
          {t('promptCustomization.rejectedHint', 'Missing required placeholders')}: {row.missing_placeholders.join(', ')}
        </p>
      )}

      {expanded && (
        <div className="space-y-3 pt-2 border-t border-surface-base">
          {guidance && (
            <p className="text-xs text-on-surface-dim bg-surface-base/50 rounded px-3 py-2">{guidance}</p>
          )}

          {row.required_placeholders.length > 0 && (
            <div className="text-xs text-on-surface-dim space-y-1">
              <p>{t('promptCustomization.requiredTokens', 'Keep these placeholders')}:</p>
              <ul className="space-y-0.5">
                {row.required_placeholders.map((token) => {
                  const desc = t(`promptCustomization.tokenGlossary.${token.replace(/[{}]/g, '')}`, '');

                  return (
                    <li key={token}>
                      <span className="font-mono text-on-surface">{token}</span>
                      {desc && <span> — {desc}</span>}
                    </li>
                  );
                })}
              </ul>
            </div>
          )}

          <textarea
            value={body}
            onChange={(e) => editBody(e.target.value)}
            aria-label={t('promptCustomization.bodyLabel', 'Prompt body')}
            rows={8}
            className="w-full bg-surface-base rounded px-3 py-2 text-sm text-on-surface font-mono"
            placeholder={t('promptCustomization.bodyPlaceholder', 'Leave empty to use the shipped default')}
          />

          {/* "Enabled" toggles an existing override on/off — meaningless without one, so
              hide it until an override exists (a new save creates an enabled override). */}
          {row.has_override && (
            <label className="flex items-center gap-2 text-sm text-on-surface cursor-pointer">
              <input type="checkbox" checked={enabled} onChange={(e) => setEnabled(e.target.checked)} className="cursor-pointer" />
              {t('promptCustomization.enabled', 'Enabled')}
            </label>
          )}

          <div className="flex items-center gap-4 text-xs">
            <button
              type="button"
              onClick={() => setShowDefault((v) => !v)}
              aria-expanded={showDefault}
              aria-controls={`prompt-default-${row.key}`}
              className="text-on-surface-dim hover:text-on-surface underline cursor-pointer"
            >
              {showDefault
                ? t('promptCustomization.hideDefault', 'Hide the default prompt')
                : t('promptCustomization.showDefault', 'View the default prompt')}
            </button>
            <button
              type="button"
              onClick={startFromDefault}
              disabled={body === row.default_body}
              className="text-accent hover:underline disabled:opacity-40 disabled:no-underline cursor-pointer disabled:cursor-not-allowed"
            >
              {t('promptCustomization.copyDefault', 'Start from the default')}
            </button>
          </div>

          {showDefault && (
            <div id={`prompt-default-${row.key}`}>
              <p className="text-xs text-on-surface-dim mb-1">{defaultPanelLabel}</p>
              <textarea
                value={row.default_body}
                readOnly
                rows={8}
                aria-label={defaultPanelLabel}
                className="w-full bg-surface-base/60 border border-surface-base rounded px-3 py-2 text-sm text-on-surface-dim font-mono cursor-default"
              />
            </div>
          )}

          {error && <p className="text-xs text-red-400">{error}</p>}

          <div className="flex items-center gap-2">
            <button
              type="button"
              onClick={save}
              disabled={upsert.isPending || body.trim() === ''}
              className="bg-accent text-on-accent rounded px-3 py-1.5 text-sm disabled:opacity-50 cursor-pointer disabled:cursor-not-allowed"
            >
              {t('promptCustomization.save', 'Save')}
            </button>
            {row.has_override && (
              <button
                type="button"
                onClick={del}
                disabled={remove.isPending}
                className="text-on-surface-dim hover:text-red-400 text-sm px-3 py-1.5 cursor-pointer disabled:cursor-not-allowed"
              >
                {t('promptCustomization.revert', 'Revert to default')}
              </button>
            )}
            {/* Validate the CANDIDATE currently in the editor (not the saved override) — runs the
                real-LLM regression canary and shows the verdict before the operator activates it.
                Shown ONLY for prompts the canary can actually exercise (canary_validatable) AND
                only where it can actually produce a verdict (canary_available: an LLM is
                configured). Offering it elsewhere would run but never test the prompt, or hang
                forever (e.g. the demo with no LLM key) — a worse experience than hiding it. */}
            {row.canary_validatable && row.canary_available && (
              <button
                type="button"
                onClick={validate}
                disabled={validating || body.trim() === ''}
                className="text-accent hover:underline text-sm px-3 py-1.5 disabled:opacity-40 disabled:no-underline cursor-pointer disabled:cursor-not-allowed"
              >
                {validating
                  ? t('promptCustomization.canary.validating', 'Validating…')
                  : t('promptCustomization.canary.validate', 'Validate this prompt')}
              </button>
            )}
            {row.canary_validatable && !row.canary_available && (
              <span className="text-xs text-on-surface-dim">
                {t('promptCustomization.canary.unavailable', 'Validation unavailable — this deployment has no live LLM for the canary to run.')}
              </span>
            )}
            {body.trim() === '' && !upsert.isPending && (
              <span className="text-xs text-on-surface-dim">
                {t('promptCustomization.saveHint', 'Write a prompt to enable saving')}
              </span>
            )}
          </div>

          {(validating || verdict || jobFailed) && (
            <div className="rounded border border-surface-base bg-surface-base/40 px-3 py-2 text-xs space-y-1">
              {queued && (
                <p className="text-on-surface-dim">
                  <span className="inline-block animate-pulse">●</span>{' '}
                  {t('promptCustomization.canary.queued', 'Queued — waiting for the validation worker to pick this up (usually within a minute).')}
                </p>
              )}

              {runningNow && (
                <p className="text-on-surface-dim">
                  <span className="inline-block animate-pulse">●</span>{' '}
                  {t('promptCustomization.canary.running', 'Validating now — running a real-model safety check over the full scenario set. This typically takes ~20–30 minutes and continues in the background. Keep this page open: the verdict (safe, or the list of regressions) will replace this message right here when it finishes.')}
                </p>
              )}

              {pollError && (
                <p className="text-amber-400">
                  {t('promptCustomization.canary.pollError', 'Could not refresh the status just now — the validation is still running in the background and this will retry automatically.')}
                </p>
              )}

              {verdict?.ok && (
                <p className="text-green-400">
                  ✓ {t('promptCustomization.canary.ok', 'No regression — this prompt stays within tolerance of the baseline.')}
                </p>
              )}

              {verdict && !verdict.ok && (
                <div className="space-y-1">
                  <p className="text-amber-400">
                    ⚠ {t('promptCustomization.canary.regression', 'Regression detected — review before activating:')}
                  </p>
                  <ul className="space-y-0.5">
                    {verdict.regressions.map((r) => (
                      <li key={r.signal} className="text-on-surface-dim">
                        <span className="font-mono text-on-surface">{r.signal}</span>
                        {`: ${r.baseline.toFixed(3)} → ${r.candidate.toFixed(3)} — ${r.reason}`}
                      </li>
                    ))}
                  </ul>
                </div>
              )}

              {jobFailed && (
                <p className="text-red-400">
                  {t('promptCustomization.canary.failed', 'Validation could not complete')}
                  {job.data?.error ? `: ${job.data.error}` : ''}
                </p>
              )}
            </div>
          )}

          {row.updated_at && (
            <p className="text-xs text-on-surface-dim">
              {t('promptCustomization.updated', 'Updated')} {new Date(row.updated_at).toLocaleString()}
              {row.updated_by ? ` · ${row.updated_by}` : ''}
            </p>
          )}
        </div>
      )}
    </div>
  );
}
