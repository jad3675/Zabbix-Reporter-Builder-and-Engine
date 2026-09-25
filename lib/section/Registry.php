<?php declare(strict_types = 1);

namespace Modules\Reporter\Lib\Section;

/**
 * Built-in sections plus anything in <module>/sections/*.php. A drop-in file must
 * return an instance of SectionInterface. Drop-ins live in the module directory, not
 * the data directory, on purpose: the data directory is writable by the web server,
 * and code must not be.
 */
final class Registry {

	private const BUILTIN = [
		Builtin\Narrative::class,
		Builtin\Summary::class,
		Builtin\ProblemsByHost::class,
		Builtin\TopTriggers::class,
		Builtin\TopMetrics::class,
		Builtin\CapacityGrowth::class,
		Builtin\Availability::class,
		Builtin\ResponseTimes::class,
		Builtin\ProblemLog::class,
		Builtin\ThresholdBreaches::class,
		Builtin\MonitoringHealth::class,
		Builtin\Inventory::class,
		Builtin\Maintenance::class
	];

	/** @var array<string, SectionInterface> */
	private array $sections = [];
	private array $load_errors = [];

	public function __construct(?string $custom_dir = null) {
		foreach (self::BUILTIN as $class) {
			$this->add(new $class());
		}

		$custom_dir = $custom_dir ?? REPORTER_ROOT.'/sections';

		foreach (glob($custom_dir.'/*.php') ?: [] as $file) {
			try {
				$section = (static function (string $__file) {
					return require $__file;
				})($file);

				if (!$section instanceof SectionInterface) {
					$this->load_errors[] = sprintf('%s: does not return a SectionInterface instance.', basename($file));

					continue;
				}

				if (isset($this->sections[$section->type()])) {
					$this->load_errors[] = sprintf('%s: type "%s" is already registered.', basename($file),
						$section->type());

					continue;
				}

				$this->add($section);
			}
			catch (\Throwable $e) {
				$this->load_errors[] = sprintf('%s: %s', basename($file), $e->getMessage());
			}
		}
	}

	private function add(SectionInterface $section): void {
		if (!preg_match('/^[a-z][a-z0-9_]{1,63}$/', $section->type())) {
			$this->load_errors[] = sprintf('Section type "%s" is not a valid identifier.', $section->type());

			return;
		}

		$this->sections[$section->type()] = $section;
	}

	public function has(string $type): bool {
		return isset($this->sections[$type]);
	}

	public function get(string $type): SectionInterface {
		if (!isset($this->sections[$type])) {
			throw new \InvalidArgumentException(sprintf('Unknown section type "%s".', $type));
		}

		return $this->sections[$type];
	}

	/** @return array<string, SectionInterface> */
	public function all(): array {
		return $this->sections;
	}

	public function loadErrors(): array {
		return $this->load_errors;
	}

	/** Schema for the editor's JavaScript. */
	public function schemas(): array {
		$out = [];

		foreach ($this->sections as $type => $section) {
			$out[] = [
				'type' => $type,
				'label' => $section->label(),
				'description' => $section->description(),
				'options' => $section->options(),
				'defaults' => $section->normalizeOptions([])
			];
		}

		return $out;
	}
}
