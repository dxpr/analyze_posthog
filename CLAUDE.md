# Analyze PostHog - Implementation Plan

## Overview

Drupal module that displays PostHog analytics data (pageviews, unique visitors, sessions, bounce rate, time on page) for entities with URL paths, integrated into the Analyze tab framework. Queries PostHog's HogQL API to read analytics data — complementary to the existing `posthog` contrib module which only writes/captures events.

## Architecture Decision: Authentication

### Chosen approach: Personal API Key

PostHog has two key types:
- **Project API key** (prefix `phc_`) — write-only, used by the existing `posthog` module for event capture
- **Personal API key** (prefix `phx_`) — read access to analytics data via the Query API

This module uses a **Personal API key** because:
1. The existing `posthog` module's project API key cannot query analytics data
2. Personal API keys provide read access to HogQL, events, and insights
3. No OAuth flow needed — just paste the key into settings

### Why independent of the `posthog` contrib module

The existing `posthog` module handles event ingestion (writing). This module handles analytics querying (reading). They use different API keys, different endpoints, and different directions. Making them independent avoids coupling and allows sites to use the analyzer without the tracking module (e.g., if PostHog JS is loaded via a tag manager instead).

### API endpoint

```
POST https://{host}/api/projects/{project_id}/query/
Authorization: Bearer {personal_api_key}
Content-Type: application/json

{
  "query": {
    "kind": "HogQLQuery",
    "query": "SELECT ... FROM events WHERE ..."
  }
}
```

The HogQL endpoint provides full SQL-like access to all event data. The legacy `/api/projects/{id}/insights/trend/` endpoint is blocked for personal API keys, but HogQL is more powerful and flexible.

### Token storage

Personal API key stored in Drupal config (not State API) — it's a long-lived credential entered once, similar to how the Search Console module stores client_id/client_secret. No token refresh needed.

## PostHog Data Model

### Available event properties (confirmed via live API testing)

All metrics derive from `$pageview` and `$pageleave` autocapture events. Key properties on `$pageview`:

| Property | Type | Example | Use |
|----------|------|---------|-----|
| `$pathname` | string | `/pricing` | **Entity URL matching** |
| `$current_url` | string | `https://dxpr.com/pricing` | Full URL reference |
| `$referrer` | string | `https://www.google.com/` | Referrer URL |
| `$referring_domain` | string | `www.google.com` | Referrer dimension |
| `$browser` | string | `Chrome` | Browser dimension |
| `$os` | string | `Mac OS X` | OS dimension |
| `$device_type` | string | `Desktop`, `Mobile` | Device dimension |
| `$geoip_country_name` | string | `United States` | Country dimension |
| `$geoip_country_code` | string | `US` | Country code |
| `$geoip_city_name` | string | `New York` | City (available but not in initial scope) |
| `$session_id` | string | UUID | Session counting |
| `$session_entry_pathname` | string | `/pricing` | Entry page (for bounce rate) |
| `$session_entry_referrer` | string | URL | Session entry referrer |
| `title` | string | `Pricing | DXPR` | Page title |
| `distinct_id` | string | email or UUID | Unique visitor counting |

### Queryable metrics (confirmed working)

| Metric | HogQL | Confirmed value |
|--------|-------|-----------------|
| Pageviews | `count()` where `event = '$pageview'` | 450 for /pricing |
| Unique visitors | `count(DISTINCT distinct_id)` | 219 |
| Sessions | `count(DISTINCT properties.$session_id)` | 238 |
| Bounce rate | Sessions with 1 pageview / total sessions | 11.3% |
| Avg time on page | JOIN `$pageview` → `$pageleave` timestamps | 155 seconds |
| Period comparison | UNION ALL with previous date range | Works |

### Dimension breakdowns (confirmed working)

| Dimension | Property | Example data |
|-----------|----------|-------------|
| Referrers | `$referring_domain` | google.com: 135, $direct: 130, drupal.org: 66 |
| Countries | `$geoip_country_name` | US: 69, India: 51, France: 35 |
| Devices | `$device_type` | Desktop: 424, Mobile: 26 |
| Browsers | `$browser` | Chrome, Firefox, Safari |
| Pages | `$pathname` | /pricing: 450, /: 521 (sitewide only) |

### Design decision: Single dimension per view

