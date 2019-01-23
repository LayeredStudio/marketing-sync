<?php
namespace Layered\MarketingSync;

use WP_User;
use WP_CLI;
use Layered\Wp\Q;

class Sync {

	public static function start() {
		return new static;
	}

	public function __construct() {

		// sync user
		add_action('user_register', [$this, 'syncUser']);
		add_action('profile_update', [$this, 'syncUser']);
		add_action('add_user_role', [$this, 'syncUser']);

		// sync user field
		add_action('added_user_meta', [$this, 'syncUserMeta'], 100, 3);
		add_action('updated_user_meta', [$this, 'syncUserMeta'], 100, 3);

		// send data to providers
		add_action('marketing_sync_update_provider', [$this, 'sendDataToProvider'], 10, 3);

		if (class_exists('WP_CLI')) {
			WP_CLI::add_command('marketing-sync sync', [$this, 'cliCommandSync']);
		}
	}

	public function syncUserMeta($metaId, $userId, $metaKey) {
		return $this->syncUser($userId, [$metaKey]);
	}

	public function syncUser($user, array $onlyFields = null) {
		$connections = marketingSyncGetConnections();

		if (is_int($user)) {
			$user = get_user_by('id', $user);
		}

		foreach ($connections as $index => $connection) {
			if ($connection['status'] === 'enabled') {
				$userFilter = $connection['filter-role'] === 'any' ? true : in_array($connection['filter-role'], $user->roles);

				if ($userFilter) {
					$fields = [];

					foreach ($connection['user_meta'] as $i => $userMeta) {
						if (is_null($onlyFields) || in_array($userMeta, $onlyFields)) {
							$field = $connection['fields'][$i];

							if ($userMeta === 'role') {
								//$userValue = wp_roles()->roles[$user->roles[0]]['name'];
								$lastRole = array_values(array_slice($user->roles, -1))[0];
								$userValue = wp_roles()->roles[$lastRole]['name'];
							} else {
								$userValue = apply_filters('marketing_sync_field_value', $user->$userMeta, $field, $userMeta, $connection['provider']);
							}

							$fields[$field] = $userValue;
						}
					}

					if (count($fields)) {
						queue_action('marketing_sync_update_provider', $connection, $user->ID, $fields);
					}
				}
			}
		}

		return true;
	}

	public function sendDataToProvider(array $connection, $user, array $fields) {
		$providers = marketingSyncProviders();

		if (is_int($user)) {
			$user = get_user_by('id', $user);
		}

		return $providers[$connection['provider']]->syncUser($user, $connection, $fields);
	}

	public static function getWpFields() {
		$fields = [];

		$fields[] = [
			'id'	=>	'role',
			'name'	=>	__('Role', 'marketing-sync'),
			'group'	=>	__('User', 'marketing-sync')
		];
		$fields[] = [
			'id'	=>	'user_login',
			'name'	=>	__('Username', 'marketing-sync'),
			'group'	=>	__('User', 'marketing-sync')
		];
		$fields[] = [
			'id'	=>	'user_email',
			'name'	=>	__('Email', 'marketing-sync'),
			'group'	=>	__('User', 'marketing-sync')
		];
		$fields[] = [
			'id'	=>	'user_url',
			'name'	=>	__('URL', 'marketing-sync'),
			'group'	=>	__('User', 'marketing-sync')
		];
		$fields[] = [
			'id'	=>	'display_name',
			'name'	=>	__('Display Name', 'marketing-sync'),
			'group'	=>	__('User', 'marketing-sync')
		];
		$fields[] = [
			'id'	=>	'first_name',
			'name'	=>	__('First Name', 'marketing-sync'),
			'group'	=>	__('User', 'marketing-sync')
		];
		$fields[] = [
			'id'	=>	'last_name',
			'name'	=>	__('Last Name', 'marketing-sync'),
			'group'	=>	__('User', 'marketing-sync')
		];
		$fields[] = [
			'id'	=>	'description',
			'name'	=>	__('Description', 'marketing-sync'),
			'group'	=>	__('User', 'marketing-sync')
		];


		foreach (get_registered_meta_keys('user') as $key => $meta) {
			$fields[] = [
				'id'	=>	$key,
				'name'	=>	$key,
				'group'	=>	__('Custom Fields', 'marketing-sync')
			];
		}


		return $fields;
	}

	public function syncAllUsers() {
		set_time_limit(0);
		$synced = 0;

		foreach (get_users() as $user) {
			$this->syncUser($user);
			$synced++;
		}

		return $synced;
	}

	public function cliCommandSync() {
		$res = $this->syncAllUsers();

		WP_CLI::success('Synced ' . $res . ' users');
	}

}
