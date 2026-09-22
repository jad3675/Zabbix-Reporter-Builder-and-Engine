<?php declare(strict_types = 1);

namespace Modules\Reporter\Lib\Core;

/**
 * What a section hands back: an ordered list of blocks plus notes. Sections never emit
 * HTML. The renderers decide how a block looks on screen, in the PDF and in a
 * spreadsheet, which is what lets one section produce all three.
 *
 * Block types:
 *   kpis   [['label' => .., 'value' => .., 'format' => .., 'hint' => ..], ...]
 *   table  columns + rows; rows are raw values, formatting is per column
 *   chart  kind (hbar | columns | severity) + data
 *   text   a paragraph
 *
 * Column formats: text, int, number, pct, duration, units (uses the row's "units" key),
 * datetime, date, severity (0-5), sevstrip (array of six counts), spark (array of numbers).
 * A column can set 'export' => false (screen and PDF only) or 'screen' => false (export only).
 */
final class SectionResult {

	private array $blocks = [];
	private array $notes = [];

	public function kpis(array $items): self {
		$this->blocks[] = ['type' => 'kpis', 'items' => array_values($items)];

		return $this;
	}

	public function table(string $title, array $columns, array $rows, array $options = []): self {
		$this->blocks[] = [
			'type' => 'table',
			'title' => $title,
			'columns' => array_values($columns),
			'rows' => array_values($rows),
			'display_limit' => max(0, (int) ($options['display_limit'] ?? 0)),
			'sheet' => (string) ($options['sheet'] ?? $title),
			'empty' => (string) ($options['empty'] ?? 'Nothing to report for this period.')
		];

		return $this;
	}

	public function chart(string $kind, string $title, array $data, array $options = []): self {
		$this->blocks[] = ['type' => 'chart', 'kind' => $kind, 'title' => $title, 'data' => $data,
			'options' => $options];

		return $this;
	}

	public function text(string $text): self {
		$this->blocks[] = ['type' => 'text', 'text' => $text];

		return $this;
	}

	public function note(string $note): self {
		$this->notes[] = $note;

		return $this;
	}

	public function toArray(): array {
		return ['blocks' => $this->blocks, 'notes' => $this->notes];
	}
}
