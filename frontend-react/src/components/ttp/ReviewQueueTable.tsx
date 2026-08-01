import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { useTtpReviewQueue } from '@/hooks/useTtps';
import {
  useConversationDetail,
  useConversationIocs,
  useConversationMessages,
} from '@/hooks/useConversations';
import { MaskModeProvider } from '@/hooks/MaskModeProvider';
import { useMaskMode } from '@/hooks/useMaskMode';
import { Loading } from '@/components/feedback/Loading';
import { ErrorMessage } from '@/components/feedback/ErrorMessage';
import { Pagination } from '@/components/ui/Pagination';
import { TtpChip } from '@/components/ttp/TtpChip';
import { bodyStartOffset, evidenceRanges, toBodyRanges, highlightSegments } from '@/lib/ttpEvidence';
import { maskPiiInBody } from '@/lib/maskPiiInBody';
import { timeSince, formatShortTimestamp } from '@/lib/time';
import type { TtpReviewQueueItem } from '@/types/ttp';

const REVIEW_PAGE_SIZE = 15;
/** Code points of raw-body context shown on each side of the evidence span. */
const EVIDENCE_CONTEXT = 120;

type SortKey = 'ttp_code' | 'confidence' | 'conv_id' | 'ts_msg' | 'extraction_model';

function SortTh({ label, sortKey: key, current, dir, onSort }: {
  label: string; sortKey: SortKey; current: SortKey; dir: 'asc' | 'desc'; onSort: (k: SortKey) => void;
}) {
  const isActive = current === key;
  return (
    <th className="px-5 py-3 font-medium cursor-pointer select-none hover:text-on-surface transition-colors" onClick={() => onSort(key)}>
      {label}
      <span className={`ml-1.5 inline-block text-[0.6rem] ${isActive ? 'text-accent' : 'text-on-surface-dim'}`}>
        {isActive ? (dir === 'desc' ? '▼' : '▲') : '⇅'}
      </span>
    </th>
  );
}

/**
 * Read-only TTP review queue (triage tooling, no status mutation). The whole
 * surface lives under its OWN MaskModeProvider — evidence excerpts are masked
 * by default and reveal is a deliberate, tab-local user action; no other page
 * inherits this state.
 */
export function ReviewQueueTable() {
  return (
    <MaskModeProvider>
      <ReviewQueueContent />
    </MaskModeProvider>
  );
}

