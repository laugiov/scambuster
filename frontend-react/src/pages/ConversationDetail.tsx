import { useMemo, useState } from 'react';
import { useParams, Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { useConversationDetail, useConversationMessages, useConversationIocs, useAllConversations, useCloseConversation, useReopenConversation } from '@/hooks/useConversations';
import { useConversationTtps } from '@/hooks/useTtps';
import type { ConversationTimelineEntry } from '@/types/ttp';
import { stimulusColor, stimulusLabel } from '@/lib/stimulusLabels';
import { TtpChip } from '@/components/ttp/TtpChip';
import { evidenceRanges, toBodyRanges, highlightSegments } from '@/lib/ttpEvidence';
import { Badge } from '@/components/ui/Badge';
import { statusToBadgeVariant } from '@/components/ui/badgeUtils';
import { Loading } from '@/components/feedback/Loading';
import { ErrorMessage } from '@/components/feedback/ErrorMessage';
import { useMetaConfig, personaDisplayName } from '@/hooks/useMetaConfig';
import { useThreatActorProfile } from '@/hooks/useThreatActor';
import { ThreatActorCard } from '@/components/conversation/ThreatActorCard';
import type { Message, Ioc } from '@/types/api';
import { scamTypeLabel, scamTypeColor } from '@/lib/scamTypeLabels';
import { isActionableIocType } from '@/lib/iocActionable';

function formatTime(iso: string): string {
  return new Date(iso).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
}

function formatDate(iso: string): string {
  const d = new Date(iso);
  if (isNaN(d.getTime())) return '--';
  const month = d.toLocaleString('en-US', { month: 'short' });
  const day = d.getDate();
  const year = d.getFullYear();
  const time = d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
  return `${month} ${day}, ${year} · ${time}`;
}

// Severity is now computed from IOC type + enrichment scores (shared utility)
import { iocSeverity } from '@/lib/iocSeverity';

// Flash ring on the message bubble a causality chip points at. Pure DOM: a
// class toggle + timeout instead of React state, so the transient highlight
// never re-renders the thread (and never trips the set-state-in-effect gate).
// The pending timer is tracked per element so a rapid re-click restarts the
// flash window instead of letting the first timer strip the ring mid-flash.
const FLASH_RING_CLASSES = ['ring-2', 'ring-accent'];
const FLASH_RING_MS = 1600;
const flashTimers = new WeakMap<Element, number>();
function flashMessageBubble(msgId: string): void {
  const el = document.getElementById(`msg-${msgId}`);
  if (!el) return;
  el.scrollIntoView({ behavior: 'smooth', block: 'center' });
  const pending = flashTimers.get(el);
  if (pending !== undefined) window.clearTimeout(pending);
  el.classList.add(...FLASH_RING_CLASSES);
  flashTimers.set(el, window.setTimeout(() => {
    el.classList.remove(...FLASH_RING_CLASSES);
    flashTimers.delete(el);
  }, FLASH_RING_MS));
}

export function ConversationDetail() {
  const { t } = useTranslation();
  const { id } = useParams<{ id: string }>();
  const { data: config } = useMetaConfig();
  const conv = useConversationDetail(id ?? '');
  const { data: allConversations } = useAllConversations();
  // Detail API doesn't return scam_type/persona — get from list cache
  const listConv = allConversations?.find((c) => c.conv_id === id);
  const messages = useConversationMessages(id ?? '');
  const iocs = useConversationIocs(id ?? '');
  const threatActor = useThreatActorProfile(id ?? '');
  const ttps = useConversationTtps(id ?? '');
  const [selectedIoc, setSelectedIoc] = useState<Ioc | null>(null);

  // Index the elicitation timeline by message id so each MessageBubble can
  // render its own TTP badges / revealed IOCs / stimulus, and resolve TTP codes
  // to their human labels (timeline rows carry codes only).
  const timelineByMsg = useMemo(() => {
    const map = new Map<string, ConversationTimelineEntry>();
    for (const entry of ttps.data?.timeline ?? []) {
      map.set(entry.msg_id, entry);
    }
    return map;
  }, [ttps.data]);

  const ttpLabelByCode = useMemo(() => {
    const map: Record<string, string> = {};
    for (const obs of ttps.data?.observations ?? []) {
      map[obs.ttp_code] = obs.ttp_label;
    }
    return map;
  }, [ttps.data]);

  // Outbound messages referenced as a stimulus by any revealed IOC: those
  // bubbles get a neutral "revelations followed" marker. Linkage comes from
  // stimulus_msg_id data only — never positional inference.
  const stimulusRefIds = useMemo(() => {
    const set = new Set<string>();
    for (const entry of ttps.data?.timeline ?? []) {
      for (const ioc of entry.iocs_revealed) {
        if (ioc.stimulus_msg_id !== null) set.add(ioc.stimulus_msg_id);
      }
    }
    return set;
  }, [ttps.data]);

  const hasReviewTtps = (ttps.data?.observations ?? []).some((obs) => obs.status === 'review');

  if (conv.isLoading) return <Loading message={t('conversationDetail.loadingConversation')} />;
  if (conv.error) return <ErrorMessage message={t('conversationDetail.failedLoad')} onRetry={() => void conv.refetch()} />;
  if (!conv.data) return <ErrorMessage message={t('conversationDetail.notFound')} />;

  const c = conv.data;

  // Actionable IOCs are now filtered server-side by the
  // /iocs endpoint (ListConversationIocsController passes
  // actionableOnly=true). The frontend just counts what it received,
  // no local exclusion needed. The defensive client-side filter below
  // is a no-op on current server responses but kept as defence-in-depth
  // in case a future API consumer (e.g. mobile app) calls a non-actionable
  // surface and shares this component.
  const filteredIocCount = (iocs.data ?? []).filter(
    (ioc) => isActionableIocType(ioc.type),
  ).length;

  return (
    <div className="flex flex-col -m-8 h-[calc(100vh-0px)]">
      {/* Top header bar */}
      <header className="bg-surface-low px-6 py-3 flex items-center justify-between shrink-0">
        <div className="flex items-center gap-4">
          <Link to="/conversations" className="text-accent hover:text-accent-hover transition-colors" aria-label={t('conversationDetail.backToConversations')}>
            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2} aria-hidden="true">
              <path strokeLinecap="round" strokeLinejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
            </svg>
          </Link>
          <h2 className="text-accent text-lg font-medium">
            Conversation #{c.conv_id.slice(0, 8)}
          </h2>
        </div>
        <div className="flex items-center gap-2">
          {c.persona && (
            <span className="px-3 py-1 bg-accent-muted/20 text-accent text-xs uppercase tracking-wider font-bold rounded-lg">
              Persona: {personaDisplayName(config, c.persona)}
            </span>
          )}
          {c.scam_type && (
            <span className="px-3 py-1 bg-warning/20 text-warning text-xs uppercase tracking-wider font-bold rounded-lg">
              {c.scam_type}
            </span>
          )}
          <Badge label={c.status} variant={statusToBadgeVariant(c.status)} />
          {/* Live Bait Theater button.
              Promoted to a prominent solid CTA (was a
              discreet ghost chip) because the user
              reported it was being missed. The Theater is a flagship
              feature; the entry point should look like it. */}
          {(messages.data?.length ?? 0) > 0 && (
            <Link
              to={`/conversations/${id}/theater`}
              title={t('conversationDetail.replayExtraction')}
              className="ml-2 inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold bg-accent-muted text-on-surface rounded-md shadow-md shadow-accent-muted/40 hover:bg-accent hover:text-surface-base hover:shadow-lg hover:shadow-accent/40 hover:-translate-y-px active:translate-y-0 transition-all"
              data-testid="theater-link"
            >
              <svg className="w-4 h-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <path d="M8 5v14l11-7z" />
              </svg>
              {t('conversationDetail.replayExtraction')}
            </Link>
          )}
          <ConversationLifecycleControl conversationId={c.conv_id} status={c.status} />
        </div>
      </header>

      {/* 3-column grid — each column scrolls independently */}
      <div className="grid grid-cols-12 gap-6 p-6 flex-1 min-h-0">
        {/* Left: metadata + IOCs */}
        <div className="col-span-3 flex flex-col gap-6 overflow-y-auto pr-1">
          <SessionMetadata conv={{ ...c, scam_type: c.scam_type ?? listConv?.scam_type, persona: c.persona ?? listConv?.persona }} messageCount={messages.data?.length ?? 0} iocCount={filteredIocCount} config={config} />
          <ExtractedIocs convId={id ?? ''} iocs={iocs.data ?? []} isLoading={iocs.isLoading} selectedId={selectedIoc?.obs_id ?? null} onSelect={setSelectedIoc} />
        </div>

        {/* Center: email thread */}
        <div className="col-span-6 flex flex-col bg-surface-low rounded-lg overflow-hidden">
          <div className="px-6 py-4 bg-surface-high flex items-center gap-2">
            <svg className="w-4 h-4 text-accent" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
              <path strokeLinecap="round" strokeLinejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" />
            </svg>
            <span className="text-sm font-semibold text-on-surface">{t('conversationDetail.emailThread')}</span>
            <span className="text-xs text-on-surface-dim ml-1">{t('conversationDetail.viaImap')}</span>
            {hasReviewTtps && (
              <span
                data-testid="ttp-review-legend"
                title={t('ttp.timeline.reviewTooltip')}
                className="ml-auto inline-flex items-center gap-1.5 text-[0.625rem] text-on-surface-dim"
              >
                <span aria-hidden="true" className="inline-block h-2.5 w-2.5 rounded border border-dashed border-current opacity-70" />
                {t('ttp.timeline.reviewLegend')}
              </span>
            )}
          </div>

          <div className="flex-1 overflow-y-auto p-6 space-y-4">
            {messages.isLoading ? (
              <Loading message={t('conversationDetail.loadingMessages')} />
            ) : (
              (messages.data ?? []).map((msg) => (
                <MessageBubble
                  key={msg.message_id}
                  message={msg}
                  timeline={timelineByMsg.get(msg.message_id) ?? null}
                  ttpLabelByCode={ttpLabelByCode}
                  stimulusReferenced={stimulusRefIds.has(msg.message_id)}
                />
              ))
            )}
            {!messages.isLoading && (messages.data ?? []).length === 0 && (
              <p className="text-center text-on-surface-dim text-sm py-8">{t('conversationDetail.noMessages')}</p>
            )}
          </div>

          <div className="p-4 bg-surface-low">
            <div className="flex items-center bg-surface-base rounded-lg px-4 py-3 opacity-50">
              <span className="text-sm text-on-surface-variant italic flex-1">
                {t('conversationDetail.automatedAgent')}
              </span>
              <svg className="w-4 h-4 text-on-surface-dim" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
              </svg>
            </div>
          </div>
        </div>

        {/* Right: Intelligence panel */}
        <div className="col-span-3 overflow-y-auto pl-1">
          <div className="flex flex-col gap-6">
            {selectedIoc ? (
              <IocDetailPanel
                ioc={selectedIoc}
                onClose={() => setSelectedIoc(null)}
                threatActorSummary={threatActor.data ? `${threatActor.data.sophistication} · ${c.scam_type ? scamTypeLabel(c.scam_type) : ''}` : undefined}
              />
            ) : (
              threatActor.data && <ThreatActorCard profile={threatActor.data} personaLabel={personaDisplayName(config, c.persona ?? listConv?.persona ?? '')} />
            )}
          </div>
        </div>
      </div>
    </div>
  );
}

