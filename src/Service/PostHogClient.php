<?php

declare(strict_types=1);

namespace Drupal\analyze_posthog\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\key\KeyRepositoryInterface;
use Drupal\path_alias\AliasManagerInterface;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Client service for interacting with the PostHog HogQL API.
 */
class PostHogClient {

  /**
   * The logger.
   */
  protected LoggerInterface $logger;

  /**
   * Dimension property mapping.
   *
   * @var array<string, string>
   */
  protected const DIMENSION_MAP = [
    'referrer' => '$referring_domain',
    'country' => '$geoip_country_name',
    'device' => '$device_type',
    'browser' => '$browser',
    'page' => '$pathname',
  ];

  /**
   * Constructs a PostHogClient.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\path_alias\AliasManagerInterface $aliasManager
   *   The path alias manager.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache backend.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   The HTTP client.
   * @param \Drupal\key\KeyRepositoryInterface $keyRepository
   *   The key repository.
   */
  public function __construct(
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly AliasManagerInterface $aliasManager,
    protected readonly CacheBackendInterface $cache,
    LoggerChannelFactoryInterface $loggerFactory,
    protected readonly ClientInterface $httpClient,
    protected readonly KeyRepositoryInterface $keyRepository,
  ) {
    $this->logger = $loggerFactory->get('analyze_posthog');
  }

  /**
   * Check if the module is configured.
   *
   * @return bool
   *   TRUE if API key, host, and project ID are set.
   */
  public function isConfigured(): bool {
    $config = $this->configFactory->get('analyze_posthog.settings');
    $apiKey = $this->resolveKey((string) $config->get('personal_api_key'));
    return !empty($apiKey)
      && !empty($config->get('host'))
      && !empty($config->get('project_id'));
  }

  /**
   * Resolve a key value from the Key module.
   *
   * @param string $keyId
   *   The key entity ID.
   *
   * @return string|null
   *   The key value, or NULL.
   */
  protected function resolveKey(string $keyId): ?string {
    if (empty($keyId)) {
      return NULL;
    }
    $key = $this->keyRepository->getKey($keyId);
    return $key ? $key->getKeyValue() : NULL;
  }

  /**
   * Test the API connection with a lightweight query.
   *
   * @return bool
   *   TRUE if the connection is successful.
   */
  public function testConnection(): bool {
    $result = $this->hogqlQuery('SELECT 1');
    return $result !== NULL;
  }

