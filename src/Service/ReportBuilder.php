<?php

declare(strict_types=1);

namespace Drupal\analyze_posthog\Service;

use Drupal\Core\Render\Markup;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\HttpFoundation\Request;

/**
 * Shared rendering logic for PostHog analytics reports.
 *
 * Used by both the sitewide ReportController and the entity-level
 * PostHog analyze plugin to ensure consistent layout and DRY code.
 */
final class ReportBuilder {

  use StringTranslationTrait;

  /**
   * Build KPI summary table from metrics data.
   *
   * @param array<string, mixed> $data
   *   Metrics with 'current', 'previous', and 'change' keys.
   * @param string $caption
   *   Optional table caption (e.g. date range description).
   *
   * @return array<string, mixed>
   *   A table render array.
   */
  public function buildKpiTable(array $data, string $caption = ''): array {
    $current = $data['current'];
    $change = $data['change'] ?? NULL;

    $header = [
      $this->t('Pageviews'),
      $this->t('Unique visitors'),
      $this->t('Sessions'),
      $this->t('Bounce rate'),
    ];

    $rows = [[
      $this->formatKpiCell(
        number_format((int) $current['pageviews']),
        $change ? $change['pageviews'] : NULL,
        TRUE
      ),
      $this->formatKpiCell(
        number_format((int) $current['visitors']),
        $change ? $change['visitors'] : NULL,
        TRUE
      ),
      $this->formatKpiCell(
        number_format((int) $current['sessions']),
        $change ? $change['sessions'] : NULL,
        TRUE
      ),
      // Bounce rate: lower is better, so positive change = bad.
      $this->formatKpiCell(
        number_format($current['bounce_rate'], 1) . '%',
        $change ? $change['bounce_rate'] : NULL,
        FALSE
      ),
    ]];

    $table = [
      '#theme' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#attributes' => ['class' => ['posthog-kpi']],
      '#attached' => ['library' => ['analyze_posthog/report']],
      '#weight' => -10,
    ];
    if ($caption !== '') {
      $table['#caption'] = $caption;
    }
    return $table;
  }

  /**
   * Format a single KPI cell with value and color-coded change indicator.
   *
   * @param string $value
   *   Formatted current value.
   * @param array{value: float, formatted: string}|null $change
   *   Change data, or NULL if no comparison.
   * @param bool $positiveIsGood
   *   TRUE if positive change is good (pageviews, visitors, sessions).
   *   FALSE if negative change is good (bounce rate: lower = better).
   *
   * @return array<string, mixed>
   *   A render array for the table cell.
   */
  public function formatKpiCell(string $value, ?array $change, bool $positiveIsGood): array {
    $html = '<strong class="ph-kpi-value">' . $value . '</strong>';

    if ($change !== NULL && $change['value'] != 0) {
      $isGood = $positiveIsGood
        ? ($change['value'] > 0)
        : ($change['value'] < 0);
      $class = $isGood ? 'ph-change--up' : 'ph-change--down';
      $direction = $change['value'] > 0
        ? $this->t('up')
        : $this->t('down');
      $arrow = $change['value'] > 0 ? '&#9650;' : '&#9660;';
      $changeText = ltrim($change['formatted'], '+-');
      $ariaLabel = $changeText . ' ' . $direction;
      $html .= '<br><span class="' . $class
        . '" role="text" aria-label="' . htmlspecialchars((string) $ariaLabel) . '">'
        . $arrow . ' ' . $changeText
        . '</span>';
    }
    elseif ($change !== NULL) {
      $html .= '<br><span class="ph-change--neutral">'
        . $this->t('No change') . '</span>';
    }

    return ['data' => ['#markup' => Markup::create($html)]];
  }

  /**
   * Format a percentage change indicator for a data table row.
   *
   * @param float|null $pctChange
   *   Percentage change in pageviews, or NULL.
   *
   * @return string
   *   HTML string for the change cell.
   */
  public function formatPercentageChange(?float $pctChange): string {
    if ($pctChange === NULL) {
      return '–';
    }
    if (abs($pctChange) < 0.1) {
      return '–';
    }
    $arrow = $pctChange > 0 ? '▲' : '▼';
    $class = $pctChange > 0 ? 'ph-change--up' : 'ph-change--down';
    $changeVal = number_format(abs($pctChange), 1) . '%';
    $direction = $pctChange > 0 ? $this->t('up') : $this->t('down');
    return '<span class="' . $class
      . '" role="text" aria-label="' . $changeVal . ' ' . $direction . '">'
      . $arrow . ' ' . $changeVal . '</span>';
  }

