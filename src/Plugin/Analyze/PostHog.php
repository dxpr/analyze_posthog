<?php

declare(strict_types=1);

namespace Drupal\analyze_posthog\Plugin\Analyze;

use Drupal\analyze\AnalyzePluginBase;
use Drupal\analyze\HelperInterface;
use Drupal\analyze_posthog\Form\ReportFilterForm;
use Drupal\analyze_posthog\Service\PostHogClient;
use Drupal\analyze_posthog\Service\ReportBuilder;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Link;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Analyze plugin to display PostHog analytics data.
 *
 * @Analyze(
 *   id = "posthog_analytics",
 *   label = @Translation("PostHog Analytics"),
 *   description = @Translation("Displays PostHog pageview analytics for entities with URL paths.")
 * )
 */
final class PostHog extends AnalyzePluginBase {

  /**
   * Creates the plugin.
   *
   * @param array<string, mixed> $configuration
   *   Configuration.
   * @param string $plugin_id
   *   Plugin ID.
   * @param array<string, mixed> $plugin_definition
   *   Plugin Definition.
   * @param \Drupal\analyze\HelperInterface $helper
   *   Analyze helper service.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   Config Factory.
   * @param \Drupal\analyze_posthog\Service\PostHogClient $client
   *   The PostHog client.
   * @param \Drupal\analyze_posthog\Service\ReportBuilder $reportBuilder
   *   The shared report builder.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack.
   * @param \Drupal\Core\Form\FormBuilderInterface $formBuilder
   *   The form builder.
   * @param \Drupal\Core\Pager\PagerManagerInterface $pagerManager
   *   The pager manager.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    HelperInterface $helper,
    AccountProxyInterface $currentUser,
    ConfigFactoryInterface $configFactory,
    protected readonly PostHogClient $client,
    protected readonly ReportBuilder $reportBuilder,
    protected readonly RequestStack $requestStack,
    protected readonly FormBuilderInterface $formBuilder,
    protected readonly PagerManagerInterface $pagerManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $helper, $currentUser, $configFactory);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('analyze.helper'),
      $container->get('current_user'),
      $container->get('config.factory'),
      $container->get('analyze_posthog.client'),
      $container->get('analyze_posthog.report_builder'),
      $container->get('request_stack'),
      $container->get('form_builder'),
      $container->get('pager.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function isApplicable(string $entity_type, ?string $bundle = NULL): bool {
    return $this->client->isConfigured();
  }

  /**
   * {@inheritdoc}
   */
  public function isEnabled(EntityInterface $entity): bool {
    return parent::isEnabled($entity) && $this->client->isConfigured();
  }

  /**
   * {@inheritdoc}
   */
  public function access(EntityInterface $entity): bool {
    return $this->currentUser->hasPermission('access posthog analytics');
  }

