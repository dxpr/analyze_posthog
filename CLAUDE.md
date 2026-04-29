# Analyze PostHog: Conversion Attribution Implementation Plan

## Context

The pageview analytics portion of this module is fully implemented and working: KPIs (pageviews, visitors, sessions, bounce rate), dimension breakdowns (referrer, country, device, browser, page), comparison periods, sitewide and entity-level reports, Drush commands, filters, and CI. All code passes PHPCS and PHPStan level 5.

This plan covers the **next major feature**: per-content conversion attribution, connecting page visits to business outcomes.

## Why This Matters

Every analytics tool shows pageview counts. PostHog's differentiator is that it captures both pageviews AND custom business events (signups, purchases, form submissions) in the same data store with the same session IDs. This module can answer a question no other Drupal analytics integration can: **"Did this page help convert anyone?"**

## The Core Problem for a Contrib Module

Different sites have different conversion events:
- SaaS: `subscription_purchased`, `trial_started`
- E-commerce: `order_completed`, `add_to_cart`
- Lead gen: `webform_submission`, `contact_form_sent`
- Publisher: `newsletter_signup`, `paywall_unlocked`

The module cannot hardcode event names. Admins must define their own conversion goals.

## Architecture

### Conversion Goals: Config-Based, Not Entity-Based

Goals are stored as a list within `analyze_posthog.settings`, not as separate config entities. Rationale: most sites have 2-5 goals, not hundreds. A list is simpler to manage, export, and doesn't require entity CRUD scaffolding.

```yaml
# config/install/analyze_posthog.settings.yml (additions)
conversion_goals: []
```

Each goal in the list:
```yaml
- id: signup           # Machine name, used in cache keys
  label: 'Signup'      # Human-readable, shown in reports
  event: 'user_registered'  # PostHog event name (exact match)
  value: 0             # Fixed monetary value per conversion (0 = no revenue)
  value_property: ''   # OR: read value from event property (e.g., 'amount')
  currency: 'USD'      # ISO 4217 currency code
```

The `value` vs `value_property` distinction:
- `value: 29` + `value_property: ''` → every conversion of this goal = $29 (e.g., fixed-price subscription)
- `value: 0` + `value_property: 'amount'` → read revenue from `properties.amount` on each event (e.g., variable-price orders)
- `value: 0` + `value_property: ''` → no revenue tracking, count only (e.g., newsletter signup)

### Config Schema Addition

```yaml
# config/schema/analyze_posthog.schema.yml (additions)
analyze_posthog.conversion_goal:
  type: mapping
  label: 'Conversion goal'
  mapping:
    id:
      type: string
      label: 'Machine name'
    label:
      type: string
      label: 'Human-readable label'
    event:
      type: string
      label: 'PostHog event name'
    value:
      type: float
      label: 'Fixed monetary value per conversion'
    value_property:
      type: string
      label: 'Event property containing monetary value'
    currency:
      type: string
      label: 'Currency code (ISO 4217)'

# Add to analyze_posthog.settings mapping:
    conversion_goals:
      type: sequence
      label: 'Conversion goals'
      sequence:
        type: analyze_posthog.conversion_goal
```

### Attribution Model: Session-Based

"A page gets credit for a conversion if the converting session included a pageview of that page."

Why session-based:
- **Simple to explain**: "23 people who viewed this page also converted"; content editors understand this immediately
- **Reliable with PostHog data**: `$session_id` is on every event, making session joins straightforward in HogQL
- **No ordering complexity**: last-touch and first-touch require sequencing pageviews within sessions, adding query complexity for marginal benefit in a content attribution context
- **Matches industry standard**: Google Analytics 4's default content attribution is also session-scoped

### HogQL Queries

**Per-entity conversion query** (for /pricing with 2 configured goals):

```sql
SELECT
  event as goal,
  count(DISTINCT properties.$session_id) as conversions,
  sum(toFloat64OrZero(properties.amount)) as revenue
FROM events
WHERE event IN ('subscription_purchased', 'user_registered')
  AND properties.$session_id IN (
    SELECT DISTINCT properties.$session_id
    FROM events
    WHERE event = '$pageview'
      AND properties.$pathname = '/pricing'
      AND timestamp > now() - interval 28 day
  )
  AND timestamp > now() - interval 28 day
GROUP BY goal
```

**Sitewide conversion-by-page query** (which pages drive the most conversions):