  /**
   * Format a status badge for a data table row.
   *
   * @param string $status
   *   Row status: 'new', 'lost', or other.
   *
   * @return string
   *   HTML badge string, or empty string.
   */
  public function formatStatusBadge(string $status): string {
    if ($status === 'new') {
      return ' <mark class="gin-new-flag">' . $this->t('New') . '</mark>';
    }
    if ($status === 'lost') {
      return ' <mark class="gin-experimental-flag">'
        . $this->t('Lost') . '</mark>';
    }
    return '';
  }

  /**
   * Enrich current-period rows with comparison data from previous period.
   *
   * Adds 'status' (new/lost/up/down/stable) and 'pct_change' to each row.
   * Status is based on pageview change (>10% threshold).
   *
   * @param array<int, array<string, mixed>> $currentRows
   *   Current period dimension data.
   * @param array<int, array<string, mixed>> $prevRows
   *   Previous period dimension data.
   *
   * @return array<int, array<string, mixed>>
   *   Enriched rows.
   */
  public function enrichWithComparison(array $currentRows, array $prevRows): array {
    $prevByKey = [];
    foreach ($prevRows as $pr) {
      $prevByKey[$pr['key']] = $pr;
    }

    $currentKeys = [];
    $enriched = [];
    foreach ($currentRows as $row) {
      $ck = $row['key'];
      $currentKeys[] = $ck;
      $prev = $prevByKey[$ck] ?? NULL;
      if ($prev === NULL) {
        $row['status'] = 'new';
        $row['pct_change'] = NULL;
      }
      else {
        $prevPv = $prev['pageviews'] ?? 0;
        $curPv = $row['pageviews'] ?? 0;
        if ($prevPv > 0) {
          $pct = (($curPv - $prevPv) / $prevPv) * 100;
          $row['pct_change'] = $pct;
          if ($pct > 10) {
            $row['status'] = 'up';
          }
          elseif ($pct < -10) {
            $row['status'] = 'down';
          }
          else {
            $row['status'] = 'stable';
          }
        }
        else {
          $row['pct_change'] = $curPv > 0 ? 100.0 : 0.0;
          $row['status'] = $curPv > 0 ? 'up' : 'stable';
        }
      }
      $enriched[] = $row;
    }

    // Add lost rows.
    foreach (array_diff(array_keys($prevByKey), $currentKeys) as $lostKey) {
      $prev = $prevByKey[$lostKey];
      $enriched[] = [
        'key' => $prev['key'],
        'pageviews' => 0,
        'visitors' => 0,
        'pct_change' => NULL,
        'status' => 'lost',
      ];
    }

    return $enriched;
  }

