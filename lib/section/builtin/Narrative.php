<?php declare(strict_types = 1);

namespace Modules\Reporter\Lib\Section\Builtin;

use Modules\Reporter\Lib\Core\Context;
use Modules\Reporter\Lib\Core\SectionResult;
use Modules\Reporter\Lib\Section\AbstractSection;

/**
 * Whatever the account manager wants to say, at the top of the report. No data, no
 * queries; every service report needs one and writing it in the definition beats
 * editing the PDF afterwards.
 */
final class Narrative extends AbstractSection {

	public function type(): string {
		return 'narrative';
	}

	public function label(): string {
		return 'Written summary';
	}

	public function description(): string {
		return '';
	}

	public function options(): array {
		return [
			['name' => 'text', 'label' => 'Text', 'type' => 'textarea', 'default' => '', 'maxlength' => 6000,
				'hint' => 'Blank lines separate paragraphs. Shown as written.']
		];
	}

	public function validateOptions(array $o): array {
		return $o['text'] === '' ? ['Write something, or remove the section.'] : [];
	}

	public function run(Context $ctx, array $o): SectionResult {
		$result = new SectionResult();

		foreach (preg_split('/\n\s*\n/', (string) $o['text']) as $paragraph) {
			$paragraph = trim($paragraph);

			if ($paragraph !== '') {
				$result->text($paragraph);
			}
		}

		return $result;
	}
}
