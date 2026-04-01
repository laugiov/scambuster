import { useRef, useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { usePersonaDetail } from '@/hooks/usePersonas';
import type { PersonaSummary } from '@/types/api';

interface PersonaDetailPanelProps {
  personaCode: string;
  performance: PersonaSummary | null;
  onClose: () => void;
}

export function PersonaDetailPanel({ personaCode, performance, onClose }: PersonaDetailPanelProps) {
  const { t } = useTranslation();
  const { data: detail, isLoading } = usePersonaDetail(personaCode);
  const panelRef = useRef<HTMLDivElement>(null);

  // Auto-scroll to panel when it appears or persona changes
  // Small delay ensures the panel is rendered before scrolling
  useEffect(() => {
    const timer = setTimeout(() => {
      panelRef.current?.scrollIntoView({ behavior: 'smooth', block: 'end' });
    }, 50);
    return () => clearTimeout(timer);
  }, [personaCode]);

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
        <button
          onClick={onClose}
          className="text-on-surface-dim hover:text-on-surface text-lg leading-none px-1"
          aria-label="Close"
        >
          &times;
        </button>
      </div>

      {isLoading && (
        <p className="text-sm text-on-surface-dim">{t('common.loading', 'Loading...')}</p>
      )}

      {detail && (
        <>
          {/* Metadata */}
          <div className="grid grid-cols-3 gap-3">
            <MetaField label={t('personas.code')} value={detail.persona_code} />
            <MetaField label={t('personas.createdBy', 'Created by')} value={detail.created_by} />
            <MetaField label={t('personas.createdAt', 'Created')} value={new Date(detail.created_at).toLocaleDateString()} />
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
          <div>
            <span className="text-xs font-bold text-on-surface-dim uppercase tracking-widest block mb-1">
              {t('personas.systemPrompt', 'System Prompt')}
            </span>
            <div className="bg-surface-base rounded p-3 text-sm text-on-surface-variant leading-relaxed whitespace-pre-wrap">
              {detail.system_prompt}
            </div>
          </div>

          {/* Performance from parent */}
          {performance && performance.performance_by_scam_type.length > 0 && (
            <div>
              <span className="text-xs font-bold text-on-surface-dim uppercase tracking-widest block mb-2">
                {t('personas.performanceByScamType')}
              </span>
              <div className="space-y-1">
                {performance.performance_by_scam_type.map((st) => (
                  <div key={st.scam_type_code} className="flex items-center justify-between bg-surface-base rounded p-2">
                    <span className="text-xs text-on-surface-variant">{st.scam_type_code}</span>
                    <div className="flex items-center gap-4">
                      <span className="text-xs text-on-surface-dim">{st.sessions_count ?? st.total_pulls ?? 0} pulls</span>
                      <span className="text-xs font-mono font-bold text-accent">{(st.reward_avg ?? st.avg_reward ?? 0).toFixed(2)}</span>
                    </div>
                  </div>
                ))}
              </div>
            </div>
          )}
        </>
      )}

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
