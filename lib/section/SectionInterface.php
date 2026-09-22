<?php declare(strict_types = 1);

namespace Modules\Reporter\Lib\Section;

use Modules\Reporter\Lib\Core\Context;
use Modules\Reporter\Lib\Core\SectionResult;

/**
 * A report section. Implement this (usually by extending AbstractSection) and drop the
 * file into the module's sections/ directory; see sections/README.md.
 *
 * Rules a section must follow:
 *  - read data only through $ctx (its API client is budgeted and read-only);
 *  - prefer the shared fetchers on $ctx (problems, alerts, items, trends) so sections in
 *    one report reuse each other's queries instead of repeating them;
 *  - return SectionResult blocks, never HTML.
 */
interface SectionInterface {

	/** Stable identifier stored in definitions, e.g. "problems_by_host". */
	public function type(): string;

	/** Name shown in the editor and used as the default heading. */
	public function label(): string;

	/** One sentence telling the reader what the section measures and how. */
	public function description(): string;

	/**
	 * Option schema. Each entry:
	 *   ['name' => .., 'label' => .., 'type' => text|int|float|bool|select|tags,
	 *    'default' => .., 'min' => .., 'max' => .., 'choices' => [value => label], 'hint' => ..]
	 */
	public function options(): array;

	/** Validate and fill defaults. Unknown keys are dropped. */
	public function normalizeOptions(array $options): array;

	/** Return error strings for option combinations that normalizing cannot fix. */
	public function validateOptions(array $options): array;

	public function run(Context $ctx, array $options): SectionResult;
}