function ReviewQueueContent() {
  const { t } = useTranslation();
  const { masked, toggle } = useMaskMode();
  const { data, isLoading, error, refetch } = useTtpReviewQueue();

  const [page, setPage] = useState(1);
  const [sortKey, setSortKey] = useState<SortKey>('ts_msg');
  const [sortDir, setSortDir] = useState<'asc' | 'desc'>('desc');
  const [expandedObsId, setExpandedObsId] = useState<string | null>(null);

  const items = useMemo(() => data?.items ?? [], [data?.items]);

  const toggleSort = (key: SortKey) => {
    if (sortKey === key) setSortDir((d) => (d === 'desc' ? 'asc' : 'desc'));
    else { setSortKey(key); setSortDir('desc'); }
    setPage(1);
  };

  const sorted = useMemo(() => {
    return [...items].sort((a, b) => {
      let cmp: number;
      switch (sortKey) {
        case 'ttp_code': cmp = a.ttp_code.localeCompare(b.ttp_code); break;
        case 'confidence': cmp = a.confidence - b.confidence; break;
        case 'conv_id': cmp = a.conv_id.localeCompare(b.conv_id); break;
        case 'extraction_model': cmp = a.extraction_model.localeCompare(b.extraction_model); break;
        default: {
          const ta = a.ts_msg ? new Date(a.ts_msg).getTime() : 0;
          const tb = b.ts_msg ? new Date(b.ts_msg).getTime() : 0;
          cmp = ta - tb;
        }
      }
      if (cmp === 0) return a.obs_id.localeCompare(b.obs_id);
      return sortDir === 'desc' ? -cmp : cmp;
    });
  }, [items, sortKey, sortDir]);

  const paged = sorted.slice((page - 1) * REVIEW_PAGE_SIZE, page * REVIEW_PAGE_SIZE);

  if (isLoading) return <Loading message={t('ttpReview.loading')} />;
  if (error) return <ErrorMessage message={t('ttpReview.failedLoad')} onRetry={() => void refetch()} />;

  const total = data?.total ?? 0;

  if (items.length === 0) {
    return (
      <div
        data-testid="ttp-review-empty"
        className="bg-surface-low rounded-lg px-5 py-16 text-center space-y-2"
      >
        <span aria-hidden="true" className="mx-auto flex h-10 w-10 items-center justify-center rounded-full bg-emerald-500/15 text-lg text-emerald-400">✓</span>
        <p className="text-sm font-medium text-on-surface">{t('ttpReview.emptyTitle')}</p>
        <p className="text-xs text-on-surface-dim max-w-md mx-auto">{t('ttpReview.emptyBody')}</p>
      </div>
    );
  }

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <span className="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium bg-amber-500/20 text-amber-400">
            {t('ttpReview.queueSize', { count: total })}
          </span>
          {total > items.length && (
            <span className="text-[11px] text-on-surface-dim italic" data-testid="ttp-review-cap-note">
              {t('ttpReview.capNote', { shown: items.length, total })}
            </span>
          )}
        </div>
        <button
          type="button"
          data-testid="ttp-review-mask-toggle"
          onClick={toggle}
          aria-label={t('ttpReview.toggleMask')}
          aria-pressed={!masked}
          className="text-xs px-3 py-1.5 rounded bg-surface-high text-on-surface-variant hover:text-on-surface border border-outline-variant cursor-pointer"
        >
          {masked ? `👁 ${t('ttpReview.reveal')}` : `🔒 ${t('ttpReview.mask')}`}
        </button>
      </div>

      <Pagination page={page} pageSize={REVIEW_PAGE_SIZE} totalItems={sorted.length} onPageChange={setPage} />

      <div className="bg-surface-low rounded-lg overflow-hidden">
        <table className="w-full text-left">
          <thead>
            <tr className="text-xs text-on-surface-dim uppercase tracking-widest">
              <SortTh label={t('ttpReview.ttpColumn')} sortKey="ttp_code" current={sortKey} dir={sortDir} onSort={toggleSort} />
              <SortTh label={t('ttpReview.confidenceColumn')} sortKey="confidence" current={sortKey} dir={sortDir} onSort={toggleSort} />
              <SortTh label={t('ttpReview.conversationColumn')} sortKey="conv_id" current={sortKey} dir={sortDir} onSort={toggleSort} />
              <SortTh label={t('ttpReview.dateColumn')} sortKey="ts_msg" current={sortKey} dir={sortDir} onSort={toggleSort} />
              <SortTh label={t('ttpReview.provenanceColumn')} sortKey="extraction_model" current={sortKey} dir={sortDir} onSort={toggleSort} />
            </tr>
          </thead>
          <tbody className="text-sm">
            {paged.map((item) => (
              <ReviewRow
                key={item.obs_id}
                item={item}
                expanded={expandedObsId === item.obs_id}
                onToggle={() => setExpandedObsId((prev) => (prev === item.obs_id ? null : item.obs_id))}
              />
            ))}
          </tbody>
        </table>
      </div>
      <Pagination page={page} pageSize={REVIEW_PAGE_SIZE} totalItems={sorted.length} onPageChange={setPage} />
    </div>
  );
}

/**
 * One queue row. Expanding renders the evidence panel below it — a
 * conditional mount, so the lazy per-conversation fetches never fire until
 * the analyst asks. Rows without usable offsets show the honest
 * "paraphrased" state instead and never fetch anything.
 */
