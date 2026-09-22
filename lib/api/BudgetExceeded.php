<?php declare(strict_types = 1);

namespace Modules\Reporter\Lib\Api;

/**
 * Thrown when a run hits one of its safety limits. Deliberately not an ApiException:
 * the API did nothing wrong, the report asked for too much.
 */
class BudgetExceeded extends \RuntimeException {
}
