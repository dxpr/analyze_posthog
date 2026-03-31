<?php

declare(strict_types=1);

namespace Drupal\analyze_posthog\Form;

use Drupal\analyze_posthog\Service\PostHogClient;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Filter form for PostHog analytics reports.
 *
 * Renders as a horizontal exposed-filter bar matching the core admin/content
 * page pattern (views-exposed-form with inline items).
 *
 * Reusable for both the sitewide report and entity-level reports by passing
 * a custom action URL as a form argument.
 */
final class ReportFilterForm extends FormBase {

  /**
   * Constructs the form.
   *
   * @param \Drupal\analyze_posthog\Service\PostHogClient $client
   *   The PostHog client.
   */
  public function __construct(
    protected readonly PostHogClient $client,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('analyze_posthog.client'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'analyze_posthog_report_filter';
  }

  /**
   * {@inheritdoc}
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param string $action_url
   *   Optional form action URL. Defaults to sitewide report route.
   * @param bool $show_country
   *   Whether to show the country filter (sitewide only).
   *
   * @phpstan-param array<string, mixed> $form
   * @phpstan-return array<string, mixed>
   */
  public function buildForm(array $form, FormStateInterface $form_state, string $action_url = '', bool $show_country = TRUE): array {
    $request = $this->getRequest();
    $config = $this->config('analyze_posthog.settings');
    $defaultDays = (int) $config->get('date_range') ?: 28;

    $dimension = $request->query->get('dimension', 'referrer');
    $days = (int) $request->query->get('days', (string) $defaultDays);
    $statusFilter = $request->query->get('status', 'all');
    $countryFilter = $request->query->get('country', '');
    $querySearch = $request->query->get('q', '');

    // Use GET method so filters appear in the URL.
    $form['#method'] = 'get';
    if ($action_url !== '') {
      $form['#action'] = $action_url;
    }
    else {
      $form['#action'] = Url::fromRoute('analyze_posthog.report')->toString();
    }
    // Match core Views exposed form structure for Claro styling.
    $form['#attributes']['class'][] = 'views-exposed-form';
    $form['#attributes']['class'][] = 'analyze-posthog-report-filter';
    $form['#attached']['library'][] = 'analyze_posthog/report';

    $dimensionOptions = [
      'referrer' => $this->t('Referrers'),
      'country' => $this->t('Countries'),
      'device' => $this->t('Devices'),
      'browser' => $this->t('Browsers'),
    ];
    // Page dimension only for sitewide reports.
    if ($show_country) {
      $dimensionOptions['page'] = $this->t('Pages');
    }

    $form['dimension'] = [
      '#type' => 'select',
      '#title' => $this->t('Dimension'),
      '#options' => $dimensionOptions,
      '#default_value' => $dimension,
      '#wrapper_attributes' => [
        'class' => ['views-exposed-form__item'],
      ],
    ];

    $form['days'] = [
      '#type' => 'select',
      '#title' => $this->t('Period'),
      '#options' => [
        7 => $this->t('Last 7 days'),
        14 => $this->t('Last 14 days'),
        28 => $this->t('Last 28 days'),
        90 => $this->t('Last 90 days'),
        180 => $this->t('Last 6 months'),
        365 => $this->t('Last year'),
      ],
      '#default_value' => $days,
      '#wrapper_attributes' => [
        'class' => ['views-exposed-form__item'],
      ],
    ];

    // Country filter (only for sitewide report, not entity reports).
    if ($show_country && $dimension !== 'country') {
      $countryOptions = ['' => $this->t('- Any -')];
      $countries = $this->client->getSitewideDimensionData($days, 'country', 20);
      foreach ($countries as $c) {
        $countryOptions[$c['key']] = $c['key'];
      }
      $form['country'] = [
        '#type' => 'select',
        '#title' => $this->t('Country'),
        '#options' => $countryOptions,
        '#default_value' => $countryFilter,
        '#wrapper_attributes' => [
          'class' => ['views-exposed-form__item'],
        ],
      ];
    }

    // Status filter.
    $form['status'] = [
      '#type' => 'select',
      '#title' => $this->t('Status'),
      '#options' => [
        'all' => $this->t('- Any -'),
        'up' => $this->t('Up'),
        'down' => $this->t('Down'),
        'new' => $this->t('New'),
        'lost' => $this->t('Lost'),
      ],
      '#default_value' => $statusFilter,
      '#wrapper_attributes' => [
        'class' => ['views-exposed-form__item'],
      ],
    ];

    // Query text filter.
    $form['q'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Search'),
      '#default_value' => $querySearch,
      '#size' => 20,
      '#maxlength' => 255,
      '#wrapper_attributes' => [
        'class' => ['views-exposed-form__item'],
      ],
    ];

    $form['actions'] = [
      '#type' => 'actions',
      '#attributes' => [
        'class' => [
          'views-exposed-form__item',
          'views-exposed-form__item--actions',
        ],
      ],
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Filter'),
    ];
    // Reset link -- matches core admin/content "Reset" button.
    $resetUrl = $action_url !== '' ? $action_url : Url::fromRoute('analyze_posthog.report')->toString();
    $form['actions']['reset'] = [
      '#type' => 'link',
      '#title' => $this->t('Reset'),
      '#url' => Url::fromUri('internal:' . $resetUrl),
      '#attributes' => [
        'class' => ['button'],
      ],
    ];

    // Since we use GET, suppress Drupal's form_build_id/form_token/form_id.
    $form['form_build_id'] = ['#access' => FALSE];
    $form['form_token'] = ['#access' => FALSE];
    $form['form_id'] = ['#access' => FALSE];

    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * @phpstan-param array<string, mixed> $form
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // No-op: filters are applied via GET parameters read by the controller.
  }

}