function ReviewRow({ item, expanded, onToggle }: {
  item: TtpReviewQueueItem;
  expanded: boolean;
  onToggle: () => void;
}) {
  const { t } = useTranslation();

  const hasOffsets =
    item.evidence_start !== null &&
    item.evidence_end !== null &&
    item.evidence_end > item.evidence_start;

  return (
    <>
      <tr
        data-testid="ttp-review-row"
        onClick={onToggle}
        onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onToggle(); } }}
        tabIndex={0}
        aria-expanded={expanded}
        className="transition-colors cursor-pointer outline-none focus-visible:ring-2 focus-visible:ring-accent hover:bg-surface-high/50"
      >
        <td className="px-5 py-3">
          <TtpChip
            code={item.ttp_code}
            label={item.ttp_label}
            phase={item.phase}
            confidence={item.confidence}
            status="review"
          />
        </td>
        <td className="px-5 py-3 font-mono text-on-surface-variant">
          {Math.round(item.confidence * 100)}%
        </td>
        <td className="px-5 py-3">
          <Link
            to={`/conversations/${item.conv_id}`}
            data-testid="ttp-review-conversation-link"
            onClick={(e) => e.stopPropagation()}
            className="font-mono text-xs text-accent hover:underline"
          >
            {item.conv_id.slice(0, 8)}
          </Link>
        </td>
        <td className="px-5 py-3 text-on-surface-dim text-xs">
          {item.ts_msg ? (
            <span title={timeSince(item.ts_msg)}>{formatShortTimestamp(item.ts_msg)}</span>
          ) : '--'}
        </td>
        <td className="px-5 py-3 font-mono text-xs text-on-surface-dim">
          {item.extraction_model !== '' ? item.extraction_model : '--'}
        </td>
      </tr>
      {expanded && (
        <tr>
          <td colSpan={5} className="px-5 pb-4">
            {hasOffsets ? (
              <EvidencePanel item={item} />
            ) : (
              <div
                data-testid="ttp-review-paraphrased"
                className="rounded bg-surface-high/50 px-4 py-3 text-xs italic text-on-surface-dim"
              >
                {t('ttpReview.paraphrased')}
              </div>
            )}
          </td>
        </tr>
      )}
    </>
  );
}

/**
 * On-demand evidence reconstruction. Fetches the conversation's full message
 * list, IOC catalog AND detail (existing hooks, react-query cached), locates
 * the anchored message client-side, and rebuilds the quote from offsets on
 * the RAW body — highlight FIRST, then masking is applied per segment
 * (maskPiiInBody rewrites the string, so masking before slicing would desync
 * the offsets). A 403/network failure on any fetch renders the standard
 * row-level error state.
 */