/**
 * Header control to manually close an open conversation or reopen a closed/abandoned
 * one. Both actions require an explicit confirmation carrying a warning about the
 * effect on the persona/bandit statistics. Renders nothing for a `mistake` status.
 */
function ConversationLifecycleControl({ conversationId, status }: { conversationId: string; status: string }) {
  const { t } = useTranslation();
  const [showConfirm, setShowConfirm] = useState(false);
  const closeConv = useCloseConversation();
  const reopenConv = useReopenConversation();

  const mode: 'close' | 'reopen' | null =
    status === 'open' ? 'close' : status === 'closed' || status === 'abandoned' ? 'reopen' : null;

  if (mode === null) return null;

  const mutation = mode === 'close' ? closeConv : reopenConv;
  const buttonLabel = mode === 'close' ? t('conversationDetail.close') : t('conversationDetail.reopen');
  const warning = mode === 'close'
    ? t('conversationDetail.closeWarning')
    : t('conversationDetail.reopenWarning');
  const buttonClass = mode === 'close'
    ? 'bg-error/20 text-error hover:bg-error/30'
    : 'bg-accent/20 text-accent hover:bg-accent/30';

  return (
    <div className="relative">
      <button
        onClick={() => { mutation.reset(); setShowConfirm((v) => !v); }}
        className={`ml-2 px-4 py-2 text-sm font-semibold rounded-md transition-colors ${buttonClass}`}
        data-testid="lifecycle-button"
      >
        {buttonLabel}
      </button>
      {showConfirm && (
        <div
          role="alertdialog"
          aria-label={buttonLabel}
          className="absolute right-0 mt-2 w-80 z-20 bg-surface-low border border-warning/30 rounded-md p-4 shadow-lg space-y-3"
        >
          <p className="text-xs text-warning leading-relaxed">{warning}</p>
          {mutation.isError && (
            <p className="text-xs text-error">
              {(mutation.error as { response?: { data?: { error?: string } } })?.response?.data?.error
                ?? t('conversationDetail.lifecycleError')}
            </p>
          )}
          <div className="flex gap-2">
            <button
              onClick={() => mutation.mutate(conversationId, { onSuccess: () => setShowConfirm(false) })}
              disabled={mutation.isPending}
              className="px-3 py-1.5 rounded text-xs font-medium bg-accent text-surface-base disabled:opacity-50"
            >
              {t('conversationDetail.confirmYes')}
            </button>
            <button
              onClick={() => setShowConfirm(false)}
              className="px-3 py-1.5 rounded text-xs font-medium bg-surface-high text-on-surface"
            >
              {t('conversationDetail.confirmNo')}
            </button>
          </div>
        </div>
      )}
    </div>
  );
}