  /**
   * Build a sortable data table from enriched rows.
   *
   * @param array<int, array<string, mixed>> $rows
   *   Enriched data rows.
   * @param string $dimension
   *   Active dimension (referrer, country, device, browser, page).
   * @param \Symfony\Component\HttpFoundation\Request|null $request
   *   Request for tablesort parameters.
   * @param int $days
   *   Date range in days, used for the table caption.
   *
   * @return array<string, mixed>
   *   A table render array.
   */
  public function buildDataTable(array $rows, string $dimension, ?Request $request = NULL, int $days = 28): array {
    $dimensionLabel = $this->getDimensionLabel($dimension);

    $header = [
      ['data' => $dimensionLabel, 'field' => 'key', 'specifier' => 'key'],
      [
        'data' => $this->t('Change'),
        'field' => 'pct_change',
        'specifier' => 'pct_change',
      ],
      [
        'data' => $this->t('Pageviews'),
        'field' => 'pageviews',
        'specifier' => 'pageviews',
        'sort' => 'desc',
      ],
      [
        'data' => $this->t('Visitors'),
        'field' => 'visitors',
        'specifier' => 'visitors',
      ],
    ];

    // Read sort from request.
    $orderLabel = $request?->query->get('order', 'Pageviews') ?? 'Pageviews';
    $sortDir = ($request?->query->get('sort', 'desc') === 'asc') ? 'asc' : 'desc';
    $sortField = 'pageviews';
    foreach ($header as $col) {
      if ((string) $col['data'] === $orderLabel) {
        $sortField = $col['field'];
        break;
      }
    }

    usort($rows, function ($a, $b) use ($sortField, $sortDir) {
      $av = $a[$sortField] ?? 0;
      $bv = $b[$sortField] ?? 0;
      $cmp = $sortField === 'key'
        ? strcasecmp((string) $av, (string) $bv)
        : ($av <=> $bv);
      return $sortDir === 'asc' ? $cmp : -$cmp;
    });

    $tableRows = [];
    foreach ($rows as $row) {
      $keyValue = (string) $row['key'];
      $badge = $this->formatStatusBadge($row['status'] ?? '');
      $changeStr = $this->formatPercentageChange($row['pct_change'] ?? NULL);

      $tableRow = [
        [
          'data' => [
            '#markup' => Markup::create(
              htmlspecialchars($keyValue) . $badge
            ),
          ],
        ],
        ['data' => ['#markup' => Markup::create($changeStr)]],
        number_format((int) $row['pageviews']),
        number_format((int) $row['visitors']),
      ];
      $tableRows[] = $tableRow;
    }

    $pluralLabel = $this->getDimensionPluralLabel($dimension);
    $caption = $this->t('Top @dimension, @dates', [
      '@dimension' => $pluralLabel,
      '@dates' => $this->buildDateCaption($days),
    ]);

    return [
      '#theme' => 'table',
      '#header' => $header,
      '#rows' => $tableRows,
      '#caption' => $caption,
      '#attributes' => ['class' => ['posthog-report']],
      '#attached' => ['library' => ['analyze_posthog/report']],
      '#empty' => $this->t('No data available.'),
    ];
  }

  /**
   * Build the date comparison caption string.
   *
   * @param int $days
   *   The date range in days.
   *
   * @return string
   *   Formatted caption.
   */
  public function buildDateCaption(int $days): string {
    $endDate = new \DateTime();
    $startDate = (clone $endDate)->modify('-' . ($days - 1) . ' days');
    $prevEnd = (clone $startDate)->modify('-1 day');
    $prevStart = (clone $prevEnd)->modify('-' . ($days - 1) . ' days');

    return (string) $this->t('@start – @end compared to @prev_start – @prev_end', [
      '@start' => $startDate->format('M j'),
      '@end' => $endDate->format('M j, Y'),
      '@prev_start' => $prevStart->format('M j'),
      '@prev_end' => $prevEnd->format('M j'),
    ]);
  }

  /**
   * Filter enriched rows by status and text search.
   *
   * @param array<int, array<string, mixed>> $rows
   *   Enriched rows.
   * @param string $statusFilter
   *   Status filter: 'all' (no filter), 'up', 'down', 'new', 'lost'.
   * @param string $searchText
   *   Text to search for in dimension keys (case-insensitive).
   *
   * @return array<int, array<string, mixed>>
   *   Filtered rows.
   */
  public function filterRows(array $rows, string $statusFilter = 'all', string $searchText = ''): array {
    if (!empty($searchText)) {
      $rows = array_values(array_filter(
        $rows,
        fn($r) => stripos((string) $r['key'], $searchText) !== FALSE
      ));
    }
    if ($statusFilter !== 'all') {
      $rows = array_values(array_filter(
        $rows,
        fn($r) => $r['status'] === $statusFilter
      ));
    }
    return $rows;
  }