Following Google Search Console's UX pattern (and Jakob Nielsen's H8 — minimalist design), each dimension tab shows ONE dimension aggregated across all others. No sub-dimensions. Country filter narrows results but doesn't add a column.

## File Structure

```
analyze_posthog/
├── CLAUDE.md
├── .github/
│   └── workflows/
│       └── review.yml
├── .gitignore
├── analyze_posthog.info.yml
├── analyze_posthog.install
├── analyze_posthog.libraries.yml
├── analyze_posthog.links.menu.yml
├── analyze_posthog.links.task.yml
├── analyze_posthog.module
├── analyze_posthog.permissions.yml
├── analyze_posthog.routing.yml
├── analyze_posthog.services.yml
├── composer.json
├── config/
│   ├── install/
│   │   └── analyze_posthog.settings.yml
│   └── schema/
│       └── analyze_posthog.schema.yml
├── css/
│   └── analyze_posthog.css
├── docker-compose.yml
├── phpcs.xml
├── scripts/
│   ├── prepare-drupal-lint.sh
│   ├── run-drupal-check.sh
│   ├── run-drupal-lint.sh
│   └── run-drupal-lint-auto-fix.sh
└── src/
    ├── Controller/
    │   └── ReportController.php
    ├── Drush/
    │   └── Commands/
    │       └── PostHogCommands.php
    ├── Form/
    │   ├── ReportFilterForm.php
    │   └── PostHogSettingsForm.php
    ├── Plugin/
    │   └── Analyze/
    │       └── PostHog.php
    └── Service/
        ├── PostHogClient.php
        └── ReportBuilder.php
```

## Consistency with analyze_search_console

This module follows the exact same architecture as analyze_search_console. Patterns to replicate:

### Shared ReportBuilder pattern

`ReportBuilder` is the single source of truth for all rendering logic. Both the sitewide `ReportController`, entity-level `PostHog` plugin, and Drush commands use it. Public methods:

- `buildKpiTable(array $data, string $caption): array` — 4-column KPI summary with color-coded change indicators
- `formatKpiCell(string $value, ?array $change, bool $positiveIsGood): array` — single cell with value + arrow
- `formatPositionChange(?float $change): string` — not applicable to PostHog (no position metric), but pattern carries over for percentage changes
- `formatStatusBadge(string $status): string` — New/Lost badges
- `enrichWithComparison(array $current, array $prev): array` — adds status/change to rows
- `buildDataTable(array $rows, string $dimension, ?Request $request, int $days): array` — sortable table
- `buildDateCaption(int $days): string` — "Mar 4 – Apr 1, 2026 compared to Feb 5 – Mar 3"

### Shared ReportFilterForm pattern

Single form class used by both sitewide and entity reports. Accepts:
- `$action_url` — entity route URL (empty = sitewide route)
- `$show_country` — TRUE for sitewide (shows country dropdown), FALSE for entity

Uses Drupal's form API with:
- `#method => 'get'` — filters in URL
- `#attributes['class'][] = 'views-exposed-form'` — matches core admin/content layout
- `#wrapper_attributes['class'] = ['views-exposed-form__item']` on each field
- `#attached['library'][] = 'analyze_posthog/report'` — flex layout CSS
- Hidden form_build_id/form_token/form_id (`#access => FALSE`)
- Reset link alongside Filter submit button

### Data pipeline

Same flow as search_console: **Fetch → Enrich → Filter → Render**

1. **PostHogClient** fetches current + previous period data via HogQL
2. **ReportBuilder::enrichWithComparison()** adds status/change columns
3. Controller/Plugin applies text search + status filters
4. **ReportBuilder::buildDataTable()** renders the result

## Routing

```yaml
analyze_posthog.settings:
  path: '/admin/config/analyze/posthog'
  defaults:
    _form: 'Drupal\analyze_posthog\Form\PostHogSettingsForm'
    _title: 'PostHog Analytics Settings'
  requirements:
    _permission: 'administer analyze settings'

analyze_posthog.report:
  path: '/admin/reports/posthog'
  defaults:
    _controller: 'Drupal\analyze_posthog\Controller\ReportController::report'
    _title: 'Site Analytics'
  requirements:
    _permission: 'access posthog analytics'
```

## Menu & Task Links