function SessionMetadata({ conv, messageCount, iocCount, config }: {
  conv: { conv_id: string; score_risk: number; ts_first?: string; ts_last?: string; created_at?: string; persona?: string | null; scam_type?: string | null; secondary_scam_types?: { code: string; confidence: number }[] | null; account_label?: string | null; account_email?: string | null };
  messageCount: number;
  iocCount: number;
  config?: import('@/types/api').MetaConfig;
}) {
  const { t } = useTranslation();
  const startDate = conv.ts_first ?? conv.created_at ?? '';
  const endDate = conv.ts_last ?? '';
  let duration = '--';
  if (startDate && endDate) {
    const mins = Math.floor((new Date(endDate).getTime() - new Date(startDate).getTime()) / 60000);
    if (mins < 60) duration = `${mins}min`;
    else duration = `${Math.floor(mins / 60)}h ${mins % 60}min`;
  }

  return (
    <section className="bg-surface-low rounded-lg p-5">
      <h3 className="text-xs uppercase tracking-widest text-on-surface-dim font-medium mb-4">{t('conversationDetail.sessionMetadata')}</h3>
      <div className="space-y-3">
        <MailboxRow label={conv.account_label ?? null} email={conv.account_email ?? null} t={t} />
        {conv.scam_type && (
          <div className="flex flex-col">
            <span className="text-[0.625rem] text-accent-muted uppercase font-bold tracking-tight">{t('conversations.scamType')}</span>
            <div className="flex flex-wrap items-center gap-1.5 mt-0.5">
              <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${scamTypeColor(conv.scam_type)}`}>
                {scamTypeLabel(conv.scam_type)}
              </span>
              {conv.secondary_scam_types?.map((st) => (
                <span key={st.code} className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium opacity-70 ${scamTypeColor(st.code)}`} title={`${Math.round(st.confidence * 100)}% confidence`}>
                  {scamTypeLabel(st.code)}
                </span>
              ))}
            </div>
          </div>
        )}
        {conv.persona && (
          <MetaRow label={t('conversations.persona')} value={personaDisplayName(config, conv.persona)} />
        )}
        <MetaRow label={t('conversationDetail.started')} value={startDate ? formatDate(startDate) : '--'} />
        <div className="grid grid-cols-2 gap-3">
          <MetaRow label={t('conversationDetail.duration')} value={duration} />
          <MetaRow
            label={t('conversationDetail.totalMessages')}
            value={t('conversationDetail.messagesValue', { count: messageCount, turns: Math.ceil(messageCount / 2) })}
          />
        </div>
        <div className="grid grid-cols-2 gap-3">
          <MetaRow
            label={t('conversationDetail.iocActionable')}
            value={String(iocCount)}
            tooltip={t('conversationDetail.iocActionableTooltip')}
          />
          <MetaRow label={t('conversationDetail.riskScore')} value={String(conv.score_risk)} highlight />
        </div>
      </div>
    </section>
  );
}

