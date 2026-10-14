<?php
declare(strict_types=1);
/** Shim: повертає лише free-кількість активу з exi_get_account() */
if (!function_exists('exi_get_free_only')) {
  function exi_get_free_only(array $account, string $asset): float {
    if (isset($account['balances']) && is_array($account['balances'])) {
      foreach ($account['balances'] as $b) {
        if (($b['asset'] ?? '') === $asset) {
          return (float)($b['free'] ?? 0.0);
        }
      }
    }
    return 0.0;
  }
}