```yaml
# analyze_posthog.links.menu.yml
analyze_posthog.settings:
  title: 'PostHog Analytics'
  description: 'Configure PostHog analytics integration for Analyze.'
  parent: ai.admin_settings
  route_name: analyze_posthog.settings
  weight: 6

analyze_posthog.report:
  title: 'Site Analytics'
  description: 'View PostHog website analytics.'
  parent: system.admin_reports
  route_name: analyze_posthog.report
  weight: 5
```

```yaml
# analyze_posthog.links.task.yml
analyze_posthog.settings:
  route_name: analyze_posthog.settings
  title: 'Settings'
  base_route: analyze_posthog.settings

analyze_posthog.report_tab:
  route_name: analyze_posthog.report
  title: 'Report'
  base_route: analyze_posthog.settings
```

## Permissions

```yaml
access posthog analytics:
  title: 'Access PostHog analytics'
  description: 'View PostHog analytics data in the Analyze tab and sitewide report.'
```

## Configuration

### Default config (`config/install/analyze_posthog.settings.yml`)

```yaml
personal_api_key: ''
host: ''
project_id: ''
date_range: 28
cache_ttl: 21600
```

### Schema

```yaml
analyze_posthog.settings:
  type: config_object
  label: 'PostHog Analytics settings'
  mapping:
    personal_api_key:
      type: string
      label: 'Personal API key'
    host:
      type: string
      label: 'PostHog host URL'
    project_id:
      type: string
      label: 'Project ID'
    date_range:
      type: integer
      label: 'Default date range in days'
    cache_ttl:
      type: integer
      label: 'Cache TTL in seconds'
```

## Services

```yaml
services:
  analyze_posthog.client:
    class: Drupal\analyze_posthog\Service\PostHogClient
    arguments:
      - '@config.factory'
      - '@path_alias.manager'
      - '@cache.default'
      - '@logger.factory'
      - '@http_client'

  analyze_posthog.report_builder:
    class: Drupal\analyze_posthog\Service\ReportBuilder
```

## Implementation Steps

### Phase 1: Module skeleton and authentication

1. **Create `analyze_posthog.info.yml`**
   ```yaml
   name: 'Analyze PostHog'
   type: module
   description: 'Displays PostHog analytics data (pageviews, visitors, sessions) for entities with URL paths.'
   package: Analyze
   core_version_requirement: ^10.3 || ^11
   dependencies:
     - analyze:analyze (>=1.1.0)
   configure: analyze_posthog.settings
   ```

