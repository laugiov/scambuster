import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useCreatePersona } from '@/hooks/usePersonas';
import type { MetaScamType } from '@/types/api';

interface CreatePersonaFormProps {
  scamTypes: MetaScamType[];
  onClose: () => void;
  onCreated?: (code: string) => void;
}

const CODE_RE = /^[a-z_]{3,30}$/;

export function CreatePersonaForm({ scamTypes, onClose, onCreated }: CreatePersonaFormProps) {
  const { t } = useTranslation();
  const createPersona = useCreatePersona();

  const [code, setCode] = useState('');
  const [label, setLabel] = useState('');
  const [tone, setTone] = useState('');
  const [prompt, setPrompt] = useState('');
  const [selectedScamTypes, setSelectedScamTypes] = useState<string[]>([]);
  const [error, setError] = useState<string | null>(null);

  function toggleScamType(code: string) {
    setSelectedScamTypes((prev) =>
      prev.includes(code) ? prev.filter((c) => c !== code) : [...prev, code],
    );
  }

  function submit() {
    setError(null);

    if (!CODE_RE.test(code)) {
      setError(t('personas.create.errorCode', 'Code must be snake_case, 3-30 characters (a-z, _)'));
      return;
    }
    if (label.trim() === '' || label.length > 128) {
      setError(t('personas.create.errorLabel', 'Label must be 1-128 characters'));
      return;
    }
    if (tone.trim() === '' || tone.length > 256) {
      setError(t('personas.create.errorTone', 'Tone must be 1-256 characters'));
      return;
    }
    if (prompt.length < 100 || prompt.length > 5000) {
      setError(t('personas.create.errorPrompt', 'System prompt must be 100-5000 characters'));
      return;
    }

    createPersona.mutate(
      {
        persona_code: code,
        persona_label: label,
        persona_tone: tone,
        system_prompt: prompt,
        scam_type_codes: selectedScamTypes,
      },
      {
        onSuccess: () => {
          onCreated?.(code);
          onClose();
        },
        onError: (e: unknown) => {
          const msg =
            (e as { response?: { data?: { error?: string } } })?.response?.data?.error;
          setError(msg ?? t('personas.create.errorSave', 'Failed to create persona'));
        },
      },
    );
  }

  return (
    <div className="bg-surface-low rounded-lg p-6 space-y-4">
      <div className="flex items-center justify-between">
        <h2 className="text-base font-medium text-accent">
          {t('personas.create.title', 'Add a persona')}
        </h2>
        <button
          onClick={onClose}
          className="text-on-surface-dim hover:text-on-surface text-lg leading-none px-1"
          aria-label="Close"
        >
          &times;
        </button>
      </div>

      <p className="text-xs text-on-surface-dim">
        {t(
          'personas.create.hint',
          'Create a persona tuned to your organization’s activity. It starts in cold-start exploration and the bandit will try it automatically.',
        )}
      </p>

      <Field label={t('personas.create.code', 'Code (snake_case, immutable)')}>
        <input
          type="text"
          value={code}
          onChange={(e) => setCode(e.target.value)}
          placeholder="logistics_dispatcher"
          aria-label={t('personas.create.code', 'Code (snake_case, immutable)')}
          className="w-full bg-surface-base rounded px-3 py-2 text-sm text-on-surface"
        />
      </Field>
      <Field label={t('personas.create.label', 'Label')}>
        <input
          type="text"
          value={label}
          onChange={(e) => setLabel(e.target.value)}
          aria-label={t('personas.create.label', 'Label')}
          className="w-full bg-surface-base rounded px-3 py-2 text-sm text-on-surface"
        />
      </Field>
      <Field label={t('personas.create.tone', 'Tone (comma-separated)')}>
        <input
          type="text"
          value={tone}
          onChange={(e) => setTone(e.target.value)}
          placeholder="formal, structured, cautious"
          aria-label={t('personas.create.tone', 'Tone (comma-separated)')}
          className="w-full bg-surface-base rounded px-3 py-2 text-sm text-on-surface"
        />
      </Field>
      <Field label={t('personas.systemPrompt', 'System Prompt')}>
        <textarea
          value={prompt}
          onChange={(e) => setPrompt(e.target.value)}
          rows={8}
          aria-label={t('personas.systemPrompt', 'System Prompt')}
          className="w-full bg-surface-base rounded p-3 text-sm text-on-surface leading-relaxed"
        />
        <span className="text-xs text-on-surface-dim">{prompt.length} / 5000 (min 100)</span>
      </Field>

      {scamTypes.length > 0 && (
        <Field label={t('personas.create.scamTypes', 'Scam types (optional)')}>
          <div className="flex flex-wrap gap-2">
            {scamTypes.map((st) => (
              <label
                key={st.code}
                className="flex items-center gap-1.5 text-xs text-on-surface-variant cursor-pointer bg-surface-base rounded px-2 py-1"
              >
                <input
                  type="checkbox"
                  checked={selectedScamTypes.includes(st.code)}
                  onChange={() => toggleScamType(st.code)}
                />
                {st.label}
              </label>
            ))}
          </div>
        </Field>
      )}

      {error && <p className="text-xs text-error">{error}</p>}

      <div className="flex items-center gap-3">
        <button
          onClick={submit}
          disabled={createPersona.isPending}
          className="text-xs px-3 py-1.5 rounded bg-accent text-surface-base font-medium hover:opacity-90 disabled:opacity-50"
        >
          {t('personas.create.submit', 'Create persona')}
        </button>
        <button
          onClick={onClose}
          className="text-xs px-3 py-1.5 rounded bg-surface-base text-on-surface-variant hover:text-on-surface"
        >
          {t('personas.edit.cancel', 'Cancel')}
        </button>
      </div>
    </div>
  );
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div>
      <label className="text-xs font-bold text-on-surface-dim uppercase tracking-widest block mb-1">
        {label}
      </label>
      {children}
    </div>
  );
}
