<?php

declare(strict_types=1);

namespace Drupal\analyze_posthog\Drush\Commands;

use Drupal\analyze_posthog\Service\PostHogClient;
use Drupal\analyze_posthog\Service\ReportBuilder;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush commands for the Analyze PostHog module.
 */
final class PostHogCommands extends DrushCommands {

  /**
   * Constructs PostHogCommands.
   *
   * @param \Drupal\analyze_posthog\Service\PostHogClient $client
   *   The PostHog client.
   * @param \Drupal\analyze_posthog\Service\ReportBuilder $reportBuilder
   *   The shared report builder.
   * @param \Drupal\Core\Cache\CacheTagsInvalidatorInterface $cacheTagsInvalidator
   *   The cache tags invalidator.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(
    private readonly PostHogClient $client,
    private readonly ReportBuilder $reportBuilder,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    private readonly ConfigFactoryInterface $configFactory,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('analyze_posthog.client'),
      $container->get('analyze_posthog.report_builder'),
      $container->get('cache_tags.invalidator'),
      $container->get('config.factory'),
    );
  }

  /**
   * Check PostHog API connection status.
   */
  #[CLI\Command(name: 'analyze:posthog:status', aliases: ['analyze-ph-status'])]
  #[CLI\Help(description: 'Check PostHog API connection status.')]
  public function status(): void {
    if (!$this->client->isConfigured()) {
      $this->logger()->error('PostHog is not configured. Configure at /admin/config/analyze/posthog.');
      return;
    }

    $config = $this->configFactory->get('analyze_posthog.settings');
    $this->io()->writeln('Host: ' . $config->get('host'));
    $this->io()->writeln('Project ID: ' . $config->get('project_id'));
    $this->io()->writeln('API key: ' . (empty($config->get('personal_api_key')) ? 'Not set' : 'Set (phx_...)'));
    $this->io()->writeln('Date range: ' . $config->get('date_range') . ' days');
    $this->io()->writeln('Cache TTL: ' . $config->get('cache_ttl') . 's');

    if ($this->client->testConnection()) {
      $this->logger()->success('Connection successful.');
    }
    else {
      $this->logger()->error('Connection failed. Check your credentials at /admin/config/analyze/posthog.');
    }
  }

