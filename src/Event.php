<?php
namespace Layered\MarketingSync;

use WP_User;

class Event {

	protected static $_instance = null;

	public static function instance() {
		return self::$_instance ?? self::$_instance = new self;
	}

	public function __construct() {

		// add event JS Api
		add_action('rest_api_init', [$this, 'restApi']);
		add_action('wp_enqueue_scripts', [$this, 'jsApi']);

		// send data to providers
		add_action('marketing_sync_provider_send_event', [$this, 'sendEventToProvider'], 10, 3);

		// record basic events
		add_action('wp_login', [$this, 'sendEventUserLogIn'], 10, 2);
	}

	public function sendEvent(WP_User $user, string $eventName, array $eventData = []): bool {

		queue_action('marketing_sync_provider_send_event', $user->ID, $eventName, $eventData);

		return true;
	}

	public function sendEventToProvider($user, string $eventName, array $eventData = []): array {
		$providers = marketingSyncProviders();
		$eventResult = [];

		if (is_int($user)) {
			$user = get_user_by('id', $user);
		}

		foreach ($providers as $provider) {
			$eventResult[$provider->getId()] = $provider->sendEvent($user, $eventName, $eventData);
		}

		return $eventResult;
	}

	public function jsApi() {
		$currentUser = wp_get_current_user();
		$user = null;

		if ($currentUser->ID) {
			$user = [
				'ID'			=>	$currentUser->ID,
				'user_login'	=>	$currentUser->user_login,
				'user_email'	=>	$currentUser->user_email,
				'display_name'	=>	$currentUser->display_name
			];
		}

		wp_enqueue_script('marketing-sync-events-api', plugins_url('assets/marketing-sync-events-api.js', dirname(__FILE__)), [], '0.1');
		wp_localize_script('marketing-sync-events-api', 'MarketingSyncEventsApi', [
			'user'		=>	$user,
			'nonce'		=>	wp_create_nonce('wp_rest'),
			'apiUrl'	=>	rest_url('marketing-sync/v1/send-event')
		]);
	}

	public function restApi() {

		register_rest_route('marketing-sync/v1', '/send-event', [
			'methods'				=>	\WP_REST_Server::CREATABLE,
			'permission_callback'	=>	'is_user_logged_in',
			'args'					=>	[
				'eventName'		=>	[
					'description'		=>	__('Event name to be recorded', 'marketing-sync'),
					'type'				=>	'string',
					'required'			=>	true,
					'sanitize_callback'	=>	'sanitize_text_field',
					'validate_callback'	=>	function($value) {
						$value = sanitize_text_field($value);
						return !empty($value);
					}
				],
				'eventData'		=>	[
					'description'		=>	__('Event data to be recorded', 'marketing-sync'),
					'type'				=>	'object',
					'default'			=>	[]
				]
			],
			'callback'				=>	function(\WP_REST_Request $request) {
				$user = wp_get_current_user();
				$eventName = $request->get_param('eventName');
				$eventData = $request->get_param('eventData');
				$eventData = array_map('urldecode', $eventData);

				return $this->sendEvent($user, $eventName, $eventData);
			}
		]);

	}

	public function sendEventUserLogIn(string $userLogin, WP_User $user) {
		return $this->sendEvent($user, 'Logged in', [
			'username'	=>	$userLogin
		]);
	}

}
