// TTP (tactic/technique/procedure) API types. snake_case mirrors the backend
// JSON exactly (App\Application\Ttp\TtpQueryService + the read controllers under
// App\UI\Http\Ttp). Evidence is carried as character OFFSETS only — never text —
// and the UI reconstructs any highlight client-side from the message body.

/** One aggregated TTP row of a cluster's profile (GET /clusters/{id}/ttps). */
export interface ClusterTtpEntry {
  ttp_code: string;
  ttp_label: string;
  phase: string;
  observation_count: number;
  conversation_count: number;
  avg_confidence: number;
  first_seen: string | null;
  last_seen: string | null;
}

/** An adjacent-pair (bigram) tactic sequence with its cluster-wide count. */
export interface TtpSequence {
  sequence: string[];
  count: number;
}

/** Aggregated TTP profile for a threat-actor cluster (GET /clusters/{id}/ttps). */
export interface ClusterTtpProfile {
  cluster_id: string;
  ttps: ClusterTtpEntry[];
  top_sequences: TtpSequence[];
}

/**
 * A TTP observed on one message inside the elicitation timeline. Evidence
 * offsets are UTF-8 code-point positions [evidence_start, evidence_end) into the
 * message's `subject + "\n\n" + body_text`; both are null when the LLM
 * paraphrased (no verbatim quote), in which case only the badge is shown.
 */
export interface TimelineTtp {
  ttp_code: string;
  phase: string;
  confidence: number;
  status: string;
  evidence_start: number | null;
  evidence_end: number | null;
}

/**
 * An IOC revealed within a message. The server filters the list to actionable
 * types (header noise such as spf/dkim results never appears). `indicator_id`
 * is the canonical indicator reference; `stimulus_msg_id` is the outbound
 * message the enrichment attributed the revelation to — null when the context
 * is unenriched or the revelation was first-contact (unsolicited).
 */
export interface RevealedIoc {
  type: string;
  value_norm: string;
  indicator_id: string | null;
  stimulus_msg_id: string | null;
}

/** One message in the per-message elicitation timeline. */
export interface ConversationTimelineEntry {
  msg_id: string;
  direction: 'in' | 'out';
  ts_msg: string | null;
  subject: string | null;
  ttps: TimelineTtp[];
  iocs_revealed: RevealedIoc[];
  stimulus_type: string | null;
}

/** A flat, ordered TTP observation for one conversation. */
export interface ConversationTtpObservation {
  msg_id: string;
  ts_msg: string | null;
  ttp_code: string;
  ttp_label: string;
  phase: string;
  confidence: number;
  status: string;
  evidence_start: number | null;
  evidence_end: number | null;
}

/** TTP observations + elicitation timeline for a conversation (GET /conversations/{id}/ttps). */
export interface ConversationTtps {
  conv_id: string;
  observations: ConversationTtpObservation[];
  timeline: ConversationTimelineEntry[];
}

/**
 * One STIX-style external reference of a taxonomy entry (e.g. its ATT&CK
 * mapping), decoded server-side from the lkp_ttp.external_refs JSONB column.
 * The backend passes entries through as loose maps, so every field is optional.
 */
export interface TtpExternalRef {
  source_name?: string;
  external_id?: string;
  url?: string;
  [key: string]: unknown;
}

/**
 * One taxonomy entry with its usage counters (GET /ttps). Every closed-taxonomy
 * entry is returned — including zero-observation ones — so coverage is honest.
 * observation_count / conversation_count / first_seen / last_seen cover confirmed
 * observations only; review_count tallies the rows still awaiting analyst triage.
 * examples / external_refs carry the taxonomy's example formulations and ATT&CK
 * references (both may be empty, never absent).
 */
export interface TtpTaxonomyRow {
  ttp_code: string;
  ttp_label: string;
  phase: string;
  definition: string;
  examples: string[];
  external_refs: TtpExternalRef[];
  observation_count: number;
  conversation_count: number;
  first_seen: string | null;
  last_seen: string | null;
  review_count: number;
}

/** The full TTP taxonomy overview with a version stamp (GET /ttps). */
export interface TtpTaxonomyResponse {
  taxonomy_version: string;
  ttps: TtpTaxonomyRow[];
}

/**
 * One weekly bucket of the phase trend. `week` is the ISO date (YYYY-MM-DD) of
 * the bucket's Monday; `counts` is zero-filled server-side for every canonical
 * phase, with any unexpected phase appended rather than dropped.
 */
export interface TtpPhaseTrendWeek {
  week: string;
  counts: Record<string, number>;
}