function MetaRow({ label, value, highlight, tooltip }: { label: string; value: string; highlight?: boolean; tooltip?: string }) {
  return (
    <div className="flex flex-col" title={tooltip}>
      <span className="text-[0.625rem] text-accent-muted uppercase font-bold tracking-tight flex items-center gap-1">
        {label}
        {tooltip && <span className="text-accent-muted opacity-70 cursor-help" aria-label={tooltip}>ⓘ</span>}
      </span>
      <span className={`text-sm font-medium ${highlight ? 'text-accent' : 'text-on-surface'}`}>{value}</span>
    </div>
  );
}

function MailboxRow({ label, email, t }: { label: string | null; email: string | null; t: (k: string) => string }) {
  const showLabel = label ?? '--';
  return (
    <div className="flex flex-col">
      <span className="text-[0.625rem] text-accent-muted uppercase font-bold tracking-tight">{t('conversations.mailbox')}</span>
      <span className="text-sm font-medium text-on-surface">{showLabel}</span>
      {email && <span className="text-xs text-on-surface-dim mt-0.5">{email}</span>}
    </div>
  );
}

function ExtractedIocs({ convId, iocs, isLoading, selectedId, onSelect }: {
  convId: string;
  iocs: Ioc[];
  isLoading: boolean;
  selectedId: string | null;
  onSelect: (ioc: Ioc | null) => void;
}) {
  const { t } = useTranslation();

  const handleExportStix = async () => {
    const { default: client } = await import('@/api/client');
    const { ENDPOINTS } = await import('@/api/endpoints');
    const { data } = await client.get(ENDPOINTS.conversations.exportStix(convId));
    const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `scambuster-stix-${convId.slice(0, 8)}.json`;
    a.click();
    URL.revokeObjectURL(url);
  };

  if (isLoading) return <Loading message={t('conversationDetail.loadingIocs')} />;

  const INFRA_TYPES = new Set(['dmarc_result', 'spf_result', 'dkim_result']);
  const INFRA_DOMAINS = ['@scambuster.local'];
  const isInfraIoc = (ioc: Ioc) =>
    INFRA_TYPES.has(ioc.type.toLowerCase())
    || INFRA_DOMAINS.some((d) => ioc.value.toLowerCase().includes(d));

  const realIocs = iocs.filter((ioc) => !isInfraIoc(ioc));
  const emailAuthIocs = iocs.filter((ioc) => isInfraIoc(ioc));

  return (
    <section className="bg-surface-low rounded-lg p-5 flex-1">
      <div className="flex items-center justify-between mb-4">
        <h3 className="text-xs uppercase tracking-widest text-on-surface-dim font-medium">
          {t('conversationDetail.extractedIocs')} <span className="text-on-surface-variant">({realIocs.length})</span>
        </h3>
        {iocs.length > 0 && (
          <button
            type="button"
            onClick={() => void handleExportStix()}
            className="relative z-10 text-xs text-accent hover:text-accent-hover transition-colors flex items-center gap-1 px-2 py-1 rounded bg-surface-base hover:bg-surface-high cursor-pointer"
          >
            <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
              <path strokeLinecap="round" strokeLinejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
            </svg>
            STIX 2.1
          </button>
        )}
      </div>
      <div className="space-y-2">
        {realIocs.map((ioc) => {
          const sev = iocSeverity(ioc.type, ioc.score?.vt ?? 0, ioc.score?.urlscan ?? 0);
          const isSelected = ioc.obs_id === selectedId;
          return (
            <button
              key={ioc.obs_id}
              onClick={() => onSelect(isSelected ? null : ioc)}
              className={`w-full flex items-center justify-between p-2 rounded border-l-2 text-left cursor-pointer transition-colors ${sev.border} ${
                isSelected ? 'bg-accent-muted/10 ring-1 ring-accent/30' : 'bg-surface-base hover:bg-surface-high/50'
              }`}
            >
              <div className="flex flex-col min-w-0 mr-2 overflow-hidden">
                <span className="text-xs font-mono truncate text-on-surface-variant">{ioc.value}</span>
                <span className="text-[0.5rem] text-on-surface-dim uppercase">{ioc.type}</span>
              </div>
              <span className={`text-[0.5rem] px-1.5 py-0.5 font-bold rounded shrink-0 ${sev.color}`}>
                {sev.label}
              </span>
            </button>
          );
        })}
        {realIocs.length === 0 && (
          <p className="text-xs text-on-surface-dim text-center py-4">{t('conversationDetail.noIocs')}</p>
        )}
      </div>
      {emailAuthIocs.length > 0 && (
        <details className="mt-4">
          <summary className="text-[0.625rem] text-on-surface-dim uppercase tracking-widest cursor-pointer hover:text-on-surface-variant">
            Email Authentication ({emailAuthIocs.length})
          </summary>
          <div className="mt-2 space-y-1">
            {emailAuthIocs.map((ioc) => (
              <div key={ioc.obs_id} className="flex items-center justify-between p-1.5 bg-surface-base rounded text-xs">
                <span className="font-mono text-on-surface-dim truncate">{ioc.value}</span>
                <span className="text-[0.5rem] text-on-surface-dim uppercase ml-2 shrink-0">{ioc.type}</span>
              </div>
            ))}
          </div>
        </details>
      )}
    </section>
  );
}

