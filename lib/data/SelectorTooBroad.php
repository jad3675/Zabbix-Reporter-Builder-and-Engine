<?php declare(strict_types = 1);

namespace Modules\Reporter\Lib\Data;

/**
 * One section's filter matches more items than a report may read. Unlike the shared
 * budgets (API calls, rows, time, memory), nothing has been spent yet, so this fails
 * only the section whose filter is too broad and the rest of the report still runs.
 */
final class SelectorTooBroad extends \RuntimeException {
}
