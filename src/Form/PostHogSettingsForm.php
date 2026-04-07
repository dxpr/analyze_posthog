<?php

declare(strict_types=1);

namespace Drupal\analyze_posthog\Form;

use Drupal\analyze_posthog\Service\PostHogClient;
use Drupal\Component\Utility\Html;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure PostHog Analytics settings.
 */
final class PostHogSettingsForm extends ConfigFormBase {

  /**
   * Constructs a PostHogSettingsForm.
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
    $form = new static(
      $container->get('analyze_posthog.client'),
    );
    $form->setConfigFactory($container->get('config.factory'));
    $form->setMessenger($container->get('messenger'));
    $form->setStringTranslation($container->get('string_translation'));
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'analyze_posthog_settings';
  }

  /**
   * {@inheritdoc}
   *
   * @phpstan-return string[]
   */
  protected function getEditableConfigNames(): array {
    return ['analyze_posthog.settings'];
  }

  /**
   * {@inheritdoc}
   *
   * @phpstan-param array<string, mixed> $form
   * @phpstan-return array<string, mixed>
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('analyze_posthog.settings');
    $isConfigured = $this->client->isConfigured();

    // View reports link.
    if ($isConfigured) {
      $reportsUrl = Url::fromRoute('analyze_posthog.report');
      if ($reportsUrl->access()) {
        $form['actions_top'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['form-actions']],
          '#weight' => -15,
          'report_link' => [
            '#type' => 'link',
            '#title' => $this->t('View reports'),
            '#url' => $reportsUrl,
            '#attributes' => ['class' => ['button', 'button--small', 'button--primary']],
          ],
        ];
      }
    }

    // Setup instructions.
    $form['setup_instructions'] = [
      '#type' => 'details',
      '#title' => $this->t('Setup instructions'),
      '#open' => !$isConfigured,
      '#weight' => -10,
      '#attached' => ['library' => ['analyze_posthog/report']],
    ];

    $form['setup_instructions']['steps'] = [
      '#markup' =>
        '<ol>' .
          '<li>' .
          $this->t('<strong>Log in to your PostHog instance</strong> at <code>https://us.posthog.com</code> (US Cloud), <code>https://eu.posthog.com</code> (EU Cloud), or your self-hosted URL.') .
          '</li>' .
          '<li>' .
          $this->t('<strong>Create a Personal API key.</strong> Go to <em>Settings &rarr; Personal API Keys</em> and click <em>Create personal API key</em>. Give it a descriptive label (e.g., "Drupal Analyze") and ensure it has read access to your project.') .
          '</li>' .
          '<li>' .
          $this->t('<strong>Find your Project ID.</strong> Go to <em>Settings &rarr; Project</em>. The Project ID is displayed in the settings (usually a number).') .
          '</li>' .
          '<li>' .
          $this->t('<strong>Copy the host URL</strong> from your browser address bar (e.g., <code>https://us.posthog.com</code>).') .
          '</li>' .
          '<li>' .
          $this->t('<strong>Paste all three values below</strong> and click <em>Save configuration</em>.') .
          '</li>' .
          '</ol>',
    ];

    $form['host'] = [
      '#type' => 'textfield',
      '#title' => $this->t('PostHog host URL'),
      '#description' => $this->t('The base URL of your PostHog instance (e.g., <code>https://us.posthog.com</code> or <code>https://posthog.example.com</code>).'),
      '#default_value' => $config->get('host'),
      '#maxlength' => 512,
      '#required' => TRUE,
    ];

    $form['project_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Project ID'),
      '#description' => $this->t('Found in PostHog under Settings &rarr; Project. Usually a number.'),
      '#default_value' => $config->get('project_id'),
      '#maxlength' => 128,
      '#required' => TRUE,
    ];

    $form['personal_api_key'] = [
      '#type' => 'key_select',
      '#title' => $this->t('Personal API key'),
      '#description' => $this->t('Select a key containing your PostHog personal API key (starts with <code>phx_</code>).'),
      '#default_value' => $config->get('personal_api_key'),
      '#empty_option' => $this->t('- Select a key -'),
      '#key_description' => FALSE,
      '#required' => TRUE,
    ];

    // Connection status.
    if ($isConfigured) {
      $form['connection_status'] = [
        '#type' => 'container',
        '#attributes' => ['style' => 'padding:12px 16px;background:#e8f5e9;border:1px solid #a5d6a7;border-radius:4px;margin:16px 0;'],
        'status' => [
          '#markup' => '<strong>' . $this->t('Status:') . '</strong> ' . $this->t('Connected'),
        ],
      ];
    }
    else {
      $form['connection_status'] = [
        '#type' => 'container',
        '#attributes' => ['style' => 'padding:12px 16px;background:#f5f5f5;border:1px solid #e0e0e0;border-radius:4px;margin:16px 0;'],
        'status' => [
          '#markup' => '<strong>' . $this->t('Status:') . '</strong> ' . $this->t('Enter your PostHog credentials above and save to get started.'),
        ],
      ];
    }

    $form['date_range'] = [
      '#type' => 'select',
      '#title' => $this->t('Default date range'),
      '#options' => [
        7 => $this->t('Last 7 days'),
        14 => $this->t('Last 14 days'),
        28 => $this->t('Last 28 days'),
        90 => $this->t('Last 90 days'),
        180 => $this->t('Last 6 months'),
        365 => $this->t('Last year'),
      ],
      '#default_value' => $config->get('date_range') ?: 28,
    ];

    $form['cache_ttl'] = [
      '#type' => 'select',
      '#title' => $this->t('Cache duration'),
      '#description' => $this->t('How long to cache PostHog analytics data.'),
      '#options' => [
        3600 => $this->t('1 hour'),
        21600 => $this->t('6 hours'),
        43200 => $this->t('12 hours'),
        86400 => $this->t('24 hours'),
      ],
      '#default_value' => $config->get('cache_ttl') ?: 21600,
    ];

    // Conversion Goals section.
    $form['conversion_goals_section'] = [
      '#type' => 'details',
      '#title' => $this->t('Conversion Goals'),
      '#description' => $this->t('Define conversion goals to track which pages drive business outcomes. Each goal maps to a PostHog event name. Leave empty to disable conversion tracking.'),
      '#open' => !empty($config->get('conversion_goals')),
    ];

    // Fetch available events for the dropdown.
    $eventOptions = ['' => $this->t('- Select event -')];
    if ($isConfigured) {
      $availableEvents = $this->client->getAvailableEvents();
      foreach ($availableEvents as $eventData) {
        $eventOptions[$eventData['event']] = $eventData['event']
          . ' (' . number_format($eventData['count']) . ' in last 30 days)';
      }
    }
    // Store event options for use in building rows.
    $form_state->set('event_options', $eventOptions);

    $existingGoals = $config->get('conversion_goals') ?: [];
    // Always show existing goals + 1 empty row.
    $goalCount = count($existingGoals) + 1;

    $form['conversion_goals_section']['goals'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Label'),
        $this->t('Event'),
        $this->t('Fixed value'),
        $this->t('Value property'),
        $this->t('Currency'),
      ],
      '#empty' => $this->t('No conversion goals configured.'),
      '#tree' => TRUE,
    ];

    for ($i = 0; $i < $goalCount; $i++) {
      $goal = $existingGoals[$i] ?? [];
      $form['conversion_goals_section']['goals'][$i] = $this->buildGoalRow(
        $goal, $eventOptions
      );
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * Build a single goal table row.
   *
   * @param array<string, mixed> $goal
   *   Existing goal data (empty for a new row).
   * @param array<string, string> $eventOptions
   *   Available event options for the select.
   *
   * @return array<string, mixed>
   *   Form element for a table row.
   */
  protected function buildGoalRow(array $goal, array $eventOptions): array {
    $row = [];

    $row['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#title_display' => 'invisible',
      '#default_value' => $goal['label'] ?? '',
      '#size' => 20,
      '#maxlength' => 64,
      '#placeholder' => $this->t('e.g., Signup'),
    ];

    // Use select if we have event options, textfield as fallback.
    $currentEvent = $goal['event'] ?? '';
    if (count($eventOptions) > 1) {
      // If the current event is not in options, add it.
      if ($currentEvent !== '' && !isset($eventOptions[$currentEvent])) {
        $eventOptions[$currentEvent] = $currentEvent;
      }
      $row['event'] = [
        '#type' => 'select',
        '#title' => $this->t('Event'),
        '#title_display' => 'invisible',
        '#options' => $eventOptions,
        '#default_value' => $currentEvent,
      ];
    }
    else {
      $row['event'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Event'),
        '#title_display' => 'invisible',
        '#default_value' => $currentEvent,
        '#size' => 25,
        '#maxlength' => 255,
        '#placeholder' => $this->t('e.g., user_registered'),
      ];
    }

    $row['value'] = [
      '#type' => 'number',
      '#title' => $this->t('Fixed value'),
      '#title_display' => 'invisible',
      '#default_value' => $goal['value'] ?? 0,
      '#min' => 0,
      '#step' => 0.01,
      '#size' => 8,
    ];

    $row['value_property'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Value property'),
      '#title_display' => 'invisible',
      '#default_value' => $goal['value_property'] ?? '',
      '#size' => 15,
      '#maxlength' => 128,
      '#placeholder' => $this->t('e.g., amount'),
    ];

    $row['currency'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Currency'),
      '#title_display' => 'invisible',
      '#default_value' => $goal['currency'] ?? 'USD',
      '#size' => 5,
      '#maxlength' => 3,
      '#placeholder' => 'USD',
    ];

    return $row;
  }

