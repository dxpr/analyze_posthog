<?php

namespace Drupal\analyze_posthog\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Hook implementations for analyze_posthog.
 */
class AnalyzePosthogHooks {
  use StringTranslationTrait;
  /**
   * @file
   * Provides PostHog analytics integration for the Analyze module.
   */

  /**
   * Implements hook_help().
   */
  #[Hook('help')]
  public function help($route_name, $route_match) {
    switch ($route_name) {
      case 'help.page.analyze_posthog':
        return '<p>' . $this->t('The Analyze PostHog module displays PostHog analytics data (pageviews, unique visitors, sessions, bounce rate) for entities with URL paths in the Analyze tab.') . '</p>';
    }
  }

}
