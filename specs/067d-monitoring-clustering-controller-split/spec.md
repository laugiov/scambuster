# Spec 067d — Monitoring & Clustering controller split

> **Parent**: `specs/067-remaining-controller-compliance/`
> **Sprint**: 2 | **Effort**: 1 day
> **Sub-spec branch**: `067d-monitoring-clustering-controller-split`

## Scope

Split 3 multi-action controllers into single-action `__invoke` controllers.

### AnalyticsController → 8 controllers
| Method | New controller | Route |
|--------|---------------|-------|
| `iocTimeline` | `IocTimelineController` | GET `/analytics/ioc-timeline` |
| `conversationTimeline` | `ConversationTimelineController` | GET `/analytics/conversation-timeline` |
| `iocDistribution` | `IocDistributionController` | GET `/analytics/ioc-distribution` |
| `scamDistribution` | `ScamDistributionController` | GET `/analytics/scam-distribution` |
| `costTimeline` | `CostTimelineController` | GET `/analytics/cost-timeline` |
| `pipelineTimeline` | `PipelineTimelineController` | GET `/analytics/pipeline-timeline` |
| `activityFeed` | `ActivityFeedController` | GET `/analytics/activity-feed` |
| `weeklyTrends` | `WeeklyTrendsController` | GET `/analytics/weekly-trends` |

### ImpactController → 2 controllers
| Method | New controller | Route |
|--------|---------------|-------|
| `summary` | `ImpactSummaryController` | GET `/impact/summary` |
| `iocUniqueness` | `IocUniquenessController` | GET `/impact/ioc-uniqueness` |

### ClusterController → 5 controllers
| Method | New controller | Route |
|--------|---------------|-------|
| `list` | `ListClustersController` | GET `/clusters` |
| `stats` | `ClusterStatsController` | GET `/clusters/stats` |
| `detail` | `ClusterDetailController` | GET `/clusters/{id}` |
| `exportStix` | `ExportClusterStixController` | GET `/clusters/{id}/export/stix` |
| `iocCluster` | `IocClusterLookupController` | GET `/iocs/{indicatorId}/cluster` |

## Acceptance criteria
- [ ] 15 new `__invoke` controllers
- [ ] 3 old multi-action controllers deleted
- [ ] All routes identical
- [ ] All tests pass unchanged