2. **Create `composer.json`**
   - No external PHP dependencies (uses Drupal's `http_client` / Guzzle for API calls)
   - `type: drupal-module`

3. **Create config schema and default config** (see Configuration section above)

4. **Create permissions, routing, menu links, task links** (see sections above)

### Phase 2: Service layer — PostHogClient

5. **Create `PostHogClient` service (`src/Service/PostHogClient.php`)**

   Responsibilities:
   - Execute HogQL queries against PostHog API
   - Map entity URLs to `$pathname` property values
   - Cache results using Drupal's cache API
   - Provide typed methods matching the data pipeline

   **Core query method:**
   ```php
   protected function hogqlQuery(string $query): ?array
   ```
   Makes POST to `{host}/api/projects/{project_id}/query/` with HogQL body. Returns `['columns' => [...], 'results' => [...]]` or NULL on error.

   **Public methods:**

   - `isConfigured(): bool` — check API key, host, project_id are set
   - `testConnection(): bool` — execute a lightweight `SELECT 1` query
   - `getEntityUrl(EntityInterface $entity): ?string` — resolve entity to `$pathname` value

   **Per-entity metrics (for entity plugin):**

   - `getPageMetrics(string $pathname, int $days): ?array` — returns `['pageviews', 'visitors', 'sessions', 'bounce_rate', 'avg_time_on_page']`
   - `getPageMetricsWithComparison(string $pathname, int $days): ?array` — returns `['current' => [...], 'previous' => [...], 'change' => [...]]`
   - `getDimensionData(string $pathname, int $days, string $dimension, int $limit): array` — per-dimension breakdown for a specific page

   **Sitewide metrics (for sitewide report):**

   - `getSitewideMetrics(int $days, string $country = ''): ?array` — aggregate metrics across all pages
   - `getSitewideMetricsWithComparison(int $days, string $country = ''): ?array` — with period comparison
   - `getSitewideDimensionData(int $days, string $dimension, int $limit, string $country = ''): array` — sitewide dimension breakdown

   **Previous period methods (for enrichment):**

   - `getPreviousPeriodDimensionData(string $pathname, int $days, string $dimension, int $limit): array`
   - `getSitewidePrevDimensionData(int $days, string $dimension, int $limit, string $country = ''): array`

   **HogQL query templates:**

   Per-entity pageviews:
   ```sql
   SELECT count() as pageviews,
          count(DISTINCT distinct_id) as visitors,
          count(DISTINCT properties.$session_id) as sessions
   FROM events
   WHERE event = '$pageview'
     AND properties.$pathname = '{pathname}'
     AND timestamp > now() - interval {days} day
   ```

   Bounce rate (per entity):
   ```sql
   SELECT count(DISTINCT session_id) as bounced
   FROM (
     SELECT properties.$session_id as session_id, count() as pv
     FROM events
     WHERE event = '$pageview'
       AND properties.$session_id IN (
         SELECT DISTINCT properties.$session_id
         FROM events
         WHERE event = '$pageview'
           AND properties.$pathname = '{pathname}'
           AND timestamp > now() - interval {days} day
       )
       AND timestamp > now() - interval {days} day
     GROUP BY session_id
     HAVING pv = 1
   )
   ```

   Avg time on page (per entity):
   ```sql
   SELECT avg(dateDiff('second', pv.timestamp, pl.timestamp))
   FROM events pv
   JOIN events pl
     ON pv.properties.$session_id = pl.properties.$session_id
     AND pv.properties.$pageview_id = pl.properties.$prev_pageview_id
   WHERE pv.event = '$pageview'
     AND pl.event = '$pageleave'
     AND pv.properties.$pathname = '{pathname}'
     AND pv.timestamp > now() - interval {days} day
   ```

   Dimension breakdown:
   ```sql
   SELECT properties.{dimension_property} as key,
          count() as pageviews,
          count(DISTINCT distinct_id) as visitors
   FROM events
   WHERE event = '$pageview'
     AND properties.$pathname = '{pathname}'
     AND timestamp > now() - interval {days} day
   GROUP BY key
   ORDER BY pageviews DESC
   LIMIT {limit}
   ```

   **Dimension property mapping:**
   | Dimension | Property |
   |-----------|----------|
   | referrer | `$referring_domain` |
   | country | `$geoip_country_name` |
   | device | `$device_type` |
   | browser | `$browser` |
   | page | `$pathname` (sitewide only) |

   **Caching:**
   - Cache bin: `cache.default`
   - Cache key: `analyze_posthog:{pathname_hash}:{metric_type}:{days}`
   - Cache tags: `['analyze_posthog']`
   - Default TTL: 6 hours (configurable)

   **Comparison calculation** (reuse pattern from search_console):
   ```php
   protected function calculateChange(array $current, ?array $previous): ?array
   ```
   For pageviews/visitors/sessions: percentage change. For bounce rate: absolute pp change.

### Phase 3: ReportBuilder service

6. **Create `ReportBuilder` service (`src/Service/ReportBuilder.php`)**

   Follows the exact same pattern as analyze_search_console's ReportBuilder. Key differences:

   **KPI columns:**
   | Label | Format | Positive is good? |
   |-------|--------|-------------------|
   | Pageviews | integer | Yes |
   | Unique visitors | integer | Yes |
   | Sessions | integer | Yes |
   | Bounce rate | percentage | **No** (lower = better) |

   Note: Avg time on page shown in entity summary but not in sitewide KPI (not meaningful as an aggregate).

   **Data table columns:**
   | Column | Notes |
   |--------|-------|
   | Dimension key | Referrer, country name, device type, browser, or page path |
   | Change | Percentage change in pageviews vs previous period |
   | Pageviews | Total count |
   | Visitors | Unique visitor count |
   | Bounce rate | Per-dimension (if feasible, otherwise omit) |

   **Methods** (same signatures as search_console where applicable):
   - `buildKpiTable(array $data, string $caption): array`
   - `formatKpiCell(string $value, ?array $change, bool $positiveIsGood): array`
   - `formatStatusBadge(string $status): string`
   - `enrichWithComparison(array $current, array $prev): array` — status based on pageview change (not position)
   - `buildDataTable(array $rows, string $dimension, ?Request $request, int $days): array`
   - `buildDateCaption(int $days): string`
   - `getDimensionLabel(string $dimension): string`
   - `getDimensionPluralLabel(string $dimension): string`

   **Status classification** (different from search_console which uses position):
   - `new` — present in current period, absent in previous
   - `lost` — absent in current, present in previous
   - `up` — pageviews increased > 10%
   - `down` — pageviews decreased > 10%
   - `stable` — within 10% change

### Phase 4: Settings form

7. **Create `PostHogSettingsForm` (`src/Form/PostHogSettingsForm.php`)**

   Extends `ConfigFormBase`.

   **Fields:**

   - **Setup instructions**: Numbered steps:
     1. Log in to your PostHog instance
     2. Go to Settings → Personal API Keys
     3. Create a new key with read access
     4. Copy the host URL and project ID from your project settings
     5. Paste all three values below

   - **PostHog host URL**: textfield. Example: `https://us.posthog.com` or `https://posthog.example.com`. Validate: must start with `https://`.

   - **Project ID**: textfield. Found in PostHog → Settings → Project. Usually a number.

   - **Personal API key**: textfield. Starts with `phx_`. Store in config.

   - **Default date range**: select (7, 14, 28, 90 days).

   - **Cache TTL**: select (1h, 6h, 12h, 24h).

   - **Connection status**: on submit, call `testConnection()` and display result.

   **Auto-detection:** After key is validated, attempt to auto-detect project_id by querying `/api/projects/` and pre-selecting the first project.

### Phase 5: ReportFilterForm

8. **Create `ReportFilterForm` (`src/Form/ReportFilterForm.php`)**

   Near-identical to search_console's version. Differences:

   **Fields:**
   | Field | Options | Notes |
   |-------|---------|-------|
   | dimension | referrer, country, device, browser, page (sitewide only) | Different dimensions than search_console |
   | days | 7, 14, 28, 90 | Same |
   | country | Dynamic from API | Only when `$show_country=TRUE` and dimension != 'country' |
   | status | all, up, down, new, lost | Same |
   | q | textfield | Same |
   | actions | Filter + Reset | Same |

   No `search_type` field (PostHog doesn't differentiate web/image/video search).

   **CSS pattern:** Same `views-exposed-form` + `views-exposed-form__item` + custom flex CSS.

### Phase 6: Sitewide report controller

9. **Create `ReportController` (`src/Controller/ReportController.php`)**

   Route: `/admin/reports/posthog`

   **Build order** (matches search_console exactly):
   1. **header** (#weight -15) — "Data from PostHog for **{host}**"
   2. **filters** (#weight -10) — ReportFilterForm via `formBuilder()->getForm()`
   3. **kpi** (#weight -7) — `reportBuilder->buildKpiTable(getSitewideMetricsWithComparison(...))`
   4. **table** — `reportBuilder->buildDataTable($pagedRows, $dimension, $request, $days)`
   5. **pager** (#weight 50)
   6. **source** (#weight 100) — "Open in PostHog" button linking to PostHog web analytics dashboard

   **Data pipeline** (identical pattern):
   ```
   fetch current + previous → enrichWithComparison → text filter → status filter → paginate → buildDataTable
   ```

### Phase 7: Analyze plugin

10. **Create `PostHog` plugin (`src/Plugin/Analyze/PostHog.php`)**

    ```php
    @Analyze(
      id = "posthog_analytics",
      label = @Translation("PostHog Analytics"),
      description = @Translation("Displays PostHog pageview analytics for entities with URL paths.")
    )
    ```

    **Constructor injections:** `HelperInterface`, `AccountProxyInterface`, `ConfigFactoryInterface`, `PostHogClient`, `ReportBuilder`, `RequestStack`, `FormBuilderInterface`

    **renderSummary():**
    ```
    analyze_table with 5 rows:
    - Pageviews: 450 (+12.3%)
    - Unique visitors: 219 (+8.1%)
    - Sessions: 238 (+5.4%)
    - Bounce rate: 11.3% (-2.1%)
    - Avg time on page: 2:35
    ```

    **renderFullReport():**
    Same pattern as search_console:
    1. Filters via `$this->formBuilder->getForm(ReportFilterForm::class, $actionUrl, FALSE)`
    2. KPI table via `reportBuilder->buildKpiTable()`
    3. Data table via `reportBuilder->buildDataTable()`
    4. Pager
    5. "View in PostHog" source link

    **getFullReportUrl():** Return URL only if data exists.

    **extraSummaryLinks():** Link to PostHog web analytics for this page.

### Phase 8: Drush commands

11. **Create `PostHogCommands` (`src/Drush/Commands/PostHogCommands.php`)**

    Follows search_console pattern exactly. Reuses `ReportBuilder` for enrichment and labels.

    **Commands:**

    **`analyze:posthog:status` (`analyze-ph-status`)**
    - No arguments
    - Output: host, project ID, API key status, connection test
    - Calls: `testConnection()`

    **`analyze:posthog:query` (`analyze-ph-query`)**
    - Argument: `url` (path or full URL)
    - Options: `--days`, `--dimension`, `--status`, `--search`, `--limit`
    - Output: KPI summary + dimension table with change/status
    - Matches entity-level report

    **`analyze:posthog:report` (`analyze-ph-report`)**
    - Options: `--days`, `--dimension`, `--country`, `--status`, `--search`, `--limit`
    - Output: sitewide KPI + dimension table
    - Matches sitewide report

    **`analyze:posthog:cache-clear` (`analyze-ph-cc`)**
    - Invalidates `analyze_posthog` cache tags

### Phase 9: CSS and library

12. **Create `css/analyze_posthog.css`**

    Same styles as search_console (can be identical — uses same CSS class names):
    ```css
    /* Filter form inline layout */
    form.views-exposed-form.analyze-posthog-report-filter {
      display: flex;
      flex-wrap: wrap;
      align-items: flex-end;
    }
    form.views-exposed-form.analyze-posthog-report-filter > .form-item {
      margin: 12px 8px 0 0;
    }
    form.views-exposed-form.analyze-posthog-report-filter > .form-actions {
      margin: 12px 0 0 0;
    }

    .ph-kpi-value { font-size: 1.2em; }
    .ph-change--up { color: #18794e; }
    .ph-change--down { color: #c2382b; }
    .ph-change--neutral { color: #666; }
    .ph-code-block { ... }
    ```

13. **Create `analyze_posthog.libraries.yml`**
    ```yaml
    report:
      css:
        component:
          css/analyze_posthog.css: {}
    ```

### Phase 10: Install/uninstall hooks

14. **Create `analyze_posthog.install`**
    - `hook_install()`: Check if API key is configured; if not, show warning with link to settings
    - `hook_uninstall()`: Delete config

### Phase 11: Module file

15. **Create `analyze_posthog.module`**
    - Minimal — only `hook_help()` if needed

### Phase 12: GitHub Actions and CI

16. **Create `.github/workflows/review.yml`**

    Identical structure to search_console:
    ```yaml
    name: Review
    on: [pull_request]
    env:
      TARGET_DRUPAL_CORE_VERSION: 11
    jobs:
      drupal-lint:
        runs-on: ubicloud-standard-4
        timeout-minutes: 60
        steps:
        - uses: actions/checkout@v6
        - name: Lint Drupal
          run: docker compose --profile lint run drupal-lint
      drupal-check:
        runs-on: ubicloud-standard-4
        timeout-minutes: 60
        steps:
        - uses: actions/checkout@v6
        - name: Check Drupal compatibility
          run: docker compose --profile lint run drupal-check
    ```

17. **Create `docker-compose.yml`** — identical to search_console

18. **Create `phpcs.xml`** — identical to search_console

19. **Create CI scripts in `scripts/`**
    - Copy from search_console, change `--ignore` paths and `phpstan.neon` module name
    - `run-drupal-check.sh`: Do NOT require `google/apiclient` — this module has no Composer dependencies beyond Drupal core
    - Symlink path: `web/modules/contrib/analyze_posthog`

20. **Create `.gitignore`** — identical to search_console

## Metrics Display Format

### Summary tab (analyze_table)

| Label | Value Format | Comparison Format | Example |
|-------|-------------|-------------------|---------|
| Pageviews | integer, number_format | % change | `450 (+12.3%)` |
| Unique visitors | integer, number_format | % change | `219 (+8.1%)` |
| Sessions | integer, number_format | % change | `238 (+5.4%)` |
| Bounce rate | percentage, 1 decimal | absolute pp change | `11.3% (-2.1%)` |
| Avg time on page | mm:ss format | absolute change | `2:35 (+0:12)` |

### KPI cards (sitewide + entity full report)

Same as summary but in horizontal table format with color-coded arrows.
Bounce rate uses inverted color logic (lower = better = green).

### Data table (full report)

**Dimension: Referrers (default for sitewide)**

| Referrer | Change | Pageviews | Visitors | Status |
|----------|--------|-----------|----------|--------|
| google.com | ▲ 15.2% | 135 | 98 | up |
| $direct | ▼ 8.1% | 130 | 112 | down |
| drupal.org | – | 66 | 45 | new |

**Dimension: Countries**

| Country | Change | Pageviews | Visitors | Status |
|---------|--------|-----------|----------|--------|
| United States | ▲ 5.3% | 69 | 38 | up |

**Dimension: Devices**

| Device | Change | Pageviews | Visitors | Status |
|--------|--------|-----------|----------|--------|
| Desktop | ▼ 3.1% | 424 | 205 | stable |
| Mobile | ▲ 22.0% | 26 | 14 | up |

**Dimension: Browsers**

| Browser | Change | Pageviews | Visitors | Status |
|---------|--------|-----------|----------|--------|
| Chrome | ... | ... | ... | ... |

**Dimension: Pages (sitewide only)**

| Page | Change | Pageviews | Visitors | Status |
|------|--------|-----------|----------|--------|
| / | ▲ 8.2% | 521 | 383 | up |
| /pricing | ▼ 12.1% | 450 | 219 | down |

Sorted by pageviews descending. Caption includes comparison dates.

## Error Handling

| Scenario | Message |
|----------|---------|
| API not configured | "PostHog analytics is not configured. [Configure settings]" |
| Invalid API key | "Could not authenticate with PostHog. Check your API key. [Configure settings]" |
| No data for entity | "No analytics data available for this page yet." |
| Zero data, new setup | "PostHog is collecting data for your site. This usually takes a few hours." |
| Entity has no URL | "This entity does not have a URL path." |
| API error | "PostHog API error. Please try again later." (log full error) |

## Caching Strategy

- **Cache bin:** `cache.default`
- **Cache key:** `analyze_posthog:{host_hash}:{pathname_hash}:{metric_type}:{days}`
- **Cache tags:** `['analyze_posthog']`
- **Default TTL:** 6 hours (configurable: 1h, 6h, 12h, 24h)
- **Rationale:** PostHog data updates more frequently than Search Console (near real-time) but caching prevents excessive API calls. 6h is a reasonable balance.

## Dependencies

- `analyze:analyze (>=1.1.0)` — plugin framework
- `drupal:path_alias` — for resolving entity URLs (core, always available)
- No external Composer dependencies — uses Drupal's built-in `http_client` (Guzzle)
- `drush/drush: ^12 || ^13` — Drush commands (suggest, not hard requirement)

Note: This module does NOT depend on the `posthog` contrib module. They are complementary but independent.

## CI Checklist

Before merging any PR:

- [ ] **PHPCompatibility**: PHP 8.3+
- [ ] **Drupal coding standard**: No PHPCS violations
- [ ] **DrupalPractice standard**: No PHPCS violations
- [ ] **PHPStan level 5**: No static analysis errors against Drupal 11
- [ ] **No vendor committed**: `vendor/` and `composer.lock` in `.gitignore`

Run checks locally:
```bash
docker compose --profile lint run drupal-lint
docker compose --profile lint run drupal-check
docker compose --profile lint run drupal-lint-auto-fix
```

## Key Differences from analyze_search_console

| Aspect | Search Console | PostHog |
|--------|---------------|---------|
| Authentication | OAuth2 (client_id + secret + consent flow) | Personal API key (paste and done) |
| API | Google Search Console API (REST) | PostHog HogQL Query API (SQL-like) |
| Token storage | State API (tokens refresh) | Config (key is permanent) |
| Metrics | Clicks, impressions, CTR, position | Pageviews, visitors, sessions, bounce rate, time on page |
| Dimensions | query, page, country, device | referrer, country, device, browser, page |
| External dependencies | google/apiclient ^2.19 | None (uses Drupal http_client) |
| Filter: search_type | Yes (web/image/video/news) | No (not applicable) |
| Filter: country | Yes (sitewide only) | Yes (sitewide only) |
| Status classification | Based on position change | Based on pageview change |
| Data freshness | 2-3 day delay (finalized) | Near real-time |
| Settings complexity | OAuth consent screen + property detection | 3 fields: host, project_id, api_key |