```sql
SELECT
  pv_page as page,
  count(DISTINCT conv_session) as conversions,
  sum(revenue) as total_revenue
FROM (
  SELECT
    pv.properties.$pathname as pv_page,
    conv.properties.$session_id as conv_session,
    toFloat64OrZero(conv.properties.amount) as revenue
  FROM events conv
  JOIN events pv
    ON conv.properties.$session_id = pv.properties.$session_id
  WHERE conv.event IN ('subscription_purchased', 'user_registered')
    AND pv.event = '$pageview'
    AND conv.timestamp > now() - interval 28 day
    AND pv.timestamp > now() - interval 28 day
)
GROUP BY page
ORDER BY conversions DESC
LIMIT 100
```

**Event auto-detection query** (for settings form dropdown):

```sql
SELECT event, count() as cnt
FROM events
WHERE event NOT LIKE '$%'
  AND timestamp > now() - interval 30 day
GROUP BY event
ORDER BY cnt DESC
LIMIT 50
```

### Conversion Rate Calculation

```
conversion_rate = (converting_sessions / total_sessions) * 100
```

Where:
- `converting_sessions` = sessions that included BOTH a pageview of this page AND a goal event
- `total_sessions` = all sessions that included a pageview of this page

This rate is meaningful per-page ("5.6% of sessions visiting /pricing result in a conversion") and gives content editors a clear signal of page effectiveness.

## Implementation Plan

### Phase 1: Settings Form, Goal Management

**File: `src/Form/PostHogSettingsForm.php`**

Add a "Conversion Goals" section below the existing connection settings. Uses Drupal's `#type => 'table'` with inline editing (add/remove rows), similar to how Menu module handles menu links or how Webform handles element settings.

**UI:**

```
Conversion Goals
┌─────────┬──────────────┬─────────────────────────┬────────┬────────────────┬──────────┬─────────┐
│ Label   │ Event        │ (auto-detected dropdown)│ Value  │ Value property │ Currency │ Remove  │
├─────────┼──────────────┼─────────────────────────┼────────┼────────────────┼──────────┼─────────┤
│ Signup  │ user_registrd│ [v]                     │ 0      │                │ USD      │ [x]     │
│ Subscr. │ sub_purchased│ [v]                     │ 0      │ amount         │ USD      │ [x]     │
└─────────┴──────────────┴─────────────────────────┴────────┴────────────────┴──────────┴─────────┘
[+ Add goal]
```

The event field should be a `select` element populated by the auto-detection query, with a fallback textfield if the API is unreachable. Show event counts in the dropdown options (e.g., "subscription_purchased (68 in last 30 days)") so admins know which events have data.

**Implementation details:**
- Goals stored as indexed array in config (not keyed by ID, since Drupal config sequences use integer keys)
- Machine name (`id`) auto-generated from label via `Html::cleanCssIdentifier()` or similar
- Form uses AJAX to add/remove rows without full page reload
- Validation: event name required, no duplicate event names across goals
- On save, re-derive `id` from label to keep them in sync

### Phase 2: PostHogClient, Conversion Query Methods

**File: `src/Service/PostHogClient.php`**

Add these public methods:

```php
/**
 * Get conversion data for a specific page.
 *
 * @param string $pathname
 *   The page pathname.
 * @param int $days
 *   Number of days.
 * @param array $goals
 *   Conversion goals from config.
 *
 * @return array
 *   Array keyed by goal ID with 'conversions', 'revenue', 'rate' per goal,
 *   plus 'total_conversions', 'total_revenue', 'overall_rate' aggregates.
 */
public function getPageConversions(string $pathname, int $days, array $goals): array

/**
 * Get conversion data with comparison to previous period.
 */
public function getPageConversionsWithComparison(string $pathname, int $days, array $goals): ?array

/**
 * Get sitewide conversion data (which pages drive conversions).
 */
public function getSitewideConversions(int $days, array $goals, string $country = ''): array

/**
 * Get sitewide previous period conversion data.
 */
public function getSitewidePrevConversions(int $days, array $goals, string $country = ''): array

/**
 * Get available custom event names from PostHog.
 *
 * Used by settings form for goal event auto-detection.
 */
public function getAvailableEvents(int $days = 30): array
```

**Query builder helpers** (extend existing DRY pattern):

```php
/**
 * Build session subquery for pages matching a pathname.
 */
protected function buildPageSessionSubquery(string $escapedPath, string $timeFilter): string

/**
 * Build conversion query for given goals and session filter.
 */
protected function buildConversionQuery(array $goals, string $sessionFilter, string $timeFilter, array $valueProperties): string
```