  /**
   * Fetch PostHog data for a specific URL (entity-level report).
   *
   * @param string $url
   *   A path (e.g., /pricing) or pathname.
   * @param array<string, mixed> $options
   *   Command options.
   */
  #[CLI\Command(name: 'analyze:posthog:query', aliases: ['analyze-ph-query'])]
  #[CLI\Help(description: 'Fetch PostHog analytics data for a URL; matches the entity Analyze tab.')]
  #[CLI\Argument(name: 'url', description: 'A path like /pricing.')]
  #[CLI\Option(name: 'days', description: 'Date range in days (7, 14, 28, 90, 180, 365).')]
  #[CLI\Option(name: 'dimension', description: 'Primary dimension: referrer, country, device, browser, conversion.')]
  #[CLI\Option(name: 'status', description: 'Filter by status: all, up, down, new, lost.')]
  #[CLI\Option(name: 'search', description: 'Filter dimension keys by text (case-insensitive contains).')]
  #[CLI\Option(name: 'limit', description: 'Number of rows to display.')]
  public function query(
    string $url,
    array $options = [
      'days' => 28,
      'dimension' => 'referrer',
      'status' => 'all',
      'search' => '',
      'limit' => 20,
    ],
  ): void {
    if (!$this->client->isConfigured()) {
      $this->logger()->error('Not configured. Run: drush analyze-ph-status');
      return;
    }

    $pathname = $this->resolvePath($url);
    $days = (int) $options['days'];
    $dimension = $options['dimension'];
    $statusFilter = $options['status'] ?? 'all';
    $searchFilter = $options['search'] ?? '';
    $limit = (int) $options['limit'];
    $config = $this->configFactory->get('analyze_posthog.settings');
    $goals = $config->get('conversion_goals') ?: [];

    // Validate dimension.
    if ($dimension === 'conversion' && empty($goals)) {
      $this->logger()->error('No conversion goals configured. Add goals at /admin/config/analyze/posthog.');
      return;
    }

    $this->io()->writeln('');
    $this->io()->writeln('Path: ' . $pathname);
    $this->io()->writeln('Period: ' . $this->reportBuilder->buildDateCaption($days));
    $this->io()->writeln('');

    // KPI summary: always show comparison (matches entity UI).
    $data = $this->client->getPageMetricsWithComparison($pathname, $days);
    if ($data === NULL || $data['current'] === NULL) {
      $this->logger()->warning('No data available for this path.');
      return;
    }
    $this->printKpiTable($data, $goals, $pathname, $days);

    // Handle conversion dimension.
    if ($dimension === 'conversion') {
      $convData = $this->client->getPageConversions($pathname, $days, $goals);
      $convComparison = $this->client->getPageConversionsWithComparison(
        $pathname, $days, $goals
      );

      // Transform goals into rows.
      $currentRows = [];
      foreach ($convData['goals'] as $goalData) {
        $currentRows[] = [
          'key' => $goalData['label'],
          'conversions' => $goalData['conversions'],
          'revenue' => $goalData['revenue'],
          'pageviews' => $goalData['conversions'],
          'visitors' => 0,
        ];
      }
      $prevRows = [];
      if ($convComparison && $convComparison['previous']) {
        foreach ($convComparison['previous']['goals'] as $goalData) {
          $prevRows[] = [
            'key' => $goalData['label'],
            'conversions' => $goalData['conversions'],
            'revenue' => $goalData['revenue'],
            'pageviews' => $goalData['conversions'],
            'visitors' => 0,
          ];
        }
      }

      $hasRevenue = $this->reportBuilder->goalsHaveRevenue($goals);
      $enriched = $this->reportBuilder->enrichConversionComparison(
        $currentRows, $prevRows
      );
      $enriched = $this->reportBuilder->filterRows(
        $enriched, $statusFilter, $searchFilter
      );
      $enriched = array_slice($enriched, 0, $limit);

      $this->printConversionDimensionTable($enriched, $hasRevenue, FALSE);
      return;
    }

    // Dimension breakdown with enrichment (matches entity UI).
    $currentRows = $this->client->getDimensionData(
      $pathname, $days, $dimension, 100
    );
    $prevRows = $this->client->getPreviousPeriodDimensionData(
      $pathname, $days, $dimension, 100
    );
    $enriched = $this->reportBuilder->enrichWithComparison(
      $currentRows, $prevRows
    );

    // Apply filters and limit (matches entity UI).
    $enriched = $this->reportBuilder->filterRows(
      $enriched, $statusFilter, $searchFilter
    );
    $enriched = array_slice($enriched, 0, $limit);

    $this->printDimensionTable($enriched, $dimension);
  }

