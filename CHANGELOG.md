# Changelog - ScamBuster

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

---

## [1.3.0] - March 2026

### Added
- English synthetic dataset generation (100+ conversations)
- n8n workflow anonymization for public preview
- Documentation update for preview repository

---

## [1.2.0] - January 2026

### Added
- A/B testing validation framework (2,221 synthetic conversations)
- Statistical analysis: p < 0.001, Cohen's d = 0.37
- Test suite expanded to 1,039 automated tests

---

## [1.1.0] - December 2025

### Added
- Prompt injection detection via InjectionDetector agent (two-layer forensic)
- Scaled platform to 1,000+ active conversations

---

## [1.0.0] - 21 November 2025

### Added - Adaptive Scambaiting Module

#### Database
- **Migration 1**: ALTER TABLE `conversation` - 3 new columns:
  - `engagement_duration_sec` (INTEGER): Conversation duration in seconds
  - `turns_count` (INTEGER): Number of conversation turns
  - `reward_value` (NUMERIC(5,4)): Normalized reward [0.0, 1.0]
  - Indexes: `idx_conversation_reward`, `idx_conversation_duration`

- **Migration 2**: CREATE TABLE `persona_performance_stats`:
  - Composite key: `(persona_id, scam_type_id)` for contextual bandit
  - Columns: `sessions_count`, `reward_sum`, `reward_avg`, `last_updated`
  - CHECK constraints: `sessions_count >= 0`, `reward_avg BETWEEN 0.0 AND 1.0`
  - Indexes: `scam_type_id`, `reward_avg DESC`, `last_updated DESC`
  - Foreign keys CASCADE to `persona` and `lkp_scam_type`

#### Domain Layer
- **Value Object** `ConversationMetrics`: Conversation metrics (duration, IOCs, completion)
- **Value Object** `PersonaPerformance`: Persona performance stats (reward_avg, sessions_count)
- **Domain Event** `ConversationEndedEvent`: Dispatched when a conversation ends

#### Application Layer
- **Service** `PersonaOptimizer`: Epsilon-greedy algorithm (80% exploitation, 20% exploration)
  - `selectPersona(string $scamTypeCode): ?string` -- Optimal selection
  - `getSelectionStats(string $scamTypeCode): array` -- Selection stats
  - Cold start handling: < 3 sessions = pure exploration
  - Tie-breaking by sessions_count

- **Service** `ConversationMetricsCollector`: Metrics collection
  - Reuses existing `IocHandler` and `MessageHandler`
  - Returns a `ConversationMetrics` Value Object

- **Service** `ConversationClosureService`: Conversation closure
  - `closeConversation(string $convId): void` -- Single closure
  - `closeConversationsBatch(array $convIds): int` -- Batch closure (CRON)
  - Dispatches `ConversationEndedEvent` after reward computation

#### Infrastructure Layer
- **Entity** `PersonaPerformanceStatsEntity`: Doctrine entity for persistence
- **Repository** `PersonaPerformanceStatsRepository`: Doctrine repository
  - Methods: `findOrCreate`, `findBestPerformingPersona`, `findTopPerformingPersonas`, `findAllByScamType`, `countColdStartPersonas`
- **Event Listener** `ConversationEndedListener`: Updates `persona_performance_stats` with new reward (moving average)

#### UI Layer (REST API)
- **POST** `/api/v1/scambaiting/conversation/{convId}/close` -- Close a conversation
- **GET** `/api/v1/scambaiting/stats/{scamTypeCode}` -- Epsilon-greedy stats for a scam type
- **GET** `/api/v1/scambaiting/stats` -- Aggregated stats across all scam types
- **GET** `/api/v1/scambaiting/persona/{personaCode}/performance` -- Persona performance
- **POST** `/api/v1/scambaiting/select-persona` -- Test endpoint for selection

All endpoints require JWT authentication.

#### n8n Workflow
- **Workflow** `WF-SCAMBAITING-END-CONVERSATION`:
  - Daily CRON at 03:00 UTC
  - Queries PostgreSQL: conversations with `status='open'` and `created_at < NOW() - 48h`
  - Loops over conversations (limit 500)
  - Calls API `POST /close` for each conversation
  - Aggregates results (success, failed)

#### Tests
- **10 unit tests (Domain)**: ConversationMetrics, PersonaPerformance, ConversationEndedEvent
- **5 unit tests (Application)**: PersonaOptimizer selection, cold start, tie-breaking
- **7 integration tests**: Repository, Event Listener, Controllers
- **9 regression tests**: ReplyHandler (no breakage of existing behavior)
- **3 E2E tests**: Full end-to-end workflow
- **6 fixture scenarios**: Exploitation, exploration, cold start, edge cases

**Total**: 40+ tests, 100+ assertions, ~95% coverage

### Changed

#### Application Layer
- **`ReplyHandler.php`**: Replaced `assignRandomPersona()` with `PersonaOptimizer->selectPersona()`
  - Before: Uniform random selection
  - After: Epsilon-greedy selection (80% exploitation, 20% exploration)

#### Symfony Services
- **services.yaml**: Autowiring configuration for `PersonaOptimizer`

### Fixed
- **ConversationEndedEventTest**: Type mismatch `conversationId` (int to string)
- **Controller permissions**: `chmod 644` on all UI Layer controllers
- **Symfony cache**: Cache invalidation after controller creation

---

## [0.9.0] - 15 November 2025

### Added
- Conversation history summary for LLM context enrichment
- Endpoint: `POST /api/v1/communication/conversation/{convId}/history-summary`
- Service: `ConversationHistoryService` with LLM-generated summary

### Changed
- `ReplyHandler`: Integrated history summary into LLM context

---

## [0.8.0] - 10 November 2025

### Added
- Post-IBAN strategy for capturing additional IOCs
- Optimized reply generation after IBAN capture

### Changed
- `ReplyOrchestrator`: Added high-value IOC capture logic

---

## [0.7.0] - 5 November 2025

### Added
- Multi-conversation support with the same sender email
- Multiple active conversations per sender handling

### Fixed
- Duplicate attachment upload error

---

## [Unreleased] - Before November 2025

Earlier versions not documented in this changelog.
History available via `git log`.

---

## Change Types

- **Added**: New features
- **Changed**: Changes to existing features
- **Deprecated**: Features to be removed soon
- **Removed**: Removed features
- **Fixed**: Bug fixes
- **Security**: Security fixes

---

**Format**: [Keep a Changelog](https://keepachangelog.com/)
**Versioning**: [Semantic Versioning](https://semver.org/)