**Caching:**
- Cache key: `analyze_posthog:{host_hash}:{path_hash}:conversions:{days}:{goals_hash}`
- `goals_hash` = `md5(serialize($goals))` (invalidates when goals change)
- Same TTL and tags as existing queries

**Return format for `getPageConversions()`:**

```php
[
  'goals' => [
    'signup' => [
      'label' => 'Signup',
      'conversions' => 12,
      'revenue' => 0,
    ],
    'subscription' => [
      'label' => 'Subscription',
      'conversions' => 8,
      'revenue' => 232.00,
    ],
  ],
  'total_conversions' => 20,
  'total_revenue' => 232.00,
  'total_sessions' => 238,
  'overall_rate' => 8.4,
]
```

### Phase 3: ReportBuilder, Conversion Rendering

**File: `src/Service/ReportBuilder.php`**

Add these methods:

```php
/**
 * Build conversion KPI cells to append to the existing KPI table.
 *
 * Returns cells for: Conversions (count + change), Revenue (if any goal
 * has revenue), Conv. rate (percentage + change).
 */
public function buildConversionKpiCells(array $conversionData, ?array $prevConversionData): array

/**
 * Build a conversion breakdown table (the "Conversions" dimension tab).
 *
 * Columns: Goal, Conversions, Revenue, Conv. rate, Change, Status.
 */
public function buildConversionTable(array $conversionData, ?array $prevConversionData, string $caption): array
```

**KPI integration:**
The existing `buildKpiTable()` renders 4 columns (Pageviews, Visitors, Sessions, Bounce rate). When conversion goals are configured, append 1-2 additional columns:
- **Conversions**: total count across all goals, with change indicator
- **Revenue**: total revenue (only if any goal has value/value_property configured)

This keeps the KPI row compact. The full per-goal breakdown goes in the Conversions dimension tab.

**Conversion table columns:**

| Goal | Conversions | Revenue | Conv. rate | Change | Status |
|------|-------------|---------|------------|--------|--------|
| Signup | 12 | – | 5.0% | ▲ 33.3% | up |
| Subscription | 8 | $232 | 3.4% | ▼ 11.1% | down |

Revenue column hidden if no goals have monetary values configured.

### Phase 4: ReportFilterForm, Conversions Dimension

**File: `src/Form/ReportFilterForm.php`**

Add `'conversion' => $this->t('Conversions')` to the dimension select options, but **only when goals are configured**. Check at form build time:

```php
$config = $this->config('analyze_posthog.settings');
$goals = $config->get('conversion_goals') ?: [];
if (!empty($goals)) {
  $dimensionOptions['conversion'] = $this->t('Conversions');
}
```

When `dimension=conversion` is selected:
- The data table shows goals as rows (via `buildConversionTable()`)
- The sitewide report shows pages ranked by conversion count
- The entity report shows goal breakdown for that page

### Phase 5: ReportController, Sitewide Conversion Report

**File: `src/Controller/ReportController.php`**

When `dimension=conversion` and goals are configured:

1. **KPI**: Show total conversions + revenue alongside existing metrics
2. **Data table**: Render `buildConversionTable()` instead of `buildDataTable()`
3. **For sitewide**: Table shows pages ranked by conversions (which pages drive the most conversions)
4. **Enrichment**: Use `enrichWithComparison()` on conversion data to get new/lost/up/down status per goal or per page

**Sitewide conversion-by-page view:**

| Page | Conversions | Revenue | Conv. rate | Change | Status |
|------|-------------|---------|------------|--------|--------|
| /pricing | 20 | $580 | 8.4% | ▲ 25.0% | up |
| /getting-started | 8 | $232 | 11.8% | – | new |
| /blog/launch | 3 | $87 | 2.1% | ▼ 40.0% | down |

### Phase 6: PostHog Plugin, Entity Conversion Display

**File: `src/Plugin/Analyze/PostHog.php`**

**renderSummary()**: append conversion metrics when goals are configured:
```
Conversions: 20 (+25.0%)
Revenue: $580 (+33.2%)
```

**renderFullReport()**: when `dimension=conversion`:
Show per-goal breakdown table for this entity.

### Phase 7: Drush Commands, Full Conversion Parity

**File: `src/Drush/Commands/PostHogCommands.php`**

Every GUI feature must have a Drush equivalent. The existing commands (`status`, `query`, `report`, `cache-clear`) already mirror the web UI for pageview analytics. Conversions must follow the same pattern.

**Changes to existing commands:**