function EvidencePanel({ item }: { item: TtpReviewQueueItem }) {
  const { t } = useTranslation();
  const { masked } = useMaskMode();
  const messagesQuery = useConversationMessages(item.conv_id);
  const iocsQuery = useConversationIocs(item.conv_id);
  // Deterministic account_email source: the conversation detail is fetched
  // alongside messages + IOCs so the persona/honeypot address is masked even
  // on a fresh-session deep-link to the review tab — a cache-only probe
  // would leave it readable whenever no conversation view was visited first.
  const conversationQuery = useConversationDetail(item.conv_id);

  // Mask set = every conversation IOC's raw value AND value_norm (norms alone
  // miss the body's un-normalized occurrences) + the conversation's honeypot
  // address (quoted-reply headers contain it).
  const maskValues = useMemo<string[]>(() => {
    if (!masked) return [];
    const set = new Set<string>();
    for (const ioc of iocsQuery.data ?? []) {
      if (ioc.value) set.add(ioc.value);
      if (ioc.value_norm) set.add(ioc.value_norm);
    }
    const accountEmail = conversationQuery.data?.account_email;
    if (accountEmail) set.add(accountEmail);
    return Array.from(set);
  }, [masked, iocsQuery.data, conversationQuery.data?.account_email]);

  if (messagesQuery.isLoading || iocsQuery.isLoading || conversationQuery.isLoading) {
    return <p className="px-4 py-3 text-xs italic text-on-surface-dim">{t('ttpReview.evidenceLoading')}</p>;
  }
  if (messagesQuery.isError || iocsQuery.isError || conversationQuery.isError) {
    return (
      <div data-testid="ttp-review-error" className="rounded bg-error/10 px-4 py-3 text-xs text-error">
        {t('ttpReview.evidenceFailed')}
      </div>
    );
  }

  const message = messagesQuery.data?.find((m) => m.message_id === item.msg_id);
  if (!message) {
    return (
      <div data-testid="ttp-review-not-found" className="rounded bg-error/10 px-4 py-3 text-xs text-error">
        {t('ttpReview.messageNotFound')}
      </div>
    );
  }

  const ranges = evidenceRanges([item]);
  const bodyRanges = toBodyRanges(ranges, message.subject, message.body_text);
  if (bodyRanges.length === 0) {
    // Distinguish a genuine subject-line span from offsets that point
    // nowhere in this message (drift/out-of-bounds): only the former may
    // honestly claim the quote lives in the subject.
    const base = bodyStartOffset(message.subject);
    const inSubject = ranges.length > 0 && ranges.every((r) => r.end <= base);
    if (inSubject) {
      return (
        <div className="rounded bg-surface-high/50 px-4 py-3 text-xs text-on-surface-dim">
          {t('ttpReview.subjectEvidence')}{' '}
          <span className="italic text-on-surface-variant">
            {maskPiiInBody(message.subject ?? '', maskValues)}
          </span>
        </div>
      );
    }
    return (
      <div className="rounded bg-surface-high/50 px-4 py-3 text-xs italic text-on-surface-dim">
        {t('ttpReview.offsetsNotLocated')}
      </div>
    );
  }

  // Snap each range OUTWARD to the nearest token (whitespace) boundary in
  // raw-body space BEFORE windowing: the server caps evidence mid-token, and
  // a value fragment split across a highlight boundary would dodge
  // maskPiiInBody's whole-value match. Slight over-highlight is honest; the
  // window is derived from the snapped span so a snapped token never
  // straddles the window edge either.
  const chars = Array.from(message.body_text);
  const snappedRanges = bodyRanges.map((r) => {
    let start = Math.max(0, r.start);
    let end = Math.min(chars.length, r.end);
    while (start > 0 && !/\s/.test(chars[start - 1])) start -= 1;
    while (end < chars.length && !/\s/.test(chars[end])) end += 1;
    return { start, end };
  });

  // ±EVIDENCE_CONTEXT code points of raw-body context around the evidence.
  // All slicing is code-point based (Array.from) per the offset convention.
  const spanStart = Math.min(...snappedRanges.map((r) => r.start));
  const spanEnd = Math.max(...snappedRanges.map((r) => r.end));
  const winStart = Math.max(0, spanStart - EVIDENCE_CONTEXT);
  const winEnd = Math.min(chars.length, spanEnd + EVIDENCE_CONTEXT);
  const windowText = chars.slice(winStart, winEnd).join('');
  const windowRanges = snappedRanges.map((r) => ({ start: r.start - winStart, end: r.end - winStart }));
  const segments = highlightSegments(windowText, windowRanges);

  return (
    <div className="rounded bg-surface-high/50 px-4 py-3">
      <p className="text-xs leading-relaxed text-on-surface-variant whitespace-pre-line">
        {winStart > 0 && <span aria-hidden="true">… </span>}
        {segments.map((seg, i) => {
          const text = maskPiiInBody(seg.text, maskValues);
          return seg.highlighted ? (
            <mark
              key={`seg-${i}`}
              data-testid="ttp-review-evidence"
              className="rounded bg-accent/25 px-0.5 text-on-surface"
            >
              {text}
            </mark>
          ) : (
            <span key={`seg-${i}`}>{text}</span>
          );
        })}
        {winEnd < chars.length && <span aria-hidden="true"> …</span>}
      </p>
    </div>
  );
}

export default ReviewQueueTable;