/**
 * Weekly confirmed-observation counts per kill-chain phase over the last 8 ISO
 * weeks, bucketed on the message timestamp (GET /ttps/phase-trend).
 */
export interface TtpPhaseTrend {
  weeks: TtpPhaseTrendWeek[];
}

/** One cluster (row) of the shared-playbook matrix (GET /ttps/cluster-matrix). */
export interface ClusterTtpMatrixCluster {
  cluster_id: string;
  label: string;
  observation_total: number;
  /** Distinct conversations with any confirmed observation — the per-conversation normalizer. */
  conversation_total: number;
}

/** One TTP (column) of the shared-playbook matrix. */
export interface ClusterTtpMatrixTtp {
  ttp_code: string;
  ttp_label: string;
  phase: string;
}

/**
 * One populated cell of the shared-playbook matrix. The grid is sparse: zero
 * cells are omitted by the backend, so a missing (cluster, ttp) pair means zero.
 */
export interface ClusterTtpMatrixCell {
  cluster_id: string;
  ttp_code: string;
  count: number;
  /** Distinct conversations in the cluster exhibiting the TTP — the fair per-conversation count. */
  conversation_count: number;
}

/**
 * Shared-playbook matrix across threat-actor clusters (GET /ttps/cluster-matrix).
 * The cluster set is capped: when `truncated` is true, `total_clusters` reports
 * the full population so the cap is never silent.
 */
export interface ClusterTtpMatrix {
  clusters: ClusterTtpMatrixCluster[];
  ttps: ClusterTtpMatrixTtp[];
  cells: ClusterTtpMatrixCell[];
  truncated: boolean;
  total_clusters: number;
}

/**
 * One review-status observation awaiting analyst triage
 * (GET /ttps/review-queue). Carries taxonomy identity, confidence,
 * conversation/message anchors and extraction provenance — never the evidence
 * text: evidence_start/evidence_end are code-point offsets into the message's
 * `subject + "\n\n" + body_text` (both null when the model paraphrased).
 */
export interface TtpReviewQueueItem {
  obs_id: string;
  ttp_code: string;
  ttp_label: string;
  phase: string;
  confidence: number;
  conv_id: string;
  msg_id: string;
  ts_msg: string | null;
  evidence_start: number | null;
  evidence_end: number | null;
  extraction_model: string;
}

/**
 * The read-only review queue (GET /ttps/review-queue), newest message first.
 * The item list is capped server-side; `total` always reports the full queue
 * size so a bitten cap is never silent.
 */
export interface TtpReviewQueue {
  items: TtpReviewQueueItem[];
  total: number;
}

/** The two sequence groupings served by GET /ttps/sequences. */
export type TtpSequenceGrouping = 'cluster' | 'scam_type';

/**
 * One cross-boundary bigram of a sequence group (GET /ttps/sequences).
 * `count` is the group-wide number of occurrences (a repeat inside one
 * conversation counts every time); `conversation_count` is the distinct
 * conversations the pair was seen in.
 */
export interface TtpSequencePair {
  sequence: string[];
  count: number;
  conversation_count: number;
}

/** One group (threat-actor cluster or scam type) with its top sequences. */
export interface TtpSequenceGroup {
  key: string;
  label: string;
  sequences: TtpSequencePair[];
}

/**
 * Top TTP sequences per group (GET /ttps/sequences?group=cluster|scam_type).
 * Pairs seen fewer than `min_support` times group-wide are dropped
 * server-side (groups without any reportable pair are omitted); the group
 * set is capped server-side with an explicit `truncated` flag.
 */
export interface TtpSequences {
  groups: TtpSequenceGroup[];
  min_support: number;
  truncated: boolean;
}

/** One populated cell of the phase-transition aggregate (zero cells omitted). */
export interface TtpPhaseTransition {
  from_phase: string;
  to_phase: string;
  count: number;
}

/**
 * Global kill-chain phase-transition aggregate (GET /ttps/phase-transitions):
 * confirmed-only cross-boundary bigrams grouped by the phase of each pair's
 * endpoints. `total_pairs` reports the full bigram volume.
 */
export interface TtpPhaseTransitions {
  transitions: TtpPhaseTransition[];
  total_pairs: number;
}

/** One persona (row) of the persona × TTP matrix (GET /ttps/persona-matrix). */
export interface PersonaTtpMatrixPersona {
  code: string;
  label: string;
  /** Distinct conversations with any confirmed observation — the per-conversation normalizer. */
  conversation_total: number;
}