function MessageBubble({ message, timeline, ttpLabelByCode, stimulusReferenced = false }: {
  message: Message;
  timeline?: ConversationTimelineEntry | null;
  ttpLabelByCode?: Record<string, string>;
  /** True when another message's revealed IOCs carry this msg_id as stimulus. */
  stimulusReferenced?: boolean;
}) {
  const { t } = useTranslation();
  const [expanded, setExpanded] = useState(false);
  const isOutbound = message.direction === 'out';
  const isTruncated = message.body_text.length > 500;

  const ttpBadges = timeline?.ttps ?? [];
  // The server already restricts iocs_revealed to actionable types; consume
  // the list as-is (the IOC panel keeps its own defence-in-depth filter).
  const iocsRevealed = timeline?.iocs_revealed ?? [];
  const stimulusType = timeline?.stimulus_type ?? null;

  // Distinct outbound messages this inbound's revelations were preceded by.
  // A null stimulus_msg_id (first contact / unenriched) is the default state:
  // no chip, no fake linkage.
  const precededByIds = !isOutbound
    ? Array.from(new Set(
        iocsRevealed
          .map((ioc) => ioc.stimulus_msg_id)
          .filter((v): v is string => v !== null),
      ))
    : [];

  // Evidence offsets index into `subject + "\n\n" + body`; translate to
  // body-relative code-point ranges. Null offsets (LLM paraphrased) yield no
  // range, so the badge shows without an in-body highlight.
  const bodyRanges = toBodyRanges(evidenceRanges(ttpBadges), message.subject, message.body_text);
  const hasHighlight = bodyRanges.length > 0;

  // When we highlight, render the full body so the offsets stay aligned; only
  // truncate the non-highlighted case (existing behaviour).
  const showTruncated = isTruncated && !expanded && !hasHighlight;
  const bodyPreview = showTruncated ? message.body_text.slice(0, 500) + '...' : message.body_text;

  const hasAnnotations = ttpBadges.length > 0 || iocsRevealed.length > 0
    || (isOutbound && (!!stimulusType || stimulusReferenced));

  return (
    <div className={`flex ${isOutbound ? 'justify-end' : 'justify-start'}`}>
      <div id={`msg-${message.message_id}`} className={`max-w-[80%] p-4 rounded-xl ${
        isOutbound
          ? 'bg-teal-900/20 rounded-tr-none border border-teal-700/20'
          : 'bg-surface-highest rounded-tl-none border border-surface-highest'
      }`}>
        <span className={`text-[0.5rem] uppercase tracking-widest font-bold mb-1 block ${
          isOutbound ? 'text-teal-400' : 'text-red-400/70'
        }`}>
          {isOutbound ? `Sentinel` : `Scammer`}
        </span>
        {message.subject && (
          <p className="text-xs text-on-surface-dim font-medium mb-1">{message.subject}</p>
        )}
        <p className="text-sm leading-relaxed text-on-surface whitespace-pre-line">
          {hasHighlight
            ? highlightSegments(message.body_text, bodyRanges).map((seg, i) =>
                seg.highlighted ? (
                  <mark
                    key={`seg-${i}`}
                    data-testid="ttp-evidence"
                    className="rounded bg-accent/25 px-0.5 text-on-surface"
                  >
                    {seg.text}
                  </mark>
                ) : (
                  <span key={`seg-${i}`}>{seg.text}</span>
                ),
              )
            : bodyPreview}
        </p>
        {isTruncated && !hasHighlight && (
          <button
            type="button"
            onClick={() => setExpanded(!expanded)}
            className="text-xs text-accent hover:text-accent-hover mt-1 cursor-pointer"
          >
            {expanded ? 'Show less' : 'Show full message'}
          </button>
        )}

        {hasAnnotations && (
          <div className="mt-2 space-y-1.5 border-t border-border/40 pt-2" data-testid="ttp-annotations">
            {ttpBadges.length > 0 && (
              <div className="flex flex-wrap gap-1">
                {ttpBadges.map((ttp) => (
                  <TtpChip
                    key={ttp.ttp_code}
                    code={ttp.ttp_code}
                    label={ttpLabelByCode?.[ttp.ttp_code] ?? ''}
                    phase={ttp.phase}
                    confidence={ttp.confidence}
                    status={ttp.status}
                    testId="ttp-badge"
                  />
                ))}
              </div>
            )}
            {isOutbound && stimulusType && (
              <div className="flex flex-wrap items-center gap-1.5 text-[0.625rem]">
                <span className="uppercase tracking-wide text-on-surface-dim">{t('ttp.timeline.stimulus')}</span>
                <span
                  data-testid="stimulus-chip"
                  className={`inline-flex items-center rounded px-1.5 py-0.5 font-medium ${stimulusColor(stimulusType)}`}
                >
                  {stimulusLabel(stimulusType, t)}
                </span>
              </div>
            )}
            {isOutbound && stimulusReferenced && (
              <div className="flex flex-wrap items-center gap-1">
                <span
                  data-testid="revelations-followed-chip"
                  className="inline-flex items-center gap-1 rounded bg-surface-high px-1.5 py-0.5 text-[0.625rem] text-on-surface-dim"
                >
                  <span aria-hidden="true">↓</span>
                  {t('ttp.timeline.revelationsFollowed')}
                </span>
              </div>
            )}
            {iocsRevealed.length > 0 && (
              <div className="flex flex-wrap items-center gap-1">
                <span className="text-[0.625rem] uppercase tracking-wide text-on-surface-dim">
                  {t('ttp.timeline.iocsRevealed')}:
                </span>
                {iocsRevealed.map((ioc, idx) => (
                  <span
                    key={`${ioc.type}-${ioc.value_norm}-${idx}`}
                    data-testid="ttp-ioc"
                    className="inline-flex items-center gap-1 rounded bg-surface-high px-1.5 py-0.5 font-mono text-[0.625rem] text-on-surface-variant"
                  >
                    <span className="uppercase opacity-70">{ioc.type}</span>
                    {ioc.value_norm}
                  </span>
                ))}
              </div>
            )}
            {precededByIds.length > 0 && (
              <div className="flex flex-wrap items-center gap-1">
                {precededByIds.map((refId) => (
                  <button
                    key={refId}
                    type="button"
                    data-testid="preceded-by-chip"
                    onClick={() => flashMessageBubble(refId)}
                    className="inline-flex items-center gap-1 rounded bg-surface-high px-1.5 py-0.5 text-[0.625rem] text-on-surface-variant hover:bg-surface-base hover:text-on-surface transition-colors cursor-pointer"
                  >
                    <span aria-hidden="true">↑</span>
                    {t('ttp.timeline.precededBy')}
                  </button>
                ))}
              </div>
            )}
          </div>
        )}

        <span className={`text-[0.625rem] mt-2 block opacity-60 ${isOutbound ? 'text-right' : ''}`}>
          {message.ts_msg ? formatTime(message.ts_msg) : '--:--'}
        </span>
      </div>
    </div>
  );
}