  /**
   * Fetch sitewide PostHog analytics report.
   *
   * @param array<string, mixed> $options
   *   Command options.
   */
  #[CLI\Command(name: 'analyze:posthog:report', aliases: ['analyze-ph-report'])]
  #[CLI\Help(description: 'Sitewide PostHog analytics report; matches the admin report page.')]
  #[CLI\Option(name: 'days', description: 'Date range in days (7, 14, 28, 90, 180, 365).')]
  #[CLI\Option(name: 'dimension', description: 'Primary dimension: referrer, country, device, browser, page, conversion.')]
  #[CLI\Option(name: 'country', description: 'Filter by country name (e.g., "United States").')]
  #[CLI\Option(name: 'status', description: 'Filter by status: all, up, down, new, lost.')]
  #[CLI\Option(name: 'search', description: 'Filter dimension keys by text (case-insensitive contains).')]
  #[CLI\Option(name: 'limit', description: 'Number of rows to display.')]
  public function report(
    array $options = [
      'days' => 28,
      'dimension' => 'referrer',
      'country' => '',
      'status' => 'all',
      'search' => '',
      'limit' => 20,
    ],
  ): void {
    if (!$this->client->isConfigured()) {
      $this->logger()->error('Not configured. Run: drush analyze-ph-status');
      return;
    }

    $days = (int) $options['days'];
    $dimension = $options['dimension'];
    $country = $options['country'] ?? '';
    $statusFilter = $options['status'] ?? 'all';
    $searchFilter = $options['search'] ?? '';
    $limit = (int) $options['limit'];
    $config = $this->configFactory->get('analyze_posthog.settings');
    $goals = $config->get('conversion_goals') ?: [];

    // Validate conversion dimension.
    if ($dimension === 'conversion' && empty($goals)) {
      $this->logger()->error('No conversion goals configured. Add goals at /admin/config/analyze/posthog.');
      return;
    }

    $this->io()->writeln('');
    $this->io()->writeln('Period: ' . $this->reportBuilder->buildDateCaption($days));
    if (!empty($country)) {
      $this->io()->writeln('Country: ' . $country);
    }
    $this->io()->writeln('');

    // KPI summary: passes country filter (matches sitewide UI).
    $summary = $this->client->getSitewideMetricsWithComparison(
      $days, $country
    );
    if ($summary && $summary['current']) {
      $this->printKpiTable($summary, $goals, '', $days, $country);
    }

    // Handle conversion dimension.
    if ($dimension === 'conversion') {
      $currentRows = $this->client->getSitewideConversions(
        $days, $goals, $country
      );
      $prevRows = $this->client->getSitewidePrevConversions(
        $days, $goals, $country
      );
      $hasRevenue = $this->reportBuilder->goalsHaveRevenue($goals);
      $enriched = $this->reportBuilder->enrichConversionComparison(
        $currentRows, $prevRows
      );
      $enriched = $this->reportBuilder->filterRows(
        $enriched, $statusFilter, $searchFilter
      );
      $enriched = array_slice($enriched, 0, $limit);

      $this->printConversionDimensionTable($enriched, $hasRevenue, TRUE);
      return;
    }

    // Dimension data with enrichment via ReportBuilder (matches sitewide UI).
    $currentRows = $this->client->getSitewideDimensionData(
      $days, $dimension, 100, $country
    );
    $prevRows = $this->client->getSitewidePrevDimensionData(
      $days, $dimension, 100, $country
    );
    $enriched = $this->reportBuilder->enrichWithComparison(
      $currentRows, $prevRows
    );

    // Apply filters and limit (matches sitewide UI).
    $enriched = $this->reportBuilder->filterRows(
      $enriched, $statusFilter, $searchFilter
    );
    $enriched = array_slice($enriched, 0, $limit);

    $this->printDimensionTable($enriched, $dimension);
  }

  /**
   * Clear cached PostHog analytics data.
   */
  #[CLI\Command(name: 'analyze:posthog:cache-clear', aliases: ['analyze-ph-cc'])]
  #[CLI\Help(description: 'Clear all cached PostHog analytics data.')]
  public function cacheClear(): void {
    $this->cacheTagsInvalidator->invalidateTags(['analyze_posthog']);
    $this->logger()->success('PostHog analytics cache cleared.');
  }

  /**
   * List configured conversion goals with live event counts.
   */
  #[CLI\Command(name: 'analyze:posthog:goals', aliases: ['analyze-ph-goals'])]
  #[CLI\Help(description: 'List configured conversion goals with live event counts.')]
  public function goals(): void {
    if (!$this->client->isConfigured()) {
      $this->logger()->error('Not configured. Run: drush analyze-ph-status');
      return;
    }

    $config = $this->configFactory->get('analyze_posthog.settings');
    $goals = $config->get('conversion_goals') ?: [];

    if (empty($goals)) {
      $this->logger()->warning('No conversion goals configured. Add goals at /admin/config/analyze/posthog.');
      return;
    }

    // Fetch available events for counts.
    $availableEvents = $this->client->getAvailableEvents();
    $eventCounts = [];
    foreach ($availableEvents as $eventData) {
      $eventCounts[$eventData['event']] = $eventData['count'];
    }

    $headers = ['ID', 'Label', 'Event', 'Last 30d events'];
    $rows = [];
    foreach ($goals as $goal) {
      $count = $eventCounts[$goal['event']] ?? 0;
      $rows[] = [
        $goal['id'],
        $goal['label'],
        $goal['event'],
        number_format($count),
      ];
    }

    $this->io()->table($headers, $rows);
  }

