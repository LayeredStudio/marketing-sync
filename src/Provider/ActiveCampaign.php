<?php
namespace Layered\MarketingSync\Provider;

use Layered\MarketingSync\ProviderInterface;
use Layered\MarketingSync\Traits\ProviderTrait;
use WP_User;

class ActiveCampaign implements ProviderInterface {
	use ProviderTrait;

	public function __construct(array $connection = null) {
		$this->id = 'activeCampaign';
		$this->label = 'ActiveCampaign';
		$this->colors = [
			'brand'	=>	'#1b54d9',
			'text'	=>	'#fff'
		];
		$this->icon = '<svg role="img" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M11.643 0H4.68l7.679 12L4.68 24h6.963l7.677-12-7.677-12"/></svg>';
	}

	protected function apiRequest(string $path, array $args = []) {
		$options = $this->getOption();
		$args = wp_parse_args($args, [
			'method'	=>	'GET',
			'headers'	=>	[]
		]);

		$args['headers'] = wp_parse_args($args['headers'], [
			'Api-Token'		=>	$options['apiKey'],
			'Content-Type'	=>	'application/json'
		]);

		if (isset($args['body']) && in_array(strtoupper($args['method']), ['POST', 'PUT', 'PATCH'])) {
			$args['body'] = json_encode($args['body']);
		}

		$req = wp_remote_request($options['apiUrl'] . '/api/3' . $path, $args);

		return is_wp_error($req) ? null : wp_remote_retrieve_json($req);
	}

	public function testConnection(): bool {
		$me = $this->apiRequest('/users/me');

		return isset($me['user']) && isset($me['user']['id']);
	}

	public function getRequiredFields(): array {
		$settings = get_option('settings_activecampaign');

		$fields = [
			'apiUrl'		=>	[
				'name'			=>	__('API URL', 'marketing-sync'),
				'required'		=>	true,
				'type'			=>	'text',
				'default'		=>	$settings['api_url'] ?? '',
				'description'	=>	null
			],
			'apiKey'	=>	[
				'name'			=>	__('API Key:', 'marketing-sync'),
				'required'		=>	true,
				'type'			=>	'text',
				'default'		=>	$settings['api_key'] ?? '',
				'description'	=>	__('API URL & Key can be found in your account on Settings -> Developer page.', 'marketing-sync')
			]
		];

		return $fields;
	}

	public function getExtraFields(array $connection): array {
		$fields = [];

		// lists
		$fields['list'] = [
			'name'		=>	__('Add to list', 'marketing-sync'),
			'required'	=>	false,
			'type'		=>	'select',
			'options'	=>	[
				'0'	=>	'- Select -'
			]
		];
		$lists = $this->apiRequest('/lists');
		foreach ($lists['lists'] as $list) {
			$fields['list']['options'][$list['id']] = $list['name'];
		}

		return $fields;
	}

	public function getContactFields(array $connection): array {
		$fields = [[
			'id'	=>	'email',
			'name'	=>	__('Email', 'marketing-sync'),
			'group'	=>	__('Contact fields')
		], [
			'id'	=>	'firstName',
			'name'	=>	__('First Name', 'marketing-sync'),
			'group'	=>	__('Contact fields', 'marketing-sync')
		], [
			'id'	=>	'lastName',
			'name'	=>	__('Last Name', 'marketing-sync'),
			'group'	=>	__('Contact fields', 'marketing-sync')
		], [
			'id'	=>	'phone',
			'name'	=>	__('Phone', 'marketing-sync'),
			'group'	=>	__('Contact fields', 'marketing-sync')
		]];

		// Custom Fields
		$contactFields = $this->apiRequest('/fields');

		foreach ($contactFields['fields'] as $field) {
			$fields[] = [
				'id'	=>	$field['id'],
				'name'	=>	$field['title'],
				'group'	=>	__('Custom fields', 'marketing-sync')
			];
		}

		return $fields;
	}

	public function syncUser(WP_User $user, array $connection, array $fields): bool {
		$acContact = [
			'email'		=>	$user->user_email
		];
		$acContactFields = [];

		foreach ($fields as $field => $value) {
			if (is_numeric($field)) {
				$acContactFields[$field] = $value;
			} else {
				$acContact[$field] = $value;
			}
		}

		$re = $this->apiRequest('/contact/sync', [
			'method'	=>	'POST',
			'body'		=>	[
				'contact'	=>	$acContact
			]
		]);

		if (isset($re['contact'])) {
			$contactId = $re['contact']['id'];
			$existing = isset($re['contact']['hash']);


			// save Contact ID to WP User
			add_user_meta($user->ID, 'activecampaign-contact-id', $contactId, true);


			// add Contact to list
			if ($connection['list']) {
				$lists = [];

				if ($existing) {
					$lists = $this->apiRequest('/contacts/' . $re['contact']['id'] . '/contactLists');
					$lists = array_filter($lists['contactLists'], function($list) use($connection) {
						return $list['list'] == $connection['list'];
					});
				}

				if (!count($lists)) {
					$this->apiRequest('/contactLists', [
						'method'	=>	'POST',
						'body'		=>	[
							'contactList'	=>	[
								'contact'	=>	$contactId,
								'list'		=>	$connection['list'],
								'status'	=>	1
							]
						]
					]);
				}
			}


			// add Tags to contact
			$tags = [
				'subscriber'	=>	21,
				'agent'		=>	22,
				'pro-designer'	=>	23
			];
			foreach ($user->roles as $role) {
				if (isset($tags[$role])) {
					$this->apiRequest('/contactTags', [
						'method'	=>	'POST',
						'body'		=>	[
							'contactTag'	=>	[
								'contact'	=>	$contactId,
								'tag'		=>	$tags[$role]
							]
						]
					]);
				}
			}


			// add Field Values to Contact
			if (count($acContactFields)) {
				$fieldValues = [];

				if ($existing) {
					$fieldValues = $this->apiRequest('/contacts/' . $re['contact']['id'] . '/fieldValues')['fieldValues'];
				}

				foreach ($acContactFields as $fieldId => $value) {
					$requestBody = [
						'fieldValue'	=>	[
							'contact'	=>	$contactId,
							'field'		=>	$fieldId,
							'value'		=>	$value
						]
					];

					$existingField = array_filter($fieldValues, function($fieldValue) use($fieldId) {
						return $fieldValue['field'] == $fieldId;
					});

					if (count($existingField)) {
						$existingField = array_shift($existingField);
						$this->apiRequest('/fieldValues/' . $existingField['id'], [
							'method'	=>	'PUT',
							'body'		=>	$requestBody
						]);
					} else {
						$this->apiRequest('/fieldValues', [
							'method'	=>	'POST',
							'body'		=>	$requestBody
						]);
					}
				}
			}

		}

		return isset($re['contact']);
	}

	public function syncUserField(WP_User $user, array $connection, string $field, $value): bool {
		$fields = [];
		$fields[$field] = $value;

		return $this->syncUser($user, $connection, $fields);
	}

}