**`analyze:posthog:query` (`analyze-ph-query`)**
- Add `conversion` to the valid `--dimension` values
- When `--dimension=conversion`: show per-goal breakdown table for the given URL
- KPI output includes conversion count + revenue when goals are configured
- Example:
  ```
  drush analyze-ph-query /pricing --dimension=conversion
  ```
  Output:
  ```
  Path: /pricing
  Period: Mar 4 – Mar 31, 2026 compared to Feb 4 – Mar 3

   Pageviews   Visitors   Sessions   Bounce rate   Conversions   Revenue
   397         199        213        11.3%         20 (+25.0%)   $580 (+33.2%)

   Goal            Conversions   Revenue   Conv. rate   Change     Status
   Subscription    8             $232      3.4%         ▼ 11.1%   down
   Signup          12            –         5.0%         ▲ 33.3%   up
  ```

**`analyze:posthog:report` (`analyze-ph-report`)**
- Add `conversion` to the valid `--dimension` values
- When `--dimension=conversion`: show pages ranked by conversion count (sitewide)
- KPI output includes total conversions + revenue
- `--country`, `--status`, `--search`, `--limit` all apply to conversion data too
- Example:
  ```
  drush analyze-ph-report --dimension=conversion --limit=5
  ```
  Output:
  ```
  Period: Mar 4 – Mar 31, 2026 compared to Feb 4 – Mar 3

   Pageviews   Visitors   Sessions   Bounce rate   Conversions   Revenue
   2,747       1,304      1,481      68.3%         45 (+18.4%)   $1,305

   Page                Conversions   Revenue   Conv. rate   Change     Status
   /pricing            20            $580      8.4%         ▲ 25.0%   up
   /getting-started    8             $232      11.8%        –          new
   /blog/launch        3             $87       2.1%         ▼ 40.0%   down
   /                   2             $58       0.2%         –          stable
   /c/drupal-builder   1             $29       0.5%         –          new
  ```

**New command:**

**`analyze:posthog:goals` (`analyze-ph-goals`)**
- Lists configured conversion goals with live event counts
- No arguments required
- Output:
  ```
   ID              Label          Event                    Last 30d events
   signup          Signup         user_registered          42
   subscription    Subscription   subscription_purchased   68
  ```
- Useful for verifying goal configuration and checking that events are flowing

**Parity matrix:**

| Feature | Sitewide GUI | Entity GUI | `report` cmd | `query` cmd | `goals` cmd |
|---------|-------------|------------|-------------|-------------|-------------|
| Pageview KPIs | /admin/reports/posthog | /node/N/analyze/posthog | `--days=28` | `--days=28` | – |
| Dimension tabs | Filter form | Filter form | `--dimension=X` | `--dimension=X` | – |
| Conversion KPIs | KPI cards | KPI cards | KPI table row | KPI table row | – |
| Per-goal breakdown | dim=conversion tab | dim=conversion tab | `--dim=conversion` | `--dim=conversion` | – |
| Pages by conversions | dim=conversion (sitewide) | N/A (single page) | `--dim=conversion` | N/A | – |
| Goal configuration | Settings form | – | – | – | List output |
| Event auto-detect | Settings form dropdown | – | – | – | Event counts |
| Country filter | Filter form | – | `--country=X` | – | – |
| Status filter | Filter form | Filter form | `--status=X` | `--status=X` | – |
| Text search | Filter form | Filter form | `--search=X` | `--search=X` | – |
| Session replay | Extra link | Extra link | – | – | – |
| Cache clear | – | – | `cache-clear` | – | – |

### Phase 8: Session Replay Link

This is the low-effort, high-impact addition. PostHog records user sessions and the data is accessible via URL.

**In PostHog plugin `extraSummaryLinks()`**, add:

```php
// "Watch sessions" link: deep-link to PostHog session replay filtered by page.
$links[] = [
  'title' => $this->t('Watch sessions'),
  'url' => Url::fromUri($host . '/replay', [
    'query' => [
      'filter_test_accounts' => 'false',
      'properties' => json_encode([
        [
          'key' => '$pathname',
          'value' => [$pathname],
          'operator' => 'exact',
          'type' => 'recording',
        ],
      ]),
    ],
    'attributes' => ['target' => '_blank', 'rel' => 'noopener'],
  ]),
];
```

This is a URL-only feature with no API calls and no new service methods. It simply opens PostHog's session replay UI pre-filtered to this page. Content editors can watch real users interact with their page directly from the Analyze tab.

Also add to the sitewide report "Open in PostHog" button area.

## What NOT to Build

