import { useRef, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { usePersonaDetail, useUpdatePersona } from '@/hooks/usePersonas';
import { scamTypeLabel } from '@/lib/scamTypeLabels';
import type { PersonaSummary } from '@/types/api';

function formatPersonaDate(iso: string): string {
  const d = new Date(iso);
  if (isNaN(d.getTime())) return '--';
  return `${d.toLocaleString('en-US', { month: 'short' })} ${d.getDate()}, ${d.getFullYear()}`;
}

interface PersonaDetailPanelProps {
  personaCode: string;
  performance: PersonaSummary | null;
  onClose: () => void;
}

export function PersonaDetailPanel({ personaCode, performance, onClose }: PersonaDetailPanelProps) {
  const { t } = useTranslation();
  const { data: detail, isLoading } = usePersonaDetail(personaCode);
  const updatePersona = useUpdatePersona();
  const panelRef = useRef<HTMLDivElement>(null);

  const [editing, setEditing] = useState(false);
  const [label, setLabel] = useState('');
  const [tone, setTone] = useState('');
  const [prompt, setPrompt] = useState('');
  const [resetStats, setResetStats] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Auto-scroll to panel when it appears or persona changes
  useEffect(() => {
    const timer = setTimeout(() => {
      panelRef.current?.scrollIntoView({ behavior: 'smooth', block: 'end' });
    }, 50);
    return () => clearTimeout(timer);
  }, [personaCode]);

  // Leave edit mode when switching personas
  useEffect(() => {
    setEditing(false);
    setError(null);
  }, [personaCode]);

  const totalSessions = performance?.total_sessions ?? 0;
  // The prompt/tone drive behaviour; changing them makes the accumulated bandit
  // reward stale (it was earned by the previous version).
  const behaviourChanged =
    editing && !!detail && (prompt !== detail.system_prompt || tone !== detail.persona_tone);
  const showBiasWarning = behaviourChanged && totalSessions > 0;

  // Default the reset toggle ON when behaviour changed on a persona that has stats.
  useEffect(() => {
    setResetStats(showBiasWarning);
  }, [showBiasWarning]);

  function startEdit() {
    if (!detail) return;
    setLabel(detail.persona_label);
    setTone(detail.persona_tone);
    setPrompt(detail.system_prompt);
    setError(null);
    setEditing(true);
  }

  function save() {
    if (!detail) return;
    setError(null);

    if (label.trim() === '' || label.length > 128) {
      setError(t('personas.edit.errorLabel', 'Label must be 1-128 characters'));
      return;
    }
    if (tone.trim() === '' || tone.length > 256) {
      setError(t('personas.edit.errorTone', 'Tone must be 1-256 characters'));
      return;
    }
    if (prompt.length < 100 || prompt.length > 5000) {
      setError(t('personas.edit.errorPrompt', 'System prompt must be 100-5000 characters'));
      return;
    }

    updatePersona.mutate(
      {
        code: personaCode,
        updates: {
          persona_label: label,
          persona_tone: tone,
          system_prompt: prompt,
          reset_stats: resetStats && behaviourChanged,
        },
      },
      {
        onSuccess: () => setEditing(false),
        onError: () => setError(t('personas.edit.errorSave', 'Failed to save changes')),
      },
    );
  }

  return (
    <div ref={panelRef} className="bg-surface-low rounded-lg p-6 space-y-4">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-3">
          <h2 className="text-base font-medium text-accent">
            {detail?.persona_label ?? personaCode}
          </h2>
          {detail && (
            <span className={`text-xs px-2 py-0.5 rounded font-medium ${
              detail.is_active ? 'bg-success/20 text-success' : 'bg-error/20 text-error'
            }`}>
              {detail.is_active ? t('common.active') : t('common.inactive', 'Inactive')}
            </span>
          )}
        </div>
        <div className="flex items-center gap-3">
          {detail && !editing && (
            <button
              onClick={startEdit}
              className="text-xs px-2 py-1 rounded bg-accent/10 text-accent hover:bg-accent/20"
            >
              {t('personas.edit.edit', 'Edit')}
            </button>
          )}
          <button
            onClick={onClose}
            className="text-on-surface-dim hover:text-on-surface text-lg leading-none px-1"
            aria-label="Close"
          >
            &times;
          </button>
        </div>
      </div>

      {isLoading && (
        <p className="text-sm text-on-surface-dim">{t('common.loading', 'Loading...')}</p>
      )}

      {detail && !editing && (
        <>
          {/* Performance first (most analytical value) */}
          {performance && performance.performance_by_scam_type.length > 0 && (
            <div>
              <span className="text-xs font-bold text-on-surface-dim uppercase tracking-widest block mb-2">
                {t('personas.performanceByScamType')}
              </span>
              <div className="space-y-1">
                {performance.performance_by_scam_type.map((st) => (
                  <div key={st.scam_type_code} className={`flex items-center justify-between bg-surface-base rounded p-2 ${(st.sessions_count ?? st.total_pulls ?? 0) < 3 ? 'opacity-50' : ''}`}>
                    <span className="text-xs text-on-surface-variant">{scamTypeLabel(st.scam_type_code)}</span>
                    <div className="flex items-center gap-4">
                      <span className="text-xs text-on-surface-dim">{st.sessions_count ?? st.total_pulls ?? 0} pulls</span>
                      <span className="text-xs font-mono font-bold text-accent">{(st.reward_avg ?? st.avg_reward ?? 0).toFixed(2)}</span>
                    </div>
                  </div>
                ))}
              </div>
            </div>
          )}

          {/* Metadata */}
          <div className="grid grid-cols-3 gap-3">
            <MetaField label={t('personas.code')} value={detail.persona_code} />
            <MetaField label={t('personas.createdBy', 'Created by')} value={detail.created_by === 'fixture' ? 'System' : detail.created_by} />
            <MetaField label={t('personas.createdAt', 'Created')} value={formatPersonaDate(detail.created_at)} />
          </div>

          {/* Tone */}
          <div>
            <span className="text-xs font-bold text-on-surface-dim uppercase tracking-widest block mb-1">
              {t('personas.tone', 'Tone')}
            </span>
            <div className="flex flex-wrap gap-1">
              {detail.persona_tone.split(',').map((tag) => (
                <span key={tag.trim()} className="text-xs px-2 py-0.5 rounded bg-accent/10 text-accent">
                  {tag.trim()}
                </span>
              ))}
            </div>
          </div>

          {/* System prompt */}
          <details>
            <summary className="text-xs font-bold text-on-surface-dim uppercase tracking-widest cursor-pointer mb-1">
              {t('personas.systemPrompt', 'System Prompt')}
            </summary>
            <div className="bg-surface-base rounded p-3 text-sm text-on-surface-variant leading-relaxed whitespace-pre-wrap mt-1">
              {detail.system_prompt}
            </div>
          </details>
        </>
      )}

      {detail && editing && (
        <div className="space-y-4">
          <EditField
            label={t('personas.edit.label', 'Label')}
            value={label}
            onChange={setLabel}
          />
          <EditField
            label={t('personas.edit.tone', 'Tone (comma-separated)')}
            value={tone}
            onChange={setTone}
          />
          <div>
            <label className="text-xs font-bold text-on-surface-dim uppercase tracking-widest block mb-1">
              {t('personas.systemPrompt', 'System Prompt')}
            </label>
            <textarea
              value={prompt}
              onChange={(e) => setPrompt(e.target.value)}
              rows={8}
              aria-label={t('personas.systemPrompt', 'System Prompt')}
              className="w-full bg-surface-base rounded p-3 text-sm text-on-surface leading-relaxed"
            />
            <span className="text-xs text-on-surface-dim">{prompt.length} / 5000</span>
          </div>

          {/* Bias warning — only when behaviour changed on a persona with stats */}
          {showBiasWarning && (
            <div className="bg-error/10 border border-error/30 rounded p-3 space-y-2">
              <p className="text-xs text-error font-medium">
                {t(
                  'personas.edit.biasWarning',
                  'Changing the prompt changes how this persona behaves, but its accumulated performance ({{sessions}} sessions, avg reward {{reward}}) was earned by the previous version. Keeping those stats biases the bandit’s exploration.',
                  { sessions: totalSessions, reward: (performance?.global_avg_reward ?? 0).toFixed(2) },
                )}
              </p>
              <label className="flex items-center gap-2 text-xs text-on-surface-variant cursor-pointer">
                <input
                  type="checkbox"
                  checked={resetStats}
                  onChange={(e) => setResetStats(e.target.checked)}
                />
                {t('personas.edit.resetStats', 'Reset this persona’s performance stats (recommended)')}
              </label>
            </div>
          )}

          {error && <p className="text-xs text-error">{error}</p>}

          <div className="flex items-center gap-3">
            <button
              onClick={save}
              disabled={updatePersona.isPending}
              className="text-xs px-3 py-1.5 rounded bg-accent text-surface-base font-medium hover:opacity-90 disabled:opacity-50"
            >
              {t('personas.edit.save', 'Save')}
            </button>
            <button
              onClick={() => { setEditing(false); setError(null); }}
              className="text-xs px-3 py-1.5 rounded bg-surface-base text-on-surface-variant hover:text-on-surface"
            >
              {t('personas.edit.cancel', 'Cancel')}
            </button>
          </div>
        </div>
      )}
    </div>
  );
}

function EditField({ label, value, onChange }: { label: string; value: string; onChange: (v: string) => void }) {
  return (
    <div>
      <label className="text-xs font-bold text-on-surface-dim uppercase tracking-widest block mb-1">{label}</label>
      <input
        type="text"
        value={value}
        onChange={(e) => onChange(e.target.value)}
        aria-label={label}
        className="w-full bg-surface-base rounded px-3 py-2 text-sm text-on-surface"
      />
    </div>
  );
}

function MetaField({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <span className="text-xs font-bold text-on-surface-dim uppercase tracking-widest">{label}</span>
      <div className="bg-surface-base rounded px-3 py-2 text-sm text-on-surface mt-1 truncate">{value}</div>
    </div>
  );
}
