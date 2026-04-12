# Spec 067c — Communication controller split

> **Parent**: `specs/067-remaining-controller-compliance/`
> **Sprint**: 2 | **Effort**: 1.5 days
> **Sub-spec branch**: `067c-communication-controller-split`

## Scope

Split 5 multi-action Communication controllers into single-action `__invoke` controllers.

### ConversationController → 10 controllers
| Method | New controller | Route |
|--------|---------------|-------|
| `create` | `CreateConversationController` | POST `/conversation` |
| `getConversation` | `GetConversationController` | GET `/conversation/{convId}` |
| `listConversations` | `ListConversationsController` | GET `/conversation` |
| `deleteConversation` | `DeleteConversationController` | DELETE `/conversation/{convId}` |
| `patchConversation` | `PatchConversationController` | PATCH `/conversation/{convId}` |
| `addChannel` | `AddChannelController` | POST `/conversation/{convId}/add-channel` |
| `listIocs` | `ListConversationIocsController` | GET `/conversation/{convId}/iocs` |
| `listMessages` | `ListConversationMessagesController` | GET `/conversation/{convId}/messages` |
| `classify` | `ClassifyConversationController` | POST `/conversation/{convId}/classify` |
| `autoClassify` | `AutoClassifyConversationController` | POST `/conversation/{convId}/auto-classify` |

### MessageController → 10 controllers
| Method | New controller | Route |
|--------|---------------|-------|
| `create` | `CreateMessageController` | POST `/message` |
| `getMessage` | `GetMessageController` | GET `/message/{msgId}` |
| `deleteMessage` | `DeleteMessageController` | DELETE `/message/{msgId}` |
| `getMessageAttachments` | `GetMessageAttachmentsController` | GET `/message/{msgId}/attachments` |
| `uploadAttachment` | `UploadAttachmentController` | POST `/message/{msgId}/attachments` |
| `patchMessage` | `PatchMessageController` | PATCH `/message/{msgId}` |
| `getMessageIocs` | `GetMessageIocsController` | GET `/message/{msgId}/iocs` |
| `getMessageRisk` | `GetMessageRiskController` | GET `/message/{msgId}/risk` |
| `getMessageByMessageId` | `GetMessageByMessageIdController` | GET `/message/by-message-id/{messageId}` |
| `extractIocs` | `ExtractIocsController` | POST `/message/{msgId}/extract-iocs` |

### ReplyController → 7 controllers
| Method | New controller | Route |
|--------|---------------|-------|
| `getContext` | `GetConversationContextController` | GET `/conversation/{convId}/context` |
| `generate` | `GenerateReplyController` | POST `/reply/generate` |
| `saveDraft` | `SaveDraftController` | POST `/reply/draft` |
| `getReply` | `GetReplyController` | GET `/reply/{msgId}` |
| `compose` | `ComposeReplyController` | GET `/reply/{msgId}/compose` |
| `markSent` | `MarkReplySentController` | POST `/reply/{msgId}/sent` |
| `sendEmail` | `SendEmailController` | POST `/reply/{msgId}/send-email` |

### AttachmentController → 3 controllers
| Method | New controller | Route |
|--------|---------------|-------|
| `deleteAttachment` | `DeleteAttachmentController` | DELETE `/attachment/{attachmentId}` |
| `downloadAttachment` | `DownloadAttachmentController` | GET `/attachment/{attachmentId}/download` |
| `listConversationAttachments` | `ListConversationAttachmentsController` | GET `/attachment/conversation/{convId}/attachments` |

### IocController → 3 controllers
| Method | New controller | Route |
|--------|---------------|-------|
| `listIocs` | `ListIocsController` | GET `/iocs` |
| `ingestEnrichedIoc` | `IngestEnrichedIocController` | POST `/iocs/enriched` |
| `updateIocEnrichment` | `UpdateIocEnrichmentController` | PATCH `/iocs/{obs_id}/enrich` |

## Pattern

Each new controller:
1. Is `final class` with `__invoke()`
2. Has the `#[Route()]` attribute from the original method
3. Has the `#[IsGranted()]` attribute from the original method
4. Injects only the same handler/service the original controller injected
5. Copies the OpenAPI annotations from the original method
6. The original multi-action controller is deleted

## Acceptance criteria
- [ ] 33 new `__invoke` controllers in `src/UI/Http/Communication/`
- [ ] 5 old multi-action controllers deleted
- [ ] All routes identical (verified by `php bin/console debug:router`)
- [ ] All tests pass unchanged
