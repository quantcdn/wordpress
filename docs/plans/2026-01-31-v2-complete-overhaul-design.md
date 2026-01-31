# QuantCDN WordPress Plugin v2.0 - Complete Overhaul Design

**Date:** 2026-01-31
**Status:** Approved
**Target:** v2.0.0

## Executive Summary

Complete rewrite of the QuantCDN WordPress plugin to deliver:
- Modern React-based admin UI matching QuantCDN brand quality
- Two operational modes: Static Site generation and CDN-Only
- Clean architecture for maintainability and testability
- Seamless migration path for existing v1 customers
- Foundation for future Search and Forms integrations

## Requirements

### Technical Requirements
- PHP 8.1+
- WordPress 6.0+
- React admin UI via @wordpress/scripts
- Composer for PHP dependencies
- PSR-4 autoloading

### Functional Requirements
- **Static Mode:** Full content sync to QuantCDN edge (posts, pages, taxonomies, media, assets, redirects)
- **CDN-Only Mode:** Cache purging and management without content sync
- Automatic sync/purge on content changes
- Manual batch operations
- WP-CLI support
- Migration wizard from v1

## Architecture

### Directory Structure

```
quant/
├── quant.php                    # Bootstrap, activation, hooks
├── composer.json                # PHP dependencies
├── package.json                 # JS/React build
├── src/
│   ├── Admin/                   # React admin app entry points
│   ├── Api/                     # API client (SDK-swap-ready)
│   │   ├── ClientInterface.php  # Contract for API operations
│   │   ├── QuantClient.php      # Current implementation
│   │   └── ContentApi.php       # Content operations
│   ├── Core/                    # Plugin core (settings, activation, hooks)
│   ├── Mode/                    # Static vs CDN-only mode logic
│   ├── Sync/                    # Content sync engine
│   ├── Integration/             # Future: Search, Forms
│   └── CLI/                     # WP-CLI commands
├── assets/
│   ├── src/                     # React source
│   │   ├── components/          # Shared UI components
│   │   ├── pages/               # Admin page components
│   │   ├── hooks/               # React hooks
│   │   └── store/               # State management
│   └── build/                   # Compiled assets
└── languages/                   # i18n
```

### Key Architectural Decisions

1. **Namespace:** `Quant\` for all PHP classes
2. **PSR-4 autoloading** via Composer
3. **Dependency injection** for testability
4. **Service container** pattern for dependency management
5. **REST API endpoints** for admin UI (no admin-ajax.php)
6. **Interface-based API client** for future SDK swap

## API Layer

### SDK-Swap-Ready Design

The current QuantCDN PHP SDK (`quantcdn/quant-php-client`) does not include v1 Content API support. The plugin will use a custom client implementing a clean interface, enabling easy swap when SDK support is added.

```php
namespace Quant\Api;

interface ContentApiInterface
{
    public function ping(): ApiResponse;
    public function publish(string $url, string $markup, array $meta = []): ApiResponse;
    public function unpublish(string $url): ApiResponse;
    public function uploadFile(string $path, string $localPath): ApiResponse;
    public function createRedirect(string $from, string $to, int $code = 301): ApiResponse;
    public function purge(string|array $paths): ApiResponse;
}
```

### Implementation

```php
namespace Quant\Api;

class QuantContentApi implements ContentApiInterface
{
    public function __construct(
        private readonly QuantCredentials $credentials,
        private readonly HttpClientInterface $http
    ) {}
}
```

### Future SDK Swap

```php
// Before (custom)
$container->bind(ContentApiInterface::class, QuantContentApi::class);

// After (SDK)
$container->bind(ContentApiInterface::class, SdkContentAdapter::class);
```

### Credentials

```php
class QuantCredentials
{
    public function __construct(
        public readonly string $organization,
        public readonly string $project,
        public readonly string $token,
        public readonly string $endpoint = 'https://api.quantcdn.io'
    ) {}
}
```

## Mode System

### Plugin Modes

```php
namespace Quant\Mode;