  /**
   * {@inheritdoc}
   *
   * @phpstan-param array<string, mixed> $form
   * @phpstan-param-out array $form
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $host = $form_state->getValue('host');
    if (!empty($host) && !str_starts_with($host, 'https://')) {
      $form_state->setErrorByName('host', $this->t('The PostHog host URL must start with https://.'));
    }

    // Validate conversion goals: no duplicate events.
    $goalsNested = $form_state->getValue('conversion_goals_section');
    $goals = is_array($goalsNested) ? ($goalsNested['goals'] ?? []) : [];
    if (empty($goals)) {
      $goals = $form_state->getValue('goals') ?: [];
    }
    $seenEvents = [];
    foreach ($goals as $i => $goal) {
      $label = trim($goal['label'] ?? '');
      $event = trim($goal['event'] ?? '');
      // Skip completely empty rows.
      if ($label === '' && $event === '') {
        continue;
      }
      // Require both label and event if one is set.
      if ($label !== '' && $event === '') {
        $form_state->setErrorByName(
          "goals][$i][event",
          $this->t('Event name is required for goal "@label".', ['@label' => $label])
        );
      }
      if ($event !== '' && $label === '') {
        $form_state->setErrorByName(
          "goals][$i][label",
          $this->t('Label is required when an event is selected.')
        );
      }
      if ($event !== '' && isset($seenEvents[$event])) {
        $form_state->setErrorByName(
          "goals][$i][event",
          $this->t('Duplicate event "@event". Each goal must use a unique event.', ['@event' => $event])
        );
      }
      if ($event !== '') {
        $seenEvents[$event] = TRUE;
      }
    }

    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   *
   * @phpstan-param array<string, mixed> $form
   * @phpstan-param-out array $form
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Process conversion goals.
    $goalsNested = $form_state->getValue('conversion_goals_section');
    $rawGoals = is_array($goalsNested) ? ($goalsNested['goals'] ?? []) : [];
    if (empty($rawGoals)) {
      $rawGoals = $form_state->getValue('goals') ?: [];
    }
    $processedGoals = [];
    foreach ($rawGoals as $goal) {
      $label = trim($goal['label'] ?? '');
      $event = trim($goal['event'] ?? '');
      if ($label === '' || $event === '') {
        continue;
      }
      $id = Html::cleanCssIdentifier(strtolower($label));
      $processedGoals[] = [
        'id' => $id,
        'label' => $label,
        'event' => $event,
        'value' => (float) ($goal['value'] ?? 0),
        'value_property' => trim($goal['value_property'] ?? ''),
        'currency' => strtoupper(trim($goal['currency'] ?? 'USD')) ?: 'USD',
      ];
    }

    $this->config('analyze_posthog.settings')
      ->set('host', rtrim($form_state->getValue('host'), '/'))
      ->set('project_id', $form_state->getValue('project_id'))
      ->set('personal_api_key', $form_state->getValue('personal_api_key'))
      ->set('date_range', (int) $form_state->getValue('date_range'))
      ->set('cache_ttl', (int) $form_state->getValue('cache_ttl'))
      ->set('conversion_goals', $processedGoals)
      ->save();

    parent::submitForm($form, $form_state);

    // Test connection after save.
    if ($this->client->isConfigured()) {
      if ($this->client->testConnection()) {
        $this->messenger()->addStatus($this->t('Successfully connected to PostHog.'));
      }
      else {
        $this->messenger()->addError($this->t('Could not connect to PostHog. Please check your credentials.'));
      }
    }
  }

}
