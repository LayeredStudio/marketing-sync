<?php
namespace Layered\MarketingSync;

use WP_User;

interface ProviderInterface {

	public function testConnection(): bool;

	public function getRequiredFields(): array;
	public function getExtraFields(array $connection): array;

	public function getContactFields(array $connection): array;
	public function syncUser(WP_User $user, array $connection, array $fields): bool;
	public function syncUserField(WP_User $user, array $connection, string $field, $value): bool;

}