enum PluginMode: string
{
    case Static = 'static';
    case CdnOnly = 'cdn_only';
}
```

### Feature Matrix

| Feature | Static Mode | CDN-Only Mode |
|---------|-------------|---------------|
| Content sync (posts, pages, etc.) | Yes | No |
| Asset sync (media, theme files) | Yes | No |
| Redirect management | Yes | No |
| Batch seeding UI | Yes | No |
| Cache purge (manual) | Yes | Yes |
| Auto-purge on save | Yes | Yes |
| WP-CLI commands | Full set | Purge only |

### Mode Manager

```php
class ModeManager
{
    public function getMode(): PluginMode;
    public function isFeatureEnabled(Feature $feature): bool;
    public function setMode(PluginMode $mode): void;
}
```

## React Admin UI

### Page Structure

```
QuantCDN (top-level menu)
├── Dashboard        # Connection status, sync overview, quick actions
├── Content          # Sync status, content browser (Static mode only)
├── Cache            # Purge UI, auto-purge settings
├── Settings         # API credentials, mode selection, advanced
└── Tools            # Batch operations, migration, diagnostics
```

### Component Architecture

```
assets/src/
├── index.js                 # Entry point, router setup
├── api/
│   └── useQuantApi.js       # REST client hooks
├── components/
│   ├── Layout/              # Shell, sidebar, header
│   ├── StatusCard/          # Connection, sync status widgets
│   ├── DataTable/           # Content lists
│   ├── PurgeForm/           # Path input, bulk purge
│   └── ProgressTracker/     # Batch operation progress
├── pages/
│   ├── Dashboard.jsx
│   ├── Content.jsx
│   ├── Cache.jsx
│   ├── Settings.jsx
│   └── Tools.jsx
└── store/
    └── index.js             # @wordpress/data store
```

### UI Principles
- Use `@wordpress/components` as base
- Real-time status updates (no page refresh)
- Toast notifications for actions
- Proper loading states and error handling
- Responsive design
- Keyboard accessible

## Content Sync Engine

### Core Types

```php
namespace Quant\Sync;

enum SyncType: string
{
    case Post = 'post';
    case Page = 'page';
    case Taxonomy = 'taxonomy';
    case Archive = 'archive';
    case Media = 'media';
    case ThemeAsset = 'theme_asset';
    case Redirect = 'redirect';
    case CustomRoute = 'custom_route';
}

enum SyncAction: string
{
    case Publish = 'publish';
    case Unpublish = 'unpublish';
    case Purge = 'purge';
}