  /**
   * {@inheritdoc}
   */
  public function renderSummary(EntityInterface $entity): array {
    if (!$this->client->isConfigured()) {
      return $this->createStatusTable($this->t('PostHog analytics is not configured.'));
    }

    $pathname = $this->client->getEntityUrl($entity);
    if ($pathname === NULL) {
      return $this->createStatusTable($this->t('This entity does not have a URL path.'));
    }

    $config = $this->configFactory->get('analyze_posthog.settings');
    $days = (int) $config->get('date_range') ?: 28;

    $data = $this->client->getPageMetricsWithComparison($pathname, $days);

    if ($data === NULL || $data['current'] === NULL) {
      return $this->createStatusTable($this->t('No analytics data available for this page yet.'));
    }

    $current = $data['current'];
    $change = $data['change'];

    $pageviews = number_format((int) $current['pageviews']);
    $visitors = number_format((int) $current['visitors']);
    $sessions = number_format((int) $current['sessions']);
    $bounceRate = number_format($current['bounce_rate'], 1) . '%';
    $avgTime = $this->client->formatDuration($current['avg_time']);

    if ($change) {
      $pageviews .= ' (' . $change['pageviews']['formatted'] . ')';
      $visitors .= ' (' . $change['visitors']['formatted'] . ')';
      $sessions .= ' (' . $change['sessions']['formatted'] . ')';
      $bounceRate .= ' (' . $change['bounce_rate']['formatted'] . ')';
      if (isset($change['avg_time'])) {
        $avgTime .= ' (' . $change['avg_time']['formatted'] . ')';
      }
    }

    return [
      '#theme' => 'analyze_table',
      '#table_title' => 'PostHog Analytics (Last ' . $days . ' Days)',
      '#rows' => [
        ['label' => 'Pageviews', 'data' => $pageviews],
        ['label' => 'Unique visitors', 'data' => $visitors],
        ['label' => 'Sessions', 'data' => $sessions],
        ['label' => 'Bounce rate', 'data' => $bounceRate],
        ['label' => 'Avg time on page', 'data' => $avgTime],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function renderFullReport(EntityInterface $entity): array {
    if (!$this->client->isConfigured()) {
      return $this->createStatusTable($this->t('PostHog analytics is not configured.'));
    }

    $pathname = $this->client->getEntityUrl($entity);
    if ($pathname === NULL) {
      return $this->createStatusTable($this->t('This entity does not have a URL path.'));
    }

    $config = $this->configFactory->get('analyze_posthog.settings');
    $defaultDays = (int) $config->get('date_range') ?: 28;

    // Read overrides from query parameters.
    $request = $this->requestStack->getCurrentRequest();
    $dimension = $request?->query->get('dimension') ?? 'referrer';
    $days = (int) ($request?->query->get('days') ?? $defaultDays);
    $statusFilter = $request?->query->get('status') ?? 'all';
    $querySearch = $request?->query->get('q') ?? '';

    // Validate.
    if (!in_array($dimension, ['referrer', 'country', 'device', 'browser'], TRUE)) {
      $dimension = 'referrer';
    }
    if (!in_array($days, [7, 14, 28, 90, 180, 365], TRUE)) {
      $days = $defaultDays;
    }

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['analyze-posthog-report']],
    ];

    // Filter form -- same Drupal form as sitewide report, with entity action
    // URL.
    $entityType = $entity->getEntityTypeId();
    $routeName = 'analyze.' . $entityType . '.' . $this->getPluginId();
    $actionUrl = Url::fromRoute(
      $routeName,
      [$entityType => $entity->id()]
    )->toString();
    $build['filters'] = $this->formBuilder->getForm(
      ReportFilterForm::class, $actionUrl, FALSE
    );
    $build['filters']['#weight'] = -10;

    // KPI summary -- below filters, responds to selected period.
    $metricsData = $this->client->getPageMetricsWithComparison($pathname, $days);
    if ($metricsData && $metricsData['current']) {
      $build['kpi'] = $this->reportBuilder->buildKpiTable(
        $metricsData,
        $this->reportBuilder->buildDateCaption($days)
      );
      $build['kpi']['#weight'] = -7;
    }

    // Fetch and enrich data.
    $currentRows = $this->client->getDimensionData(
      $pathname, $days, $dimension, 100
    );
    $prevRows = $this->client->getPreviousPeriodDimensionData(
      $pathname, $days, $dimension, 100
    );
    $enrichedRows = $this->reportBuilder->enrichWithComparison(
      $currentRows, $prevRows
    );

    // Apply search and status filters.
    $enrichedRows = $this->reportBuilder->filterRows(
      $enrichedRows, $statusFilter, $querySearch
    );

    // Paginate.
    $itemsPerPage = 20;
    $totalItems = count($enrichedRows);
    $currentPage = $this->pagerManager
      ->createPager($totalItems, $itemsPerPage)
      ->getCurrentPage();
    $pagedRows = array_slice(
      $enrichedRows,
      $currentPage * $itemsPerPage,
      $itemsPerPage
    );

    // Data table -- same builder as sitewide report.
    if (empty($pagedRows)) {
      $build['empty'] = [
        '#markup' => '<p>' . $this->t('No analytics data available for this page.') . '</p>',
      ];
    }
    else {
      $build['table'] = $this->reportBuilder->buildDataTable(
        $pagedRows, $dimension, $request, $days
      );
    }

    // Pager.
    $build['pager'] = [
      '#type' => 'pager',
      '#weight' => 50,
    ];

    // Source link.
    $host = $config->get('host');
    $build['source_link'] = [
      '#type' => 'container',
      '#weight' => 100,
      'link' => [
        '#type' => 'link',
        '#title' => $this->t('View in PostHog'),
        '#url' => Url::fromUri(rtrim((string) $host, '/') . '/web', [
          'attributes' => ['target' => '_blank', 'rel' => 'noopener'],
        ]),
        '#attributes' => ['class' => ['button', 'button--small']],
      ],
    ];

    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function getFullReportUrl(EntityInterface $entity): ?Url {
    $pathname = $this->client->getEntityUrl($entity);
    if ($pathname === NULL) {
      return NULL;
    }

    $config = $this->configFactory->get('analyze_posthog.settings');
    $days = (int) $config->get('date_range') ?: 28;

    $metrics = $this->client->getPageMetrics($pathname, $days);
    if ($metrics === NULL) {
      return NULL;
    }

    return parent::getFullReportUrl($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function extraSummaryLinks(EntityInterface $entity): array {
    $links = [];

    $config = $this->configFactory->get('analyze_posthog.settings');
    $host = $config->get('host');

    if ($host) {
      $links[] = [
        'title' => $this->t('View in PostHog'),
        'url' => Url::fromUri(rtrim((string) $host, '/') . '/web', [
          'attributes' => ['target' => '_blank', 'rel' => 'noopener'],
        ]),
      ];
    }

    return $links;
  }

  /**
   * Create a status table with an optional settings link.
   *
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|string $message
   *   The status message.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  protected function createStatusTable($message): array {
    if ($this->currentUser->hasPermission('administer analyze settings')) {
      $link = Link::createFromRoute($this->t('Configure settings'), 'analyze_posthog.settings');
      $message = $this->t('@message @link', [
        '@message' => $message,
        '@link' => $link->toString(),
      ]);
    }

    return [
      '#theme' => 'analyze_table',
      '#table_title' => $this->t('PostHog Analytics'),
      '#rows' => [
        [
          'label' => $this->t('Status'),
          'data' => $message,
        ],
      ],
    ];
  }

}