  /**
   * Print a KPI summary table with comparison and optional conversion columns.
   *
   * @param array<string, mixed> $data
   *   Metrics data with 'current', 'previous', and 'change' keys.
   * @param array<int, array<string, mixed>> $goals
   *   Conversion goals (empty to skip conversion KPIs).
   * @param string $pathname
   *   Page pathname (empty for sitewide).
   * @param int $days
   *   Date range in days.
   * @param string $country
   *   Optional country filter (sitewide only).
   */
  protected function printKpiTable(array $data, array $goals = [], string $pathname = '', int $days = 28, string $country = ''): void {
    $c = $data['current'];
    $ch = $data['change'];

    $headers = ['Pageviews', 'Visitors', 'Sessions', 'Bounce rate'];
    $row = [
      number_format((int) $c['pageviews']) . ($ch ? ' (' . $ch['pageviews']['formatted'] . ')' : ''),
      number_format((int) $c['visitors']) . ($ch ? ' (' . $ch['visitors']['formatted'] . ')' : ''),
      number_format((int) $c['sessions']) . ($ch ? ' (' . $ch['sessions']['formatted'] . ')' : ''),
      number_format($c['bounce_rate'], 1) . '%' . ($ch ? ' (' . $ch['bounce_rate']['formatted'] . ')' : ''),
    ];

    // Add conversion KPI columns when goals are configured.
    if (!empty($goals)) {
      if ($pathname !== '') {
        $convComparison = $this->client->getPageConversionsWithComparison(
          $pathname, $days, $goals
        );
        $convData = $convComparison ? $convComparison['current'] : [];
        $prevConvData = $convComparison ? $convComparison['previous'] : NULL;
      }
      else {
        $convData = $this->client->getSitewideConversionTotals(
          $days, $goals, $country
        );
        $prevConvData = $this->client->getSitewidePrevConversionTotals(
          $days, $goals, $country
        );
      }

      $hasRevenue = $this->reportBuilder->goalsHaveRevenue($goals);
      $convKpi = $this->reportBuilder->printConversionKpi(
        $convData, $prevConvData, $hasRevenue
      );
      $headers = array_merge($headers, $convKpi['headers']);
      $row = array_merge($row, $convKpi['values']);
    }

    $this->io()->table($headers, [$row]);
  }

  /**
   * Print a dimension data table with change and status columns.
   *
   * @param array<int, array<string, mixed>> $rows
   *   Enriched rows.
   * @param string $dimension
   *   The active dimension.
   */
  protected function printDimensionTable(array $rows, string $dimension): void {
    if (empty($rows)) {
      $this->io()->writeln('No data found.');
      return;
    }

    $dimLabel = $this->reportBuilder->getDimensionLabel($dimension);
    $headers = [$dimLabel, 'Change', 'Pageviews', 'Visitors', 'Status'];

    $tableRows = [];
    foreach ($rows as $row) {
      $key = (string) $row['key'];
      $changeStr = '–';
      $pctChange = $row['pct_change'] ?? NULL;
      if ($pctChange !== NULL && abs($pctChange) >= 0.1) {
        $arrow = $pctChange > 0 ? '▲' : '▼';
        $changeStr = $arrow . ' ' . number_format(abs($pctChange), 1) . '%';
      }

      $tableRows[] = [
        $key,
        $changeStr,
        number_format((int) $row['pageviews']),
        number_format((int) $row['visitors']),
        $row['status'] ?? 'stable',
      ];
    }

    $this->io()->writeln('');
    $this->io()->table($headers, $tableRows);
  }

  /**
   * Print a conversion dimension table for Drush output.
   *
   * @param array<int, array<string, mixed>> $rows
   *   Enriched conversion rows.
   * @param bool $hasRevenue
   *   Whether to show the revenue column.
   * @param bool $isSitewide
   *   TRUE if sitewide (first col = Page), FALSE for entity (first col = Goal).
   */
  protected function printConversionDimensionTable(array $rows, bool $hasRevenue, bool $isSitewide): void {
    if (empty($rows)) {
      $this->io()->writeln('No conversion data found.');
      return;
    }

    $tableData = $this->reportBuilder->printConversionTable(
      $rows, $hasRevenue, $isSitewide
    );

    $this->io()->writeln('');
    $this->io()->table($tableData['headers'], $tableData['rows']);
  }

  /**
   * Resolve a path for API queries.
   *
   * @param string $url
   *   A path or URL.
   *
   * @return string
   *   The pathname.
   */
  protected function resolvePath(string $url): string {
    // Strip protocol and host if full URL is given.
    if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
      $parsed = parse_url($url);
      return $parsed['path'] ?? '/';
    }

    // Ensure leading slash.
    if (!str_starts_with($url, '/')) {
      $url = '/' . $url;
    }

    return $url;
  }

}
