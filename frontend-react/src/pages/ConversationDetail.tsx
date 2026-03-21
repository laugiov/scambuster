import { useState } from 'react';
import { useParams, Link } from 'react-router-dom';
import { useConversationDetail, useConversationMessages, useConversationIocs } from '@/hooks/useConversations';
import { Badge, statusToBadgeVariant } from '@/components/ui/Badge';
import { Loading } from '@/components/feedback/Loading';
import { ErrorMessage } from '@/components/feedback/ErrorMessage';
import { PERSONA_DISPLAY_NAMES } from '@/types/api';
import type { Message, Ioc } from '@/types/api';

function formatTime(iso: string): string {
  return new Date(iso).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
}

function formatDate(iso: string): string {
  return new Date(iso).toLocaleString('en-GB', {
    year: 'numeric', month: '2-digit', day: '2-digit',
    hour: '2-digit', minute: '2-digit',
  });
}

function iocSeverity(score: number): { label: string; color: string; border: string } {
  if (score >= 5) return { label: 'HIGH', color: 'bg-error/20 text-error', border: 'border-error' };
  if (score >= 1) return { label: 'MEDIUM', color: 'bg-warning/20 text-warning', border: 'border-warning' };
  return { label: 'LOW', color: 'bg-status-waiting/20 text-status-waiting', border: 'border-status-waiting' };
}

export function ConversationDetail() {
  const { id } = useParams<{ id: string }>();
  const conv = useConversationDetail(id ?? '');
  const messages = useConversationMessages(id ?? '');
  const iocs = useConversationIocs(id ?? '');
  const [selectedIoc, setSelectedIoc] = useState<Ioc | null>(null);

  if (conv.isLoading) return <Loading message="Loading conversation..." />;
  if (conv.error) return <ErrorMessage message="Failed to load conversation" onRetry={() => void conv.refetch()} />;
  if (!conv.data) return <ErrorMessage message="Conversation not found" />;

  const c = conv.data;

  return (
    <div className="flex flex-col -m-8 h-[calc(100vh-0px)]">
      {/* Top header bar */}
      <header className="bg-surface-low px-6 py-3 flex items-center justify-between shrink-0">
        <div className="flex items-center gap-4">
          <Link to="/conversations" className="text-accent hover:text-accent-hover transition-colors" aria-label="Back to conversations">
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
              Persona: {PERSONA_DISPLAY_NAMES[c.persona as keyof typeof PERSONA_DISPLAY_NAMES] ?? c.persona}
            </span>
          )}
          {c.scam_type && (
            <span className="px-3 py-1 bg-warning/20 text-warning text-xs uppercase tracking-wider font-bold rounded-lg">
              {c.scam_type}
            </span>
          )}
          <Badge label={c.status} variant={statusToBadgeVariant(c.status)} />
        </div>
      </header>

      {/* 3-column grid — each column scrolls independently */}
      <div className="grid grid-cols-12 gap-6 p-6 flex-1 min-h-0">
        {/* Left: metadata + IOCs */}
        <div className="col-span-3 flex flex-col gap-6 overflow-y-auto pr-1">
          <SessionMetadata conv={c} messageCount={messages.data?.length ?? 0} iocCount={iocs.data?.length ?? 0} />
          <ExtractedIocs iocs={iocs.data ?? []} isLoading={iocs.isLoading} selectedId={selectedIoc?.obs_id ?? null} onSelect={setSelectedIoc} />
        </div>

        {/* Center: email thread */}
        <div className="col-span-6 flex flex-col bg-surface-low rounded-lg overflow-hidden">
          <div className="px-6 py-4 bg-surface-high flex items-center gap-2">
            <svg className="w-4 h-4 text-accent" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
              <path strokeLinecap="round" strokeLinejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" />
            </svg>
            <span className="text-sm font-semibold text-on-surface">Email Thread — Automated</span>
            <span className="text-xs text-on-surface-dim ml-1">via IMAP honeypot</span>
          </div>

          <div className="flex-1 overflow-y-auto p-6 space-y-4">
            {messages.isLoading ? (
              <Loading message="Loading messages..." />
            ) : (
              (messages.data ?? []).map((msg) => (
                <MessageBubble key={msg.message_id} message={msg} />
              ))
            )}
            {!messages.isLoading && (messages.data ?? []).length === 0 && (
              <p className="text-center text-on-surface-dim text-sm py-8">No messages yet</p>
            )}
          </div>

          <div className="p-4 bg-surface-low">
            <div className="flex items-center bg-surface-base rounded-lg px-4 py-3 opacity-50">
              <span className="text-sm text-on-surface-variant italic flex-1">
                Automated — agent controls this conversation
              </span>
              <svg className="w-4 h-4 text-on-surface-dim" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                <path strokeLinecap="round" strokeLinejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
              </svg>
            </div>
          </div>
        </div>

        {/* Right: agent log + pipeline OR IOC detail */}
        <div className="col-span-3 overflow-y-auto pl-1">
          <div className="flex flex-col gap-6">
            {selectedIoc ? (
              <IocDetailPanel ioc={selectedIoc} onClose={() => setSelectedIoc(null)} />
            ) : (
              <>
                <AgentDecisionLog />
                <DoubleValidationPipeline />
              </>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}

function SessionMetadata({ conv, messageCount, iocCount }: {
  conv: { conv_id: string; score_risk: number; ts_first?: string; ts_last?: string; created_at?: string; persona?: string | null; scam_type?: string | null };
  messageCount: number;
  iocCount: number;
}) {
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
      <h3 className="text-xs uppercase tracking-widest text-on-surface-dim font-medium mb-4">Session Metadata</h3>
      <div className="space-y-3">
        <MetaRow label="Started" value={startDate ? formatDate(startDate) : '--'} />
        <div className="grid grid-cols-2 gap-3">
          <MetaRow label="Duration" value={duration} />
          <MetaRow label="Turns" value={String(messageCount)} />
        </div>
        <div className="grid grid-cols-2 gap-3">
          <MetaRow label="IOCs found" value={String(iocCount)} />
          <MetaRow label="Risk Score" value={String(conv.score_risk)} highlight />
        </div>
      </div>
    </section>
  );
}