function IocDetailPanel({ ioc, onClose, threatActorSummary }: { ioc: Ioc; onClose: () => void; threatActorSummary?: string }) {
  const { t } = useTranslation();
  const [copied, setCopied] = useState(false);
  const sev = iocSeverity(ioc.type, ioc.score?.vt ?? 0, ioc.score?.urlscan ?? 0);

  const stixPattern = `[${ioc.type}:value = '${ioc.value_norm.replace(/'/g, "\\'")}']`;

  const handleCopy = async () => {
    await navigator.clipboard.writeText(stixPattern);
    setCopied(true);
    setTimeout(() => setCopied(false), 2000);
  };

  return (
    <section className="bg-surface-low rounded-lg p-5 flex flex-col gap-4">
      <div className="flex items-center justify-between">
        <button
          onClick={onClose}
          className="text-xs text-accent hover:text-accent-hover transition-colors cursor-pointer flex items-center gap-1"
        >
          <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
          </svg>
          Back to Intelligence
        </button>
        <h3 className="text-sm font-bold text-on-surface">{t('conversationDetail.iocDetail')}</h3>
      </div>

      <div className="p-3 bg-surface-base rounded-lg">
        <span className="text-[0.625rem] font-bold text-accent-muted uppercase tracking-widest block mb-1">{t('conversationDetail.iocValue')}</span>
        <p className="font-mono text-sm font-bold break-all text-on-surface">{ioc.value}</p>
        <div className="mt-2 flex items-center gap-2">
          <span className={`text-xs px-2 py-0.5 rounded font-medium ${sev.color} bg-surface-high`}>{sev.label}</span>
          <span className="text-xs px-2 py-0.5 bg-surface-high text-on-surface-variant rounded">{ioc.type.toUpperCase()}</span>
        </div>
      </div>

      <div className="grid grid-cols-2 gap-3">
        <IocField label={t('iocDetail.scamType')} value={scamTypeLabel(ioc.category)} />
        <IocField label={t('conversationDetail.firstSeen')} value={formatDate(ioc.ts_observed)} />
        <IocField label={t('conversationDetail.vtScore')} value={String(ioc.score?.vt ?? 0)} />
        <IocField label={t('conversationDetail.urlScan')} value={String(ioc.score?.urlscan ?? 0)} />
        <IocField label={t('iocExplorer.confidence')} value={(ioc.confidence ?? 0).toFixed(3)} />
        <IocField label={t('iocExplorer.effectiveScore')} value={(ioc.effective_score ?? 0).toFixed(3)} />
      </div>

      <div>
        <span className="text-[0.625rem] font-bold text-on-surface-dim uppercase tracking-widest block mb-1">{t('conversationDetail.analysis')}</span>
        <p className="text-xs text-on-surface-variant bg-surface-base rounded p-2">
          {ioc.score?.explain ?? t('conversationDetail.noAnalysis')}
        </p>
      </div>

      <div className="flex-1">
        <div className="flex items-center justify-between mb-1">
          <span className="text-[0.625rem] font-bold text-on-surface-dim uppercase tracking-widest">{t('conversationDetail.stixPattern')}</span>
          <button
            type="button"
            onClick={() => void handleCopy()}
            className="text-[0.625rem] text-accent hover:text-accent-hover transition-colors cursor-pointer flex items-center gap-1"
          >
            {copied ? '✓ Copied' : '📋 Copy'}
          </button>
        </div>
        <pre className="p-2 bg-surface-base rounded font-mono text-[0.625rem] text-accent/70 overflow-x-auto whitespace-pre-wrap break-all">
{stixPattern}
        </pre>
      </div>

      <Link
        to={`/ioc-explorer/${ioc.ioc_id}`}
        className="flex items-center justify-center gap-1.5 text-xs text-accent hover:text-accent-hover transition-colors py-2 bg-surface-base rounded-lg"
      >
        <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
          <path strokeLinecap="round" strokeLinejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
        </svg>
        {t('conversationDetail.viewFullDetail')}
      </Link>

      {threatActorSummary && (
        <div className="p-2 bg-surface-base rounded text-[0.625rem] text-on-surface-dim">
          Threat Actor: {threatActorSummary}
        </div>
      )}
    </section>
  );
}

function IocField({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <span className="text-[0.625rem] font-bold text-on-surface-dim uppercase tracking-widest">{label}</span>
      <p className="text-xs font-medium text-on-surface mt-0.5 truncate">{value}</p>
    </div>
  );
}

export default ConversationDetail;
