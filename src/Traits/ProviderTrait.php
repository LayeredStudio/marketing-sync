<?php
namespace Layered\MarketingSync\Traits;

trait ProviderTrait {

	protected $options;

	public function getId(): string {
		return $this->id;
	}

	public function getColor(string $which = 'brand'): string {
		return $this->colors[$which];
	}

	public function getLabel(): string {
		return apply_filters('marketing_sync_provider_label', $this->label, $this->id);
	}

	public function getIconSvg(): string {
		return apply_filters('marketing_sync_provider_icon', $this->icon, $this->id);
	}

	public function getOption($key = null, $default = null) {

		if (!is_array($this->options)) {
			$this->options = get_option('marketing-sync-provider-' . $this->getId(), [
				'status'		=>	'needs-setup'
			]);
		}

		return $key ? ($this->options[$key] ?? $default) : $this->options;
	}

	public function updateOptions(array $newOptions): array {
		$this->options = array_merge($this->getOption(), $newOptions);
		update_option('marketing-sync-provider-' . $this->getId(), $this->options);

		return $this->options;
	}

}