  /**
   * Get the resolved pathname for an entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   *
   * @return string|null
   *   The pathname (e.g. /pricing), or NULL if unavailable.
   */
  public function getEntityUrl(EntityInterface $entity): ?string {
    try {
      $internalPath = $entity->toUrl()->toString();
      return $this->aliasManager->getAliasByPath($internalPath);
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

  /**
   * Get aggregate metrics for a page.
   *
   * @param string $pathname
   *   The page pathname (e.g. /pricing).
   * @param int $days
   *   Number of days to query.
   *
   * @return array<string, float>|null
   *   Array with pageviews, visitors, sessions, bounce_rate, avg_time keys.
   */
  public function getPageMetrics(string $pathname, int $days = 28): ?array {
    $cacheKey = $this->getCacheKey($pathname, 'metrics', $days);
    $cached = $this->cache->get($cacheKey);
    if ($cached) {
      return $cached->data;
    }

    $escapedPath = $this->escapeHogql($pathname);
    $pathFilter = $this->buildPathFilter($escapedPath);
    $timeFilter = $this->buildTimeFilter($days);

    $mainResult = $this->hogqlQuery(
      $this->buildMetricsQuery($pathFilter, $timeFilter)
    );
    if ($mainResult === NULL || empty($mainResult['results'])) {
      $this->cacheSet($cacheKey, NULL);
      return NULL;
    }

    $row = $mainResult['results'][0];
    $pageviews = (float) ($row[0] ?? 0);
    $visitors = (float) ($row[1] ?? 0);
    $sessions = (float) ($row[2] ?? 0);

    if ($pageviews == 0) {
      $this->cacheSet($cacheKey, NULL);
      return NULL;
    }

    // Bounce rate: sessions with only 1 pageview that included this page.
    $bounceRate = $this->calculateBounceRate($escapedPath, $days, $sessions);

    // Average time on page.
    $avgTime = $this->calculateAvgTimeOnPage($escapedPath, $days);

    $result = [
      'pageviews' => $pageviews,
      'visitors' => $visitors,
      'sessions' => $sessions,
      'bounce_rate' => $bounceRate,
      'avg_time' => $avgTime,
    ];

    $this->cacheSet($cacheKey, $result);
    return $result;
  }

  /**
   * Get metrics with comparison to the previous period.
   *
   * @param string $pathname
   *   The page pathname.
   * @param int $days
   *   Number of days for the current period.
   *
   * @return array<string, mixed>|null
   *   Array with 'current', 'previous', and 'change' sub-arrays, or NULL.
   */
  public function getPageMetricsWithComparison(string $pathname, int $days = 28): ?array {
    $cacheKey = $this->getCacheKey($pathname, 'comparison', $days);
    $cached = $this->cache->get($cacheKey);
    if ($cached) {
      return $cached->data;
    }

    $current = $this->getPageMetrics($pathname, $days);
    if ($current === NULL) {
      return NULL;
    }

    // Fetch previous period metrics.
    $escapedPath = $this->escapeHogql($pathname);
    $pathFilter = $this->buildPathFilter($escapedPath);
    $prevTimeFilter = $this->buildTimeFilter($days, TRUE);

    $prevResult = $this->hogqlQuery(
      $this->buildMetricsQuery($pathFilter, $prevTimeFilter)
    );
    $previous = NULL;
    if ($prevResult !== NULL && !empty($prevResult['results'])) {
      $pRow = $prevResult['results'][0];
      $prevPageviews = (float) ($pRow[0] ?? 0);
      $prevVisitors = (float) ($pRow[1] ?? 0);
      $prevSessions = (float) ($pRow[2] ?? 0);

      $prevBounceRate = $prevSessions > 0
        ? $this->calculateBounceRate($escapedPath, $days * 2, $prevSessions, $days)
        : 0;
      $prevAvgTime = $this->calculateAvgTimeOnPage($escapedPath, $days * 2, $days);

      $previous = [
        'pageviews' => $prevPageviews,
        'visitors' => $prevVisitors,
        'sessions' => $prevSessions,
        'bounce_rate' => $prevBounceRate,
        'avg_time' => $prevAvgTime,
      ];
    }

    $change = $this->calculateChange($current, $previous);

    $result = [
      'current' => $current,
      'previous' => $previous,
      'change' => $change,
    ];

    $this->cacheSet($cacheKey, $result);
    return $result;
  }

  /**
   * Get dimension data for a page.
   *
   * @param string $pathname
   *   The page pathname.
   * @param int $days
   *   Number of days to query.
   * @param string $dimension
   *   The dimension (referrer, country, device, browser).
   * @param int $limit
   *   Maximum rows to return.
   *
   * @return array<int, array<string, mixed>>
   *   Array of result rows with 'key', 'pageviews', 'visitors'.
   */
  public function getDimensionData(string $pathname, int $days = 28, string $dimension = 'referrer', int $limit = 50): array {
    $cacheKey = $this->getCacheKey($pathname, "dim_{$dimension}", $days) . ":{$limit}";
    $cached = $this->cache->get($cacheKey);
    if ($cached) {
      return $cached->data;
    }

    $property = self::DIMENSION_MAP[$dimension] ?? self::DIMENSION_MAP['referrer'];
    $pathFilter = $this->buildPathFilter($this->escapeHogql($pathname));
    $timeFilter = $this->buildTimeFilter($days);

    $result = $this->hogqlQuery(
      $this->buildDimensionQuery($property, $pathFilter, $timeFilter, $limit)
    );
    $rows = $this->parseDimensionResults($result);

    $this->cacheSet($cacheKey, $rows);
    return $rows;
  }

  /**
   * Get previous period dimension data for a page.
   *
   * @param string $pathname
   *   The page pathname.
   * @param int $days
   *   Number of days for current period.
   * @param string $dimension
   *   The dimension.
   * @param int $limit
   *   Maximum rows.
   *
   * @return array<int, array<string, mixed>>
   *   Previous period rows.
   */
  public function getPreviousPeriodDimensionData(string $pathname, int $days = 28, string $dimension = 'referrer', int $limit = 50): array {
    $cacheKey = $this->getCacheKey($pathname, "prev_dim_{$dimension}", $days) . ":{$limit}";
    $cached = $this->cache->get($cacheKey);
    if ($cached) {
      return $cached->data;
    }

    $property = self::DIMENSION_MAP[$dimension] ?? self::DIMENSION_MAP['referrer'];
    $pathFilter = $this->buildPathFilter($this->escapeHogql($pathname));
    $timeFilter = $this->buildTimeFilter($days, TRUE);

    $result = $this->hogqlQuery(
      $this->buildDimensionQuery($property, $pathFilter, $timeFilter, $limit)
    );
    $rows = $this->parseDimensionResults($result);

    $this->cacheSet($cacheKey, $rows);
    return $rows;
  }

  /**
   * Get sitewide aggregate metrics.
   *
   * @param int $days
   *   Number of days.
   * @param string $country
   *   Optional country name filter.
   *
   * @return array<string, float>|null
   *   Array with pageviews, visitors, sessions, bounce_rate keys, or NULL.
   */
  public function getSitewideMetrics(int $days = 28, string $country = ''): ?array {
    $cacheKey = "analyze_posthog:sitewide:metrics:{$days}:" . md5($country);
    $cached = $this->cache->get($cacheKey);
    if ($cached) {
      return $cached->data;
    }

    $countryFilter = $this->buildCountryFilter($country);
    $timeFilter = $this->buildTimeFilter($days);

    $mainResult = $this->hogqlQuery(
      $this->buildMetricsQuery('', $timeFilter, $countryFilter)
    );
    if ($mainResult === NULL || empty($mainResult['results'])) {
      $this->cacheSet($cacheKey, NULL);
      return NULL;
    }

    $row = $mainResult['results'][0];
    $pageviews = (float) ($row[0] ?? 0);
    $visitors = (float) ($row[1] ?? 0);
    $sessions = (float) ($row[2] ?? 0);

    $bounceResult = $this->hogqlQuery(
      $this->buildSitewideBounceQuery($timeFilter, $countryFilter)
    );
    $bounced = 0;
    if ($bounceResult !== NULL && !empty($bounceResult['results'])) {
      $bounced = (float) ($bounceResult['results'][0][0] ?? 0);
    }
    $bounceRate = $sessions > 0 ? ($bounced / $sessions) * 100 : 0;

    $result = [
      'pageviews' => $pageviews,
      'visitors' => $visitors,
      'sessions' => $sessions,
      'bounce_rate' => $bounceRate,
    ];

    $this->cacheSet($cacheKey, $result);
    return $result;
  }

  /**
   * Get sitewide metrics with comparison to previous period.
   *
   * @param int $days
   *   Number of days.
   * @param string $country
   *   Optional country name filter.
   *
   * @return array<string, mixed>|null
   *   Array with 'current', 'previous', and 'change' sub-arrays, or NULL.
   */
  public function getSitewideMetricsWithComparison(int $days = 28, string $country = ''): ?array {
    $cacheKey = "analyze_posthog:sitewide:comparison:{$days}:" . md5($country);
    $cached = $this->cache->get($cacheKey);
    if ($cached) {
      return $cached->data;
    }

    $current = $this->getSitewideMetrics($days, $country);
    if ($current === NULL) {
      return NULL;
    }

    $countryFilter = $this->buildCountryFilter($country);
    $prevTimeFilter = $this->buildTimeFilter($days, TRUE);

    $prevResult = $this->hogqlQuery(
      $this->buildMetricsQuery('', $prevTimeFilter, $countryFilter)
    );
    $previous = NULL;
    if ($prevResult !== NULL && !empty($prevResult['results'])) {
      $pRow = $prevResult['results'][0];
      $prevPageviews = (float) ($pRow[0] ?? 0);
      $prevVisitors = (float) ($pRow[1] ?? 0);
      $prevSessions = (float) ($pRow[2] ?? 0);

      // Previous sitewide bounce rate.
      $prevBounceResult = $this->hogqlQuery(
        $this->buildSitewideBounceQuery($prevTimeFilter, $countryFilter)
      );
      $prevBounced = 0;
      if ($prevBounceResult !== NULL && !empty($prevBounceResult['results'])) {
        $prevBounced = (float) ($prevBounceResult['results'][0][0] ?? 0);
      }
      $prevBounceRate = $prevSessions > 0 ? ($prevBounced / $prevSessions) * 100 : 0;

      $previous = [
        'pageviews' => $prevPageviews,
        'visitors' => $prevVisitors,
        'sessions' => $prevSessions,
        'bounce_rate' => $prevBounceRate,
      ];
    }

    $change = $this->calculateChange($current, $previous);

    $result = [
      'current' => $current,
      'previous' => $previous,
      'change' => $change,
    ];

    $this->cacheSet($cacheKey, $result);
    return $result;
  }

  /**
   * Get sitewide dimension data.
   *
   * @param int $days
   *   Number of days.
   * @param string $dimension
   *   The dimension.
   * @param int $limit
   *   Maximum rows.
   * @param string $country
   *   Optional country name filter.
   *
   * @return array<int, array<string, mixed>>
   *   Result rows.
   */
  public function getSitewideDimensionData(int $days = 28, string $dimension = 'referrer', int $limit = 100, string $country = ''): array {
    $cacheKey = "analyze_posthog:sitewide:dim_{$dimension}:{$days}:{$limit}:" . md5($country);
    $cached = $this->cache->get($cacheKey);
    if ($cached) {
      return $cached->data;
    }

    $property = self::DIMENSION_MAP[$dimension] ?? self::DIMENSION_MAP['referrer'];
    $countryFilter = $this->buildCountryFilter($country);
    $timeFilter = $this->buildTimeFilter($days);

    $result = $this->hogqlQuery(
      $this->buildDimensionQuery($property, '', $timeFilter, $limit, $countryFilter)
    );
    $rows = $this->parseDimensionResults($result);

    $this->cacheSet($cacheKey, $rows);
    return $rows;
  }

  /**
   * Get sitewide previous period dimension data.
   *
   * @param int $days
   *   Number of days for current period.
   * @param string $dimension
   *   The dimension.
   * @param int $limit
   *   Maximum rows.
   * @param string $country
   *   Optional country name filter.
   *
   * @return array<int, array<string, mixed>>
   *   Previous period rows.
   */
  public function getSitewidePrevDimensionData(int $days = 28, string $dimension = 'referrer', int $limit = 100, string $country = ''): array {
    $cacheKey = "analyze_posthog:sitewide:prev_dim_{$dimension}:{$days}:{$limit}:" . md5($country);
    $cached = $this->cache->get($cacheKey);
    if ($cached) {
      return $cached->data;
    }

    $property = self::DIMENSION_MAP[$dimension] ?? self::DIMENSION_MAP['referrer'];
    $countryFilter = $this->buildCountryFilter($country);
    $timeFilter = $this->buildTimeFilter($days, TRUE);

    $result = $this->hogqlQuery(
      $this->buildDimensionQuery($property, '', $timeFilter, $limit, $countryFilter)
    );
    $rows = $this->parseDimensionResults($result);

    $this->cacheSet($cacheKey, $rows);
    return $rows;
  }

  /**
   * Build a HogQL query for aggregate metrics (pageviews, visitors, sessions).
   *
   * @param string $pathFilter
   *   Path filter clause (empty for sitewide).
   * @param string $timeFilter
   *   Time filter clause.
   * @param string $countryFilter
   *   Country filter clause (empty if none).
   *
   * @return string
   *   The HogQL query.
   */
  protected function buildMetricsQuery(string $pathFilter, string $timeFilter, string $countryFilter = ''): string {
    return "SELECT count() as pageviews, "
      . "count(DISTINCT distinct_id) as visitors, "
      . "count(DISTINCT properties.\$session_id) as sessions "
      . "FROM events "
      . "WHERE event = '\$pageview' "
      . $pathFilter
      . $countryFilter
      . $timeFilter;
  }

  /**
   * Build a HogQL query for dimension breakdown.
   *
   * @param string $property
   *   The PostHog property to group by.
   * @param string $pathFilter
   *   Path filter clause (empty for sitewide).
   * @param string $timeFilter
   *   Time filter clause.
   * @param int $limit
   *   Max rows.
   * @param string $countryFilter
   *   Country filter clause (empty if none).
   *
   * @return string
   *   The HogQL query.
   */
  protected function buildDimensionQuery(string $property, string $pathFilter, string $timeFilter, int $limit, string $countryFilter = ''): string {
    return "SELECT properties.{$property} as key, "
      . "count() as pageviews, "
      . "count(DISTINCT distinct_id) as visitors "
      . "FROM events "
      . "WHERE event = '\$pageview' "
      . $pathFilter
      . $countryFilter
      . $timeFilter . " "
      . "GROUP BY key "
      . "ORDER BY pageviews DESC "
      . "LIMIT {$limit}";
  }

  /**
   * Build a HogQL query for sitewide bounce rate.
   *
   * @param string $timeFilter
   *   Time filter clause.
   * @param string $countryFilter
   *   Country filter clause (empty if none).
   *
   * @return string
   *   The HogQL query.
   */
  protected function buildSitewideBounceQuery(string $timeFilter, string $countryFilter = ''): string {
    return "SELECT count(DISTINCT session_id) as bounced "
      . "FROM ("
      . "SELECT properties.\$session_id as session_id, count() as pv "
      . "FROM events "
      . "WHERE event = '\$pageview' "
      . $countryFilter
      . $timeFilter . " "
      . "GROUP BY session_id "
      . "HAVING pv = 1"
      . ")";
  }

  /**
   * Build the time filter clause for current or previous period.
   *
   * @param int $days
   *   Number of days for the period.
   * @param bool $previousPeriod
   *   TRUE to query the previous period.
   *
   * @return string
   *   SQL clause starting with "AND".
   */
  protected function buildTimeFilter(int $days, bool $previousPeriod = FALSE): string {
    if ($previousPeriod) {
      return "AND timestamp > now() - interval " . ($days * 2) . " day "
        . "AND timestamp <= now() - interval {$days} day";
    }
    return "AND timestamp > now() - interval {$days} day";
  }

  /**
   * Build the path filter clause.
   *
   * @param string $escapedPath
   *   Escaped pathname (empty for sitewide).
   *
   * @return string
   *   SQL clause or empty string.
   */
  protected function buildPathFilter(string $escapedPath): string {
    if ($escapedPath === '') {
      return '';
    }
    return "AND properties.\$pathname = '{$escapedPath}' ";
  }

  /**
   * Build the country filter clause.
   *
   * @param string $country
   *   Country name (empty for no filter).
   *
   * @return string
   *   SQL clause or empty string.
   */
  protected function buildCountryFilter(string $country): string {
    if (empty($country)) {
      return '';
    }
    $escaped = $this->escapeHogql($country);
    return "AND properties.\$geoip_country_name = '{$escaped}' ";
  }

  /**
   * Execute a HogQL query against the PostHog API.
   *
   * @param string $query
   *   The HogQL query string.
   *
   * @return array<string, mixed>|null
   *   The response with 'columns' and 'results' keys, or NULL on error.
   */
  protected function hogqlQuery(string $query): ?array {
    $config = $this->configFactory->get('analyze_posthog.settings');
    $host = rtrim((string) $config->get('host'), '/');
    $projectId = $config->get('project_id');
    $apiKey = $this->resolveKey((string) $config->get('personal_api_key'));

    if (empty($host) || empty($projectId) || empty($apiKey)) {
      return NULL;
    }

    $url = $host . '/api/projects/' . $projectId . '/query/';

    try {
      $response = $this->httpClient->request('POST', $url, [
        'headers' => [
          'Authorization' => 'Bearer ' . $apiKey,
          'Content-Type' => 'application/json',
        ],
        'json' => [
          'query' => [
            'kind' => 'HogQLQuery',
            'query' => $query,
          ],
        ],
        'timeout' => 30,
      ]);

      $body = json_decode((string) $response->getBody(), TRUE);
      if (!is_array($body)) {
        return NULL;
      }

      return [
        'columns' => $body['columns'] ?? [],
        'results' => $body['results'] ?? [],
      ];
    }
    catch (\Exception $e) {
      $this->logger->error('PostHog API error: @message', [
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Parse dimension query results into a standard format.
   *
   * @param array<string, mixed>|null $result
   *   Raw API result.
   *
   * @return array<int, array<string, mixed>>
   *   Parsed rows with key, pageviews, visitors.
   */
  protected function parseDimensionResults(?array $result): array {
    $rows = [];
    if ($result === NULL || empty($result['results'])) {
      return $rows;
    }

    foreach ($result['results'] as $row) {
      $key = (string) ($row[0] ?? '');
      if ($key === '' || $key === 'null') {
        $key = '(not set)';
      }
      $rows[] = [
        'key' => $key,
        'pageviews' => (float) ($row[1] ?? 0),
        'visitors' => (float) ($row[2] ?? 0),
      ];
    }

    return $rows;
  }

  /**
   * Calculate bounce rate for a specific page.
   *
   * @param string $escapedPath
   *   The escaped pathname.
   * @param int $days
   *   Number of days.
   * @param float $totalSessions
   *   Total sessions for the page.
   * @param int $offsetDays
   *   If > 0, query previous period ending this many days ago.
   *
   * @return float
   *   Bounce rate as a percentage (0-100).
   */
  protected function calculateBounceRate(string $escapedPath, int $days, float $totalSessions, int $offsetDays = 0): float {
    if ($totalSessions <= 0) {
      return 0;
    }

    $timeFilter = $offsetDays > 0
      ? "AND timestamp > now() - interval {$days} day AND timestamp <= now() - interval {$offsetDays} day"
      : "AND timestamp > now() - interval {$days} day";

    $bounceSql = "SELECT count(DISTINCT session_id) as bounced "
      . "FROM ("
      . "SELECT properties.\$session_id as session_id, count() as pv "
      . "FROM events "
      . "WHERE event = '\$pageview' "
      . "AND properties.\$session_id IN ("
      . "SELECT DISTINCT properties.\$session_id "
      . "FROM events "
      . "WHERE event = '\$pageview' "
      . "AND properties.\$pathname = '{$escapedPath}' "
      . $timeFilter
      . ") "
      . $timeFilter . " "
      . "GROUP BY session_id "
      . "HAVING pv = 1"
      . ")";

    $bounceResult = $this->hogqlQuery($bounceSql);
    $bounced = 0;
    if ($bounceResult !== NULL && !empty($bounceResult['results'])) {
      $bounced = (float) ($bounceResult['results'][0][0] ?? 0);
    }

    return ($bounced / $totalSessions) * 100;
  }

  /**
   * Calculate average time on page.
   *
   * @param string $escapedPath
   *   The escaped pathname.
   * @param int $days
   *   Number of days.
   * @param int $offsetDays
   *   If > 0, query previous period ending this many days ago.
   *
   * @return float
   *   Average time in seconds.
   */
  protected function calculateAvgTimeOnPage(string $escapedPath, int $days, int $offsetDays = 0): float {
    $timeFilter = $offsetDays > 0
      ? "AND pv.timestamp > now() - interval {$days} day AND pv.timestamp <= now() - interval {$offsetDays} day"
      : "AND pv.timestamp > now() - interval {$days} day";

    $sql = "SELECT avg(dateDiff('second', pv.timestamp, pl.timestamp)) "
      . "FROM events pv "
      . "JOIN events pl "
      . "ON pv.properties.\$session_id = pl.properties.\$session_id "
      . "AND pv.properties.\$pageview_id = pl.properties.\$prev_pageview_id "
      . "WHERE pv.event = '\$pageview' "
      . "AND pl.event = '\$pageleave' "
      . "AND pv.properties.\$pathname = '{$escapedPath}' "
      . $timeFilter;

    $result = $this->hogqlQuery($sql);
    if ($result !== NULL && !empty($result['results']) && $result['results'][0][0] !== NULL) {
      return (float) $result['results'][0][0];
    }

    return 0;
  }

  /**
   * Calculate change metrics between current and previous periods.
   *
   * @param array<string, float> $current
   *   Current period metrics.
   * @param array<string, float>|null $previous
   *   Previous period metrics, or NULL.
   *
   * @return array<string, array{value: float, formatted: string}>|null
   *   Change data per metric, or NULL if no previous data.
   */
  protected function calculateChange(array $current, ?array $previous): ?array {
    if ($previous === NULL) {
      return NULL;
    }

    $change = [];
    $pctMetrics = ['pageviews', 'visitors', 'sessions'];
    foreach ($pctMetrics as $metric) {
      $cur = $current[$metric] ?? 0;
      $prev = $previous[$metric] ?? 0;
      if ($prev > 0) {
        $pct = (($cur - $prev) / $prev) * 100;
        $sign = $pct >= 0 ? '+' : '';
        $change[$metric] = [
          'value' => $pct,
          'formatted' => $sign . number_format($pct, 1) . '%',
        ];
      }
      else {
        $change[$metric] = [
          'value' => $cur > 0 ? 100.0 : 0.0,
          'formatted' => $cur > 0 ? '+100.0%' : '0.0%',
        ];
      }
    }

    // Bounce rate: absolute percentage point change.
    $curBounce = $current['bounce_rate'] ?? 0;
    $prevBounce = $previous['bounce_rate'] ?? 0;
    $bounceDiff = $curBounce - $prevBounce;
    $sign = $bounceDiff >= 0 ? '+' : '';
    $change['bounce_rate'] = [
      'value' => $bounceDiff,
      'formatted' => $sign . number_format($bounceDiff, 1) . '%',
    ];

    // Avg time on page (if present): absolute change in seconds.
    if (isset($current['avg_time'])) {
      $curTime = $current['avg_time'] ?? 0;
      $prevTime = $previous['avg_time'] ?? 0;
      $timeDiff = $curTime - $prevTime;
      $sign = $timeDiff >= 0 ? '+' : '';
      $change['avg_time'] = [
        'value' => $timeDiff,
        'formatted' => $sign . $this->formatDuration($timeDiff),
      ];
    }

    return $change;
  }

  /**
   * Format duration in seconds to mm:ss format.
   *
   * @param float $seconds
   *   Duration in seconds.
   *
   * @return string
   *   Formatted duration.
   */
  public function formatDuration(float $seconds): string {
    $abs = abs($seconds);
    $mins = (int) floor($abs / 60);
    $secs = (int) ($abs % 60);
    return $mins . ':' . str_pad((string) $secs, 2, '0', STR_PAD_LEFT);
  }

  /**
   * Escape a value for use in a HogQL query string.
   *
   * @param string $value
   *   The value to escape.
   *
   * @return string
   *   The escaped value.
   */
  protected function escapeHogql(string $value): string {
    return str_replace("'", "\\'", $value);
  }

  /**
   * Build a cache key.
   *
   * @param string $pathname
   *   The pathname.
   * @param string $type
   *   The metric type.
   * @param int $days
   *   Number of days.
   *
   * @return string
   *   The cache key.
   */
  protected function getCacheKey(string $pathname, string $type, int $days): string {
    $config = $this->configFactory->get('analyze_posthog.settings');
    $hostHash = md5((string) $config->get('host'));
    $pathHash = md5($pathname);
    return "analyze_posthog:{$hostHash}:{$pathHash}:{$type}:{$days}";
  }

  /**
   * Set a cache entry.
   *
   * @param string $key
   *   Cache key.
   * @param mixed $data
   *   Data to cache.
   */
  protected function cacheSet(string $key, mixed $data): void {
    $config = $this->configFactory->get('analyze_posthog.settings');
    $ttl = (int) $config->get('cache_ttl') ?: 21600;
    $this->cache->set($key, $data, time() + $ttl, ['analyze_posthog']);
  }

}