/** One TTP (column) of the persona/stimulus matrices. */
export interface MatrixTtpColumn {
  code: string;
  label: string;
  phase: string;
}

/**
 * One populated cell of the persona × TTP matrix. The grid is sparse: zero
 * cells are omitted by the backend, so a missing (persona, ttp) pair means zero.
 * `conversation_count` is the fair normalizer; `observation_count` inflates on
 * chatty conversations.
 */
export interface PersonaTtpMatrixCell {
  persona_code: string;
  ttp_code: string;
  observation_count: number;
  conversation_count: number;
}

/**
 * Persona × TTP matrix (GET /ttps/persona-matrix), confirmed observations only.
 * Conversations with no persona assigned are excluded from the grid and counted
 * in `null_persona_conversations`. The persona set is capped: when `truncated`
 * is true, `total_personas` reports the full population so the cap is never silent.
 */
export interface PersonaTtpMatrix {
  personas: PersonaTtpMatrixPersona[];
  ttps: MatrixTtpColumn[];
  cells: PersonaTtpMatrixCell[];
  truncated: boolean;
  total_personas: number;
  null_persona_conversations: number;
}

/**
 * One populated cell of the stimulus × TTP matrix. `message_count` is the
 * distinct messages where the TTP and the stimulus co-occur; `conversation_count`
 * the distinct conversations.
 */
export interface StimulusTtpMatrixCell {
  stimulus_type: string;
  ttp_code: string;
  message_count: number;
  conversation_count: number;
}

/**
 * Stimulus × TTP matrix (GET /ttps/stimulus-matrix) over the revelation-message
 * population: confirmed TTP observations on messages that also carry an enriched
 * ioc_context with a non-null stimulus_type. `population_messages` is the distinct
 * messages in scope so the population can be stated honestly; UNKNOWN is kept as a
 * stimulus value (the UI decides whether to collapse it).
 */
export interface StimulusTtpMatrix {
  stimuli: string[];
  ttps: MatrixTtpColumn[];
  cells: StimulusTtpMatrixCell[];
  population_messages: number;
}

/** One IOC co-observed with a TTP (GET /ttps/{code}/iocs). */
export interface TtpIocRow {
  indicator_id: string;
  type: string;
  value_norm: string;
  co_occurrence_count: number;
  conversation_count: number;
}

/** IOCs co-observed with a TTP (GET /ttps/{code}/iocs). */
export interface TtpIocs {
  ttp_code: string;
  iocs: TtpIocRow[];
}

/**
 * One live threat-actor cluster practicing a TTP (GET /ttps/{code}/clusters).
 * Counts cover confirmed observations only; first/last seen are message
 * timestamps. `label` is never empty (the backend falls back to a cluster_id
 * prefix when the cluster is unnamed).
 */
export interface TtpClusterRow {
  cluster_id: string;
  label: string;
  observation_count: number;
  conversation_count: number;
  first_seen: string | null;
  last_seen: string | null;
}

/**
 * Clusters practicing a TTP (GET /ttps/{code}/clusters), widest conversation
 * span first. The list is capped server-side: `truncated` is true when the cap
 * bites (no total is reported for this pivot).
 */
export interface TtpClusters {
  items: TtpClusterRow[];
  truncated: boolean;
}

/**
 * One conversation carrying observations of a TTP (GET /ttps/{code}/conversations).
 * The population spans both statuses: `observation_count` is confirmed-only and
 * `review_count` is split out, so a review-only conversation appears with
 * observation_count 0. The subject is the conversation's first message
 * (nullable); last_seen is the newest message carrying the TTP.
 */
export interface TtpConversationRow {
  conv_id: string;
  subject: string | null;
  scam_type_code: string | null;
  observation_count: number;
  review_count: number;
  last_seen: string | null;
}

/**
 * Server-paginated conversations for a TTP (GET /ttps/{code}/conversations).
 * `total` counts every conversation with any-status observations, so the
 * client paginates against it with the echoed limit/offset.
 */
export interface TtpConversations {
  items: TtpConversationRow[];
  total: number;
  limit: number;
  offset: number;
}

/** One TTP co-observed with an IOC (GET /iocs/{id}/ttps). */
export interface IocTtpRow {
  ttp_code: string;
  ttp_label: string;
  phase: string;
  co_occurrence_count: number;
  conversation_count: number;
}

/** TTPs co-observed with an IOC indicator (GET /iocs/{id}/ttps). */
export interface IocTtps {
  ioc: string;
  ttps: IocTtpRow[];
}
