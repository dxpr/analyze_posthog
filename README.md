# Analyze PostHog

PostHog analytics integration for the Analyze tab, showing pageviews,
visitors, sessions, bounce rate, and average time on page for entities with
URL paths.

## Features

- **Pageview Analytics**: Pageviews, unique visitors, sessions, bounce rate,
  and average time on page displayed per entity
- **Previous Period Comparison**: Each metric shows change vs the prior period
  (e.g., "397 pageviews (+12.3%)")
- **Dimension Breakdowns**: Switch between Referrer, Country, Device, and
  Browser views in the full report
- **Date Range Filtering**: Filter by 7/14/28/90/180/365 days
- **Sitewide Report**: Aggregate analytics across your entire site at
  `/admin/reports/posthog`
- **Conversion Tracking**: Define conversion goals and see conversions and
  revenue per page
- **Session Replay Links**: Jump directly to PostHog session replays
  pre-filtered to the current page
- **Drush Commands**: CLI access for status checks, data queries, and cache
  management
- **Batch Processing**: Process analytics for all content via Admin UI or
  Drush CLI
- **Analyze Framework Integration**: Consistent reporting across all analysis
  tools

## Requirements

- [Analyze](https://www.drupal.org/project/analyze) module (>=1.1.0)
- [Key](https://www.drupal.org/project/key) module
- A [PostHog](https://posthog.com/) account (cloud or self-hosted) with a
  personal API key

## Installation

```bash
composer require drupal/analyze_posthog
drush en analyze_posthog
```

## Configuration

1. Create a [PostHog personal API key](https://posthog.com/docs/api) with
   read access
2. Store the API key at `/admin/config/system/keys` using the Key module
3. Configure your PostHog host and project ID at
   `/admin/config/analyze/posthog`
4. Enable the analyzer per content type at
   `/admin/config/content/analyze-settings`
5. Configure permissions at
   `/admin/people/permissions#module-analyze_posthog`

## Data Displayed

### Summary Tab

Shows aggregate metrics for the configured date range with change indicators:

| Metric | Example |
|--------|---------|
| Pageviews | 397 (+12.3%) |
| Unique visitors | 234 (+8.1%) |
| Sessions | 289 (+10.5%) |
| Bounce rate | 45.2% (-3.1%) |
| Avg time on page | 2m 15s (+10.0%) |

### Full Report Tab

Detailed breakdown with dimension tabs:

- **Referrer** -- which sites and campaigns send visitors to this page
- **Country** -- geographic distribution of your audience
- **Device** -- desktop vs mobile vs tablet breakdown
- **Browser** -- Chrome, Firefox, Safari, and others
- **Conversion** -- per-goal conversion data (when goals are configured)

Each view shows metrics per row with previous period comparison, sorted by
pageviews descending.

## Batch Processing

Batch analysis is available through the centralized Analyze batch system:

- **Admin UI**: Navigate to Administration > Configuration > Content > Batch
  Analysis (`/admin/config/content/analyze-batch`), select "PostHog Analytics"
  and your desired content types.
- **Drush CLI**: `drush analyze:batch --analyzers=posthog_analytics`

See the [Analyze module documentation](https://www.drupal.org/project/analyze)
for full batch command options.

## Development

### Docker Commands

```bash
# Lint code
docker compose --profile lint run drupal-lint

# Check deprecations and static analysis
docker compose --profile lint run drupal-check

# Auto-fix lint issues
docker compose --profile lint run drupal-lint-auto-fix
```