  /**
   * Build conversion KPI cells to append to the existing KPI table.
   *
   * @param array<string, mixed> $conversionData
   *   Current period conversion data.
   * @param array<string, mixed>|null $prevConversionData
   *   Previous period conversion data, or NULL.
   * @param bool $hasRevenue
   *   Whether any goal has revenue configured.
   *
   * @return array<string, array<string, mixed>>
   *   Keyed array of KPI cells: 'conversions' and optionally 'revenue'.
   */
  public function buildConversionKpiCells(array $conversionData, ?array $prevConversionData, bool $hasRevenue): array {
    $cells = [];

    $curConv = $conversionData['total_conversions'] ?? 0;
    $prevConv = $prevConversionData ? ($prevConversionData['total_conversions'] ?? 0) : NULL;
    $convChange = $this->calculateSingleChange($curConv, $prevConv);
    $cells['conversions'] = $this->formatKpiCell(
      number_format((int) $curConv),
      $convChange,
      TRUE
    );

    if ($hasRevenue) {
      $curRev = $conversionData['total_revenue'] ?? 0;
      $prevRev = $prevConversionData ? ($prevConversionData['total_revenue'] ?? 0) : NULL;
      $revChange = $this->calculateSingleChange($curRev, $prevRev);
      $cells['revenue'] = $this->formatKpiCell(
        '$' . number_format((float) $curRev, 2),
        $revChange,
        TRUE
      );
    }

    return $cells;
  }

  /**
   * Build a conversion breakdown table (the "Conversions" dimension tab).
   *
   * For entity reports: shows per-goal breakdown.
   * For sitewide reports: shows pages ranked by conversions.
   *
   * @param array<int, array<string, mixed>> $rows
   *   Enriched conversion rows.
   * @param bool $hasRevenue
   *   Whether any goal has revenue configured.
   * @param bool $isSitewide
   *   Whether this is the sitewide (pages) view.
   * @param \Symfony\Component\HttpFoundation\Request|null $request
   *   The request for tablesort.
   * @param int $days
   *   The date range in days.
   *
   * @return array<string, mixed>
   *   A table render array.
   */
  public function buildConversionTable(array $rows, bool $hasRevenue, bool $isSitewide, ?Request $request = NULL, int $days = 28): array {
    $firstColLabel = $isSitewide ? $this->t('Page') : $this->t('Goal');

    $header = [
      ['data' => $firstColLabel, 'field' => 'key', 'specifier' => 'key'],
      [
        'data' => $this->t('Change'),
        'field' => 'pct_change',
        'specifier' => 'pct_change',
      ],
      [
        'data' => $this->t('Conversions'),
        'field' => 'conversions',
        'specifier' => 'conversions',
        'sort' => 'desc',
      ],
    ];

    if ($hasRevenue) {
      $header[] = [
        'data' => $this->t('Conv. value'),
        'field' => 'revenue',
        'specifier' => 'revenue',
      ];
    }

    // Read sort from request.
    $orderLabel = $request?->query->get('order', 'Conversions') ?? 'Conversions';
    $sortDir = ($request?->query->get('sort', 'desc') === 'asc') ? 'asc' : 'desc';
    $sortField = 'conversions';
    foreach ($header as $col) {
      if ((string) $col['data'] === $orderLabel) {
        $sortField = $col['field'];
        break;
      }
    }

    usort($rows, function ($a, $b) use ($sortField, $sortDir) {
      $av = $a[$sortField] ?? 0;
      $bv = $b[$sortField] ?? 0;
      $cmp = $sortField === 'key'
        ? strcasecmp((string) $av, (string) $bv)
        : ($av <=> $bv);
      return $sortDir === 'asc' ? $cmp : -$cmp;
    });

    $tableRows = [];
    foreach ($rows as $row) {
      $keyValue = (string) $row['key'];
      $badge = $this->formatStatusBadge($row['status'] ?? '');
      $changeStr = $this->formatPercentageChange($row['pct_change'] ?? NULL);

      $tableRow = [
        [
          'data' => [
            '#markup' => Markup::create(
              htmlspecialchars($keyValue) . $badge
            ),
          ],
        ],
        ['data' => ['#markup' => Markup::create($changeStr)]],
        number_format((int) ($row['conversions'] ?? 0)),
      ];

      if ($hasRevenue) {
        $rev = (float) ($row['revenue'] ?? 0);
        $tableRow[] = $rev > 0 ? '$' . number_format($rev, 2) : '–';
      }

      $tableRows[] = $tableRow;
    }

    $dimLabel = $isSitewide
      ? (string) $this->t('pages by conversions')
      : (string) $this->t('conversions by goal');
    $caption = $this->t('Top @dimension, @dates', [
      '@dimension' => $dimLabel,
      '@dates' => $this->buildDateCaption($days),
    ]);

    return [
      '#theme' => 'table',
      '#header' => $header,
      '#rows' => $tableRows,
      '#caption' => $caption,
      '#attributes' => ['class' => ['posthog-report']],
      '#attached' => ['library' => ['analyze_posthog/report']],
      '#empty' => $this->t('No conversion data available.'),
    ];
  }

