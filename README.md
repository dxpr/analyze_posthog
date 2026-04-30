> **Analyze PostHog** is a Drupal module by [DXPR](https://dxpr.com) that
> connects PostHog product analytics data to Drupal's content analysis workflow, surfacing page-level engagement metrics alongside editorial tools. An [Analyze](https://www.drupal.org/project/analyze) plugin by [DXPR](https://dxpr.com).
>
> [Getting Started](https://dxpr.com/c/marketing-cms) |
> [Pricing](https://dxpr.com/pricing) |
> [Try Free Demo](https://try.dxpr.com)

# Analyze PostHog: PostHog Analytics Integration for Drupal Content

Displays PostHog analytics data (pageviews, visitors, sessions, bounce rate) for
entities with URL paths, directly in the Drupal Analyze tab.

## Features

- **Per-page analytics**: Pageviews, visitors, sessions, and bounce rate
  per content entity
- **Period comparison**: Current vs previous period with change indicators
- **Dimension breakdowns**: Referrer, country, device, browser, and page
  dimensions
- **Sitewide report**: Traffic patterns across your entire site at
  /admin/reports/posthog
- **Session replay link**: Jump to PostHog session replay pre-filtered to
  the current page
- **Drush commands**: Query analytics, view reports, check status, and
  clear cache from CLI
- **Analyze Framework Integration**: Consistent reporting across all
  analysis tools

## Requirements

- [Analyze](https://www.drupal.org/project/analyze) framework (>=1.1.0)
- [Key](https://www.drupal.org/project/key) module for secure API key storage
- A [PostHog](https://posthog.com/) account (cloud or self-hosted) with a personal API key

## Installation

```bash
composer require drupal/analyze_posthog
drush en analyze_posthog
```

## Configuration

### Basic Setup
1. Create a [PostHog personal API key](https://posthog.com/docs/api) with read access
2. Store the API key at `/admin/config/system/keys` (Key module)
3. Configure PostHog host and project ID at `/admin/config/analyze/posthog`
4. Enable per content type at `/admin/config/content/analyze-settings`

## Related Modules

- [Analyze](https://www.drupal.org/project/analyze) - Required parent
  framework that provides the Analyze tab and plugin system this module
  extends
- [Key](https://www.drupal.org/project/key) - Required for secure storage
  of the PostHog API key used by this module
- [Analyze Search Console](https://www.drupal.org/project/analyze_search_console)
  - Sibling Analyze plugin that adds Google Search Console data to the
  same Analyze tab
- [Analyze Broken Links](https://www.drupal.org/project/analyze_broken_links)
  - Sibling Analyze plugin for broken link detection in the Analyze tab
