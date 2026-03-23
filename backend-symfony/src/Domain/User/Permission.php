<?php

declare(strict_types=1);

namespace App\Domain\User;

/**
 * Fine-grained permissions for RBAC.
 *
 * These complement Symfony roles (ROLE_USER, ROLE_ADMIN) with
 * resource-level access control.
 *
 * Admins implicitly have ALL permissions.
 * Regular users have permissions assigned via the permissions JSON column.
 */
enum Permission: string
{
    // Conversations
    case CONVERSATION_READ = 'conversation:read';
    case CONVERSATION_WRITE = 'conversation:write';
    case CONVERSATION_CLOSE = 'conversation:close';

    // IOCs
    case IOC_READ = 'ioc:read';
    case IOC_EXPORT = 'ioc:export';

    // Replies
    case REPLY_GENERATE = 'reply:generate';

    // Campaigns
    case CAMPAIGN_READ = 'campaign:read';
    case CAMPAIGN_HUNT = 'campaign:hunt';
    case CAMPAIGN_PROMOTE = 'campaign:promote';

    // System
    case MONITORING_READ = 'monitoring:read';
    case AUDIT_READ = 'audit:read';
    case CONFIG_WRITE = 'config:write';
}