  /**
   * Print conversion KPI line for Drush output.
   *
   * @param array<string, mixed> $convData
   *   Current conversion data.
   * @param array<string, mixed>|null $prevConvData
   *   Previous conversion data.
   * @param bool $hasRevenue
   *   Whether revenue column should be shown.
   *
   * @return array{headers: string[], values: string[]}
   *   Headers and values for table output.
   */
  public function printConversionKpi(array $convData, ?array $prevConvData, bool $hasRevenue): array {
    $headers = ['Conversions'];
    $curConv = $convData['total_conversions'] ?? 0;
    $prevConv = $prevConvData ? ($prevConvData['total_conversions'] ?? 0) : NULL;
    $convStr = number_format((int) $curConv);
    if ($prevConv !== NULL && $prevConv > 0) {
      $pct = (($curConv - $prevConv) / $prevConv) * 100;
      $sign = $pct >= 0 ? '+' : '';
      $convStr .= ' (' . $sign . number_format($pct, 1) . '%)';
    }
    $values = [$convStr];

    if ($hasRevenue) {
      $headers[] = 'Conv. value';
      $curRev = $convData['total_revenue'] ?? 0;
      $prevRev = $prevConvData ? ($prevConvData['total_revenue'] ?? 0) : NULL;
      $revStr = '$' . number_format((float) $curRev, 2);
      if ($prevRev !== NULL && $prevRev > 0) {
        $pct = (($curRev - $prevRev) / $prevRev) * 100;
        $sign = $pct >= 0 ? '+' : '';
        $revStr .= ' (' . $sign . number_format($pct, 1) . '%)';
      }
      $values[] = $revStr;
    }

    return ['headers' => $headers, 'values' => $values];
  }

  /**
   * Print conversion table rows for Drush output.
   *
   * @param array<int, array<string, mixed>> $rows
   *   Enriched conversion rows.
   * @param bool $hasRevenue
   *   Whether to include revenue column.
   * @param bool $isSitewide
   *   Whether first column is Page (TRUE) or Goal (FALSE).
   *
   * @return array{headers: string[], rows: array<int, string[]>}
   *   Headers and table rows.
   */
  public function printConversionTable(array $rows, bool $hasRevenue, bool $isSitewide): array {
    $firstCol = $isSitewide ? 'Page' : 'Goal';
    $headers = [$firstCol, 'Change', 'Conversions'];
    if ($hasRevenue) {
      $headers[] = 'Conv. value';
    }
    $headers[] = 'Status';

    $tableRows = [];
    foreach ($rows as $row) {
      $changeStr = '–';
      $pctChange = $row['pct_change'] ?? NULL;
      if ($pctChange !== NULL && abs($pctChange) >= 0.1) {
        $arrow = $pctChange > 0 ? '▲' : '▼';
        $changeStr = $arrow . ' ' . number_format(abs($pctChange), 1) . '%';
      }

      $tableRow = [
        (string) $row['key'],
        $changeStr,
        number_format((int) ($row['conversions'] ?? 0)),
      ];

      if ($hasRevenue) {
        $rev = (float) ($row['revenue'] ?? 0);
        $tableRow[] = $rev > 0 ? '$' . number_format($rev, 2) : '–';
      }

      $tableRow[] = $row['status'] ?? 'stable';
      $tableRows[] = $tableRow;
    }

    return ['headers' => $headers, 'rows' => $tableRows];
  }