function MetaRow({ label, value, highlight }: { label: string; value: string; highlight?: boolean }) {
  return (
    <div className="flex flex-col">
      <span className="text-[0.625rem] text-accent-muted uppercase font-bold tracking-tight">{label}</span>
      <span className={`text-sm font-medium ${highlight ? 'text-accent' : 'text-on-surface'}`}>{value}</span>
    </div>
  );
}

function ExtractedIocs({ iocs, isLoading, selectedId, onSelect }: {
  iocs: Ioc[];
  isLoading: boolean;
  selectedId: string | null;
  onSelect: (ioc: Ioc | null) => void;
}) {
  if (isLoading) return <Loading message="Loading IOCs..." />;

  return (
    <section className="bg-surface-low rounded-lg p-5 flex-1">
      <h3 className="text-xs uppercase tracking-widest text-on-surface-dim font-medium mb-4">
        Extracted IOCs <span className="text-on-surface-variant">({iocs.length})</span>
      </h3>
      <div className="space-y-2">
        {iocs.map((ioc) => {
          const sev = iocSeverity(ioc.score?.agg ?? 0);
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
        {iocs.length === 0 && (
          <p className="text-xs text-on-surface-dim text-center py-4">No IOCs extracted</p>
        )}
      </div>
    </section>
  );
}

function MessageBubble({ message }: { message: Message }) {
  const isOutbound = message.direction === 'out';
  const bodyPreview = message.body_text.length > 500
    ? message.body_text.slice(0, 500) + '...'
    : message.body_text;

  return (
    <div className={`flex ${isOutbound ? 'justify-end' : 'justify-start'}`}>
      <div className={`max-w-[80%] p-4 rounded-xl ${
        isOutbound
          ? 'bg-accent-muted/10 rounded-tr-none border border-accent-muted/20'
          : 'bg-surface-highest rounded-tl-none border border-surface-highest'
      }`}>
        {message.subject && (
          <p className="text-xs text-on-surface-dim font-medium mb-1">{message.subject}</p>
        )}
        <p className="text-sm leading-relaxed text-on-surface whitespace-pre-line">{bodyPreview}</p>
        <span className={`text-[0.625rem] mt-2 block opacity-60 ${isOutbound ? 'text-right' : ''}`}>
          {message.ts_msg ? formatTime(message.ts_msg) : '--:--'} · {isOutbound ? 'Sentinel' : 'Remote Agent'}
        </span>
      </div>
    </div>
  );
}

function AgentDecisionLog() {
  // Agent logs are not yet available from the API
  // This renders a static placeholder matching the maquette
  const events = [
    { time: '--:--', label: 'Orchestrator: thread initialized', color: 'bg-accent-muted' },
    { time: '--:--', label: 'ScamClassifier: type detected', color: 'bg-accent-muted' },
    { time: '--:--', label: 'IocExtractor: indicators flagged', color: 'bg-warning' },
    { time: '--:--', label: 'Generator: response drafted', color: 'bg-accent-muted' },
    { time: '--:--', label: 'PolicyGuard: hard rules passed', color: 'bg-success' },
    { time: '--:--', label: 'LLM Validator: approved', color: 'bg-success' },
  ];

  return (
    <section className="bg-surface-low rounded-lg p-5">
      <h3 className="text-xs uppercase tracking-widest text-on-surface-dim font-medium mb-5">Agent Decision Log</h3>
      <div className="relative space-y-4 before:absolute before:left-[7px] before:top-2 before:bottom-2 before:w-px before:bg-surface-highest">
        {events.map((evt) => (
          <div key={evt.label} className="relative pl-6">
            <div className={`absolute left-0 top-1.5 w-3.5 h-3.5 rounded-full ${evt.color} border-2 border-surface-low`} />
            <div className="flex flex-col">
              <span className="text-[0.625rem] text-on-surface-dim font-mono">{evt.time}</span>
              <span className="text-xs font-medium text-on-surface">{evt.label}</span>
            </div>
          </div>
        ))}
      </div>
    </section>
  );
}

function DoubleValidationPipeline() {
  const steps = [
    { label: 'Generator: response drafted', done: true },
    { label: 'PolicyGuard: hard rules passed', done: true },
    { label: 'LLM Validator: approved — sent', done: true },
  ];

  return (
    <section className="bg-surface-low rounded-lg p-5">
      <h3 className="text-xs uppercase tracking-widest text-on-surface-dim font-medium mb-4">Double Validation Pipeline</h3>
      <div className="space-y-3">
        {steps.map((step) => (
          <div key={step.label} className="flex items-center gap-3 p-3 bg-surface-base rounded-lg">
            <div className="w-5 h-5 rounded-full bg-accent-muted/20 flex items-center justify-center shrink-0">
              {step.done && (
                <svg className="w-3 h-3 text-accent" fill="currentColor" viewBox="0 0 20 20">
                  <path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" />
                </svg>
              )}
            </div>
            <span className="text-xs font-medium text-on-surface">{step.label}</span>
          </div>
        ))}
      </div>
    </section>
  );
}

function IocDetailPanel({ ioc, onClose }: { ioc: Ioc; onClose: () => void }) {
  const sev = iocSeverity(ioc.score?.agg ?? 0);

  return (
    <section className="bg-surface-low rounded-lg p-5 flex flex-col gap-4">
      <div className="flex items-center justify-between">
        <h3 className="text-sm font-bold text-on-surface">IOC Detail</h3>
        <button
          onClick={onClose}
          className="p-1 hover:bg-surface-highest rounded text-on-surface-dim cursor-pointer"
          aria-label="Close IOC detail"
        >
          <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2} aria-hidden="true">
            <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>

      <div className="p-3 bg-surface-base rounded-lg">
        <span className="text-[0.625rem] font-bold text-accent-muted uppercase tracking-widest block mb-1">Value</span>
        <p className="font-mono text-sm font-bold break-all text-on-surface">{ioc.value}</p>
        <div className="mt-2 flex items-center gap-2">
          <span className={`text-xs px-2 py-0.5 rounded font-medium ${sev.color} bg-surface-high`}>{sev.label}</span>
          <span className="text-xs px-2 py-0.5 bg-surface-high text-on-surface-variant rounded">{ioc.type.toUpperCase()}</span>
        </div>
      </div>

      <div className="grid grid-cols-2 gap-3">
        <IocField label="Category" value={ioc.category} />
        <IocField label="First Seen" value={new Date(ioc.ts_observed).toLocaleDateString('en-GB')} />
        <IocField label="VT Score" value={String(ioc.score?.vt ?? 0)} />
        <IocField label="URLScan" value={String(ioc.score?.urlscan ?? 0)} />
        <IocField label="Aggregate" value={String(ioc.score?.agg ?? 0)} />
        <IocField label="Normalized" value={ioc.value_norm} />
      </div>

      <div>
        <span className="text-[0.625rem] font-bold text-on-surface-dim uppercase tracking-widest block mb-1">Analysis</span>
        <p className="text-xs text-on-surface-variant bg-surface-base rounded p-2">
          {ioc.score?.explain ?? 'No analysis available'}
        </p>
      </div>

      <div className="flex-1">
        <span className="text-[0.625rem] font-bold text-on-surface-dim uppercase tracking-widest block mb-1">STIX Pattern</span>
        <pre className="p-2 bg-surface-base rounded font-mono text-[0.625rem] text-accent/70 overflow-auto">
{`[${ioc.type}:value = '${ioc.value_norm.replace(/'/g, "\\'")}']`}
        </pre>
      </div>
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