class SyncJob
{
    public function __construct(
        public readonly string $id,
        public readonly SyncType $type,
        public readonly int|string $objectId,
        public readonly SyncAction $action,
        public readonly array $meta = []
    ) {}
}
```

### Queue Interface

```php
interface SyncQueueInterface
{
    public function push(SyncJob $job): void;
    public function pushBatch(array $jobs): void;
    public function process(int $limit = 50): SyncResult;
    public function getStatus(): QueueStatus;
    public function clear(): void;
}
```

### Queue Backends
- **Database queue** (default): Custom table, reliable, survives restarts
- **In-memory queue**: For WP-CLI bulk operations

### Processing Triggers
- Immediate (on post save, if enabled)
- WP Cron (background processing)
- WP-CLI (manual bulk runs)
- Admin UI "Sync Now" button

## Database Schema

### Options (wp_options)

```php
'quant_version'          => '2.0.0',
'quant_mode'             => 'static',
'quant_credentials'      => [
    'organization' => '...',
    'project'      => '...',
    'token'        => '...',  // encrypted
    'endpoint'     => 'https://api.quantcdn.io',
],
'quant_static_config'    => [
    'custom_routes'   => [],
    'route_404'       => '/404',
    'domain_rewrites' => [],
],
'quant_sync_settings'    => [
    'auto_sync'       => true,
    'auto_purge'      => true,
    'sync_drafts'     => false,
],
'quant_schedule'         => [
    'enabled'  => false,
    'interval' => 'hourly',
    'types'    => ['post', 'page'],
],
'quant_migrated_from'    => '1.6.0',
'quant_migration_backup' => [...],
```

### Sync Queue Table

```sql
CREATE TABLE {prefix}quant_sync_queue (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    job_id VARCHAR(36) NOT NULL,
    type VARCHAR(20) NOT NULL,
    object_id VARCHAR(255) NOT NULL,
    action VARCHAR(20) NOT NULL,
    status VARCHAR(20) DEFAULT 'pending',
    attempts TINYINT DEFAULT 0,
    error_message TEXT NULL,
    created_at DATETIME NOT NULL,
    processed_at DATETIME NULL,
    INDEX idx_status (status),
    INDEX idx_type_object (type, object_id)
);
```

### Post Meta

```php
'_quant_sync_status'    => 'synced',  // synced, pending, failed, excluded
'_quant_last_sync'      => '2026-01-31T12:00:00Z',
'_quant_sync_error'     => null,
```

## WP-CLI Commands

### Core Commands (Both Modes)

```bash
wp quant status                    # Show connection status, mode, sync stats
wp quant ping                      # Test API connection
wp quant purge <path>              # Purge specific path
wp quant purge --all               # Purge entire cache
wp quant purge --paths=file.txt    # Purge paths from file
```

### Static Mode Commands

```bash
wp quant sync                      # Process pending queue
wp quant sync --type=post          # Sync all posts
wp quant sync --type=page          # Sync all pages
wp quant sync --post=123           # Sync specific post
wp quant sync --all                # Full site seed
wp quant queue status              # Show queue stats
wp quant queue clear               # Clear pending jobs
wp quant queue retry               # Retry failed jobs
wp quant unpublish <path>          # Remove from CDN
wp quant unpublish --post=123      # Unpublish specific post
```

## Migration Path

### v1 to v2 Migration Wizard

On first load after update, existing v1 users see a migration wizard.

**Flow:**
1. Detect v1 settings
2. Display found configuration
3. User selects mode (Static recommended for existing users)
4. Migrate settings automatically
5. Confirm and complete

### Settings Migration Map

| v1 Option | v2 Location |
|-----------|-------------|
| `quant_settings` (API creds) | `quant_credentials` |
| `quant_seed` (custom routes, 404) | `quant_static_config` |
| `quant_cron_settings` | `quant_schedule` |
| Batch queue state | Cleared (fresh start) |

### Safety Measures
- v1 options preserved with `_quant_v1_backup_*` prefix
- Rollback possible if issues
- Admin notice if migration incomplete

## Implementation Phases

### Phase 1a - Foundation
- Plugin scaffold with Composer/PSR-4
- Service container and dependency injection
- API client with interface abstraction
- Settings storage and credentials
- React app shell with @wordpress/scripts
- Basic routing and layout components

### Phase 1b - CDN-Only Mode
- Cache purge API integration
- Purge UI (manual paths, bulk)
- Auto-purge on post save hooks
- WP-CLI purge commands

### Phase 1c - Static Mode Core
- Sync queue table and processing
- Content sync (posts, pages, CPTs)
- Taxonomy and archive sync
- Media and theme asset sync
- Redirect sync
- Hook subscribers for auto-sync

### Phase 1d - Static Mode UI
- Content browser with sync status
- Batch operations UI (seed, resync)
- Progress tracking and notifications
- WP-CLI sync commands

### Phase 1e - Migration & Polish
- v1 migration wizard
- Onboarding flow (mode selection, credentials)
- Error handling and diagnostics
- Documentation

### Phase 2 (Future)
- Quant Search integration (Typesense + AI variants)
- Quant Forms integration
- Analytics dashboard

## Out of Scope for v2.0

- Quant Search integration (Phase 2)
- Quant Forms integration (Phase 2)
- Analytics dashboard (Phase 2)
- Multi-site network admin (evaluate post-launch)
- Gutenberg block for content status (nice-to-have)

## Success Criteria

1. **Performance:** UI feels instant, batch operations handle 10k+ items
2. **Reliability:** Zero data loss during migration, robust error recovery
3. **UX:** Onboarding complete in under 2 minutes, clear sync status at all times
4. **Maintainability:** Full test coverage for API layer, documented architecture
5. **Brand:** UI quality matches QuantCDN dashboard

## Open Questions

1. Should token be encrypted at rest? (Recommend: yes, using wp_salt)
2. Custom table prefix for multisite? (Recommend: per-site tables)
3. Minimum batch size before showing progress UI? (Recommend: 10 items)

## Appendix: Current v1 Analysis

The current plugin (v1.6.0) has:
- ~4,800 lines of PHP across 33+ files
- Bundled (not Composer) batch processing library
- WordPress Settings API with PHP-rendered tabs
- Manual API client (645 lines)
- 13 batch types for different content
- No tests
- jQuery-based AJAX for cache purge

Key improvements in v2:
- Modern dependency management (Composer)
- Clean separation of concerns
- Testable architecture
- React UI with real-time updates
- Queue-based sync with retry logic
- Interface-based API client for SDK migration