  /**
   * Enrich conversion rows with comparison data.
   *
   * Uses 'conversions' field instead of 'pageviews' for status calculation.
   *
   * @param array<int, array<string, mixed>> $currentRows
   *   Current period conversion rows.
   * @param array<int, array<string, mixed>> $prevRows
   *   Previous period conversion rows.
   *
   * @return array<int, array<string, mixed>>
   *   Enriched rows with 'status' and 'pct_change'.
   */
  public function enrichConversionComparison(array $currentRows, array $prevRows): array {
    $prevByKey = [];
    foreach ($prevRows as $pr) {
      $prevByKey[$pr['key']] = $pr;
    }

    $currentKeys = [];
    $enriched = [];
    foreach ($currentRows as $row) {
      $ck = $row['key'];
      $currentKeys[] = $ck;
      $prev = $prevByKey[$ck] ?? NULL;
      if ($prev === NULL) {
        $row['status'] = 'new';
        $row['pct_change'] = NULL;
      }
      else {
        $prevConv = $prev['conversions'] ?? 0;
        $curConv = $row['conversions'] ?? 0;
        if ($prevConv > 0) {
          $pct = (($curConv - $prevConv) / $prevConv) * 100;
          $row['pct_change'] = $pct;
          if ($pct > 10) {
            $row['status'] = 'up';
          }
          elseif ($pct < -10) {
            $row['status'] = 'down';
          }
          else {
            $row['status'] = 'stable';
          }
        }
        else {
          $row['pct_change'] = $curConv > 0 ? 100.0 : 0.0;
          $row['status'] = $curConv > 0 ? 'up' : 'stable';
        }
      }
      $enriched[] = $row;
    }

    // Add lost rows.
    foreach (array_diff(array_keys($prevByKey), $currentKeys) as $lostKey) {
      $prev = $prevByKey[$lostKey];
      $enriched[] = [
        'key' => $prev['key'],
        'conversions' => 0,
        'revenue' => 0.0,
        'pageviews' => 0,
        'visitors' => 0,
        'pct_change' => NULL,
        'status' => 'lost',
      ];
    }

    return $enriched;
  }

  /**
   * Check whether any conversion goal has revenue configured.
   *
   * @param array<int, array<string, mixed>> $goals
   *   Conversion goals from config.
   *
   * @return bool
   *   TRUE if at least one goal has a fixed value or value_property.
   */
  public function goalsHaveRevenue(array $goals): bool {
    foreach ($goals as $goal) {
      if (((float) ($goal['value'] ?? 0)) > 0 || !empty($goal['value_property'])) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Calculate change data for a single metric.
   *
   * @param float $current
   *   Current value.
   * @param float|null $previous
   *   Previous value, or NULL.
   *
   * @return array{value: float, formatted: string}|null
   *   Change data, or NULL if no previous.
   */
  protected function calculateSingleChange(float $current, ?float $previous): ?array {
    if ($previous === NULL) {
      return NULL;
    }
    if ($previous > 0) {
      $pct = (($current - $previous) / $previous) * 100;
      $sign = $pct >= 0 ? '+' : '';
      return [
        'value' => $pct,
        'formatted' => $sign . number_format($pct, 1) . '%',
      ];
    }
    return [
      'value' => $current > 0 ? 100.0 : 0.0,
      'formatted' => $current > 0 ? '+100.0%' : '0.0%',
    ];
  }

  /**
   * Get human-readable singular label for a dimension.
   *
   * @param string $dimension
   *   The dimension key.
   *
   * @return string
   *   The label.
   */
  public function getDimensionLabel(string $dimension): string {
    return match ($dimension) {
      'referrer' => (string) $this->t('Referrer'),
      'country' => (string) $this->t('Country'),
      'device' => (string) $this->t('Device'),
      'browser' => (string) $this->t('Browser'),
      'page' => (string) $this->t('Page'),
      'conversion' => (string) $this->t('Conversion'),
      default => ucfirst($dimension),
    };
  }

  /**
   * Get human-readable plural label for a dimension.
   *
   * @param string $dimension
   *   The dimension key.
   *
   * @return string
   *   The plural label.
   */
  public function getDimensionPluralLabel(string $dimension): string {
    return match ($dimension) {
      'referrer' => (string) $this->t('referrers'),
      'country' => (string) $this->t('countries'),
      'device' => (string) $this->t('devices'),
      'browser' => (string) $this->t('browsers'),
      'page' => (string) $this->t('pages'),
      'conversion' => (string) $this->t('conversions'),
      default => $dimension,
    };
  }

}
