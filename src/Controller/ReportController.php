<?php

declare(strict_types=1);

namespace Drupal\analyze_posthog\Controller;

use Drupal\analyze_posthog\Form\ReportFilterForm;
use Drupal\analyze_posthog\Service\PostHogClient;
use Drupal\analyze_posthog\Service\ReportBuilder;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Sitewide PostHog analytics report page.
 */
final class ReportController extends ControllerBase {

  /**
   * Constructs the controller.
   *
   * @param \Drupal\analyze_posthog\Service\PostHogClient $client
   *   The PostHog client.
   * @param \Drupal\analyze_posthog\Service\ReportBuilder $reportBuilder
   *   The shared report builder.
   * @param \Drupal\Core\Pager\PagerManagerInterface $pagerManager
   *   The pager manager.
   */
  public function __construct(
    protected readonly PostHogClient $client,
    protected readonly ReportBuilder $reportBuilder,
    protected readonly PagerManagerInterface $pagerManager,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('analyze_posthog.client'),
      $container->get('analyze_posthog.report_builder'),
      $container->get('pager.manager'),
    );
  }

  /**
   * Render the sitewide PostHog analytics report.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function report(Request $request): array {
    if (!$this->client->isConfigured()) {
      return $this->buildNotConfigured();
    }

    $config = $this->config('analyze_posthog.settings');
    $defaultDays = (int) $config->get('date_range') ?: 28;
    $host = $config->get('host');

    // Read filter values from query parameters.
    $dimension = $request->query->get('dimension', 'referrer');
    $days = (int) $request->query->get('days', (string) $defaultDays);
    $statusFilter = $request->query->get('status', 'all');
    $querySearch = $request->query->get('q', '');
    $countryFilter = $request->query->get('country', '');

    // Validate inputs.
    if (!in_array($dimension, ['referrer', 'country', 'device', 'browser', 'page'], TRUE)) {
      $dimension = 'referrer';
    }
    if (!in_array($days, [7, 14, 28, 90, 180, 365], TRUE)) {
      $days = $defaultDays;
    }

    $build = [];

    // Property header -- show clean host.
    $displayHost = (string) $host;
    $displayHost = preg_replace('#^https?://#', '', $displayHost);
    $build['header'] = [
      '#markup' => '<p>' . $this->t('Data from <a href="@host" target="_blank">PostHog</a> for <strong>@display</strong>', [
        '@host' => $host,
        '@display' => $displayHost,
      ]) . '</p>',
      '#weight' => -15,
    ];

    // Exposed filter form.
    $build['filters'] = $this->formBuilder()->getForm(ReportFilterForm::class);
    $build['filters']['#weight'] = -10;

    // KPI summary cards -- below filters, responds to all selected filters.
    $metricsData = $this->client->getSitewideMetricsWithComparison(
      $days,
      $countryFilter
    );
    if ($metricsData && $metricsData['current']) {
      $build['kpi'] = $this->reportBuilder->buildKpiTable(
        $metricsData,
        $this->reportBuilder->buildDateCaption($days)
      );
      $build['kpi']['#weight'] = -7;
    }

    // Fetch and enrich data.
    $currentRows = $this->client->getSitewideDimensionData(
      $days, $dimension, 100, $countryFilter
    );
    $prevRows = $this->client->getSitewidePrevDimensionData(
      $days, $dimension, 100, $countryFilter
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

    // Data table.
    if (empty($pagedRows)) {
      $build['empty'] = [
        '#markup' => '<p>' . $this->t('No data found.') . '</p>',
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
    $build['source'] = [
      '#type' => 'container',
      '#weight' => 100,
      'link' => [
        '#type' => 'link',
        '#title' => $this->t('Open in PostHog'),
        '#url' => Url::fromUri(
          rtrim((string) $host, '/') . '/web',
          [
            'attributes' => ['target' => '_blank', 'rel' => 'noopener'],
          ]
        ),
        '#attributes' => ['class' => ['button', 'button--small']],
      ],
    ];

    $build['#cache'] = ['max-age' => 0];
    return $build;
  }

  /**
   * Build the "not configured" message.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  protected function buildNotConfigured(): array {
    $message = $this->t('PostHog analytics is not configured.');
    if ($this->currentUser()->hasPermission('administer analyze settings')) {
      $url = Url::fromRoute('analyze_posthog.settings')->toString();
      $message .= ' ' . $this->t(
        '<a href="@url">Configure settings</a>',
        ['@url' => $url]
      );
    }
    return ['message' => ['#markup' => '<p>' . $message . '</p>']];
  }

}