- **Multi-touch attribution** (first/last/linear/time-decay): over-engineering for a contrib module. Session-based is sufficient and explainable. Power users who need multi-touch should use PostHog's native UI.
- **Funnel visualization**: PostHog's own UI does this better. Link to it instead of replicating it.
- **Real-time conversion alerts**: monitoring is a different concern than reporting. Could be a separate module using PostHog webhooks or cron.
- **Revenue forecasting**: out of scope for an analytics display module.
- **Goal completion funnels** (multi-step conversion paths): complex to query, complex to display. The session-based "did this page contribute?" model is the right level of detail for content editors.

## File Changes Summary

| File | Change |
|------|--------|
| `config/install/analyze_posthog.settings.yml` | Add `conversion_goals: []` |
| `config/schema/analyze_posthog.schema.yml` | Add goal sequence schema |
| `src/Form/PostHogSettingsForm.php` | Add goals management sub-form with event auto-detect |
| `src/Service/PostHogClient.php` | Add `getPageConversions()`, `getSitewideConversions()`, `getAvailableEvents()`, and previous-period variants |
| `src/Service/ReportBuilder.php` | Add `buildConversionKpiCells()`, `buildConversionTable()` |
| `src/Form/ReportFilterForm.php` | Add 'conversion' dimension option (conditional on goals existing) |
| `src/Controller/ReportController.php` | Handle `dimension=conversion` in sitewide report |
| `src/Plugin/Analyze/PostHog.php` | Add conversion KPIs to summary, conversion tab to full report, session replay link |
| `src/Drush/Commands/PostHogCommands.php` | Add `--dimension=conversion` support, add `goals` command |

## Dependencies

No new dependencies. The conversion feature uses the same HogQL API, same HTTP client, same caching infrastructure. The only new config is the `conversion_goals` list.

## Testing

After implementation, run ALL of the following tests. Both GUI (web) and TUI (Drush) must produce equivalent data for the same parameters.

### Setup

1. Deploy to dxpr10b test site: `rsync` module → `/web/modules/contrib/analyze_posthog/`, then `drush cr`
2. Configure 2 conversion goals at `/admin/config/analyze/posthog`:
   - Goal 1: label "Subscription", event `subscription_purchased`, value_property `amount`, currency `USD`
   - Goal 2: label "Pricing view", event `User Viewed Pricing page`, value `0`, no value_property
3. Verify the goals list: `drush analyze-ph-goals` (should show both goals with recent event counts)

### GUI Tests: Sitewide Report

Test each at `https://dxpr-10.ddev.site:8443/admin/reports/posthog`:

| # | Test | URL params | Verify |
|---|------|-----------|--------|
| G1 | Default view | (none) | KPI cards show pageviews, visitors, sessions, bounce rate. Referrer dimension table loads. |
| G2 | Conversion dimension | `?dimension=conversion` | Table shows pages ranked by conversions. Revenue column visible (Subscription goal has value_property). KPI cards include Conversions + Revenue. |
| G3 | Country filter + conversion | `?dimension=conversion&country=United States` | Only US-session conversions shown. KPI reflects US-only data. |
| G4 | Status filter on conversions | `?dimension=conversion&status=new` | Only pages that are new conversion sources shown. |
| G5 | Text search on conversions | `?dimension=conversion&q=pricing` | Only pages matching "pricing" shown. |
| G6 | Period change | `?dimension=conversion&days=90` | 90-day data. Caption reads "Jan 1 – Mar 31, 2026 compared to Oct 3 – Dec 31". |
| G7 | Period 365 days | `?dimension=conversion&days=365` | Full year data loads without error. |
| G8 | Reset button | Click "Reset" after applying filters | All filters cleared, returns to default view. |
| G9 | Filter form layout | Any view | Filters render inline (horizontal), matching `/admin/content` pattern. |
| G10 | No goals configured | Remove all goals, reload | "Conversions" dimension option hidden from dropdown. No errors. |

### GUI Tests: Entity Report

Test at `https://dxpr-10.ddev.site:8443/node/45/analyze/posthog_analytics`:

| # | Test | URL params | Verify |
|---|------|-----------|--------|
| G11 | Default entity view | (none) | KPI cards with comparison. Referrer dimension table. No country filter (entity reports hide it). |
| G12 | Entity conversion tab | `?dimension=conversion` | Per-goal breakdown table for this page. Shows Conversions, Revenue, Conv. rate per goal. |
| G13 | Entity conversion + status | `?dimension=conversion&status=up` | Only goals trending up shown. |
| G14 | Entity summary tab | Navigate to Analyze → Summary | Compact KPI list includes Conversions + Revenue lines (when goals configured). |
| G15 | Session replay link | Check extra links | "Watch sessions" link present, opens PostHog replay UI filtered to this page's pathname. |
| G16 | Source link | Check extra links | "View in PostHog" link opens PostHog web analytics. |
| G17 | No data page | Navigate to entity with no PostHog data | Shows "No analytics data available for this page yet." (no error). |

### TUI Tests: Drush Commands

Run each from the dxpr10b site root via `ddev exec drush ...`:

| # | Command | Verify |
|---|---------|--------|
| T1 | `analyze-ph-status` | Shows host, project ID, API key status, connection successful. |
| T2 | `analyze-ph-goals` | Lists both goals with event names and recent event counts. |
| T3 | `analyze-ph-report --limit=5` | Sitewide KPI + referrer table (default dimension). |
| T4 | `analyze-ph-report --dimension=conversion --limit=5` | Pages ranked by conversions. Revenue column shown. KPI includes conversions. |
| T5 | `analyze-ph-report --dimension=conversion --country="United States" --limit=5` | US-only conversion data. KPI reflects US filter. |
| T6 | `analyze-ph-report --dimension=conversion --status=new --limit=5` | Only new conversion sources. |
| T7 | `analyze-ph-report --dimension=conversion --search=pricing` | Only pages matching "pricing". |
| T8 | `analyze-ph-report --dimension=conversion --days=365` | Full year, no error. |
| T9 | `analyze-ph-report --dimension=country --limit=10` | Country dimension, no conversion data; standard pageview table. |
| T10 | `analyze-ph-query /pricing --dimension=conversion` | Per-goal breakdown for /pricing. Conversions + Revenue in KPI row. |
| T11 | `analyze-ph-query /pricing --dimension=conversion --status=up` | Only goals trending up for /pricing. |
| T12 | `analyze-ph-query /pricing --dimension=referrer --limit=5` | Standard referrer breakdown; conversions NOT shown (different dimension). |
| T13 | `analyze-ph-query / --days=7 --dimension=device` | Homepage devices, 7 days; regression test for existing functionality. |
| T14 | `analyze-ph-cc` | Cache cleared successfully. |
| T15 | `analyze-ph-report --dimension=conversion` (with no goals configured) | Graceful message: "No conversion goals configured." (no error/crash). |

### Parity Checks

After running all tests, verify these cross-checks:

| Parity check | How |
|-------------|-----|
| G2 data = T4 data | Sitewide conversion numbers match between web and Drush (same period). |
| G12 data = T10 data | Entity /pricing conversion numbers match between web and Drush. |
| G3 data = T5 data | US-filtered conversions match between web and Drush. |
| G10 behavior = T15 behavior | Both gracefully handle missing goals without errors. |
| G1 data = T3 data | Sitewide pageview KPIs match between web and Drush. |

### Linter Checks

After all functional tests pass:

```bash
cd /path/to/analyze_posthog

# PHPCS: 0 errors required (warnings OK)
docker compose --profile lint run drupal-lint

# PHPStan level 5: 0 errors required
docker compose --profile lint run drupal-check
```

Both must show `[OK] No errors` or `FOUND 0 ERRORS` before the feature is considered complete.

## Existing Implementation Reference

The pageview analytics code is the reference architecture for how to add the conversion feature. Key patterns already established:

- **SQL builder helpers**: `buildMetricsQuery()`, `buildDimensionQuery()`, `buildTimeFilter()`, `buildPathFilter()`, `buildCountryFilter()`, `buildSitewideBounceQuery()`: extend this pattern for conversion queries
- **Shared filter method**: `ReportBuilder::filterRows()`: reuse for conversion data filtering
- **Enrichment**: `ReportBuilder::enrichWithComparison()`: adapt for conversion rows (status based on conversion count change instead of pageview change)
- **KPI rendering**: `ReportBuilder::buildKpiTable()` / `formatKpiCell()`: extend to include conversion columns
- **Dimension routing**: Controller/plugin already switch on `$dimension`; add `'conversion'` case
- **Drush parity**: Every UI surface has a Drush equivalent; maintain this for conversions
- **Key module**: API key stored via `key:key` dependency, resolved via `KeyRepositoryInterface`
- **CSS**: `views-exposed-form` inline layout, `gin-new-flag`/`gin-experimental-flag` badges, `ph-change--up`/`ph-change--down` indicators
