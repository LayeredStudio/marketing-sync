<?php
/*
Plugin Name: Marketing Sync
Plugin URI: https://marketing-sync
Description: Sync registered users info with MailChimp, ActiveCampaign, Drip or Intercom
Version: 0.1
Text Domain: marketing-sync
Author: Andrei
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html
*/

require plugin_dir_path(__FILE__) . 'vendor/autoload.php';


// default providers

add_filter('marketing_sync_providers', function(array $providers) {

	$providers['mailChimp'] = new \Layered\MarketingSync\Provider\MailChimp;
	$providers['activeCampaign'] = new \Layered\MarketingSync\Provider\ActiveCampaign;

	return $providers;
});


// start the plugin

add_action('plugins_loaded', '\Layered\MarketingSync\Sync::start');
add_action('plugins_loaded', '\Layered\MarketingSync\Event::instance');
add_action('plugins_loaded', '\Layered\MarketingSync\Admin::instance');


// Use Q for action bg processing
add_action('plugins_loaded', '\Layered\Wp\Q::instance');
register_activation_hook(__FILE__, '\Layered\Wp\Q::install');


/* Helper functions */

function marketingSyncSendEvent(WP_User $user, string $eventName, array $eventData = []) {
	return Layered\MarketingSync\Event::instance()->sendEvent($user, $eventName, $eventData);
}

function marketingSyncProviders() {
	return apply_filters('marketing_sync_providers', []);
}


function marketingSyncGetConnections() {
	return get_option('marketing-sync-connections', []);
}

function marketingSyncUpdateConnections(array $connections) {
	return update_option('marketing-sync-connections', $connections);
}

function marketingSyncAddConnection(array $connection) {
	$connections = marketingSyncGetConnections();
	$connections[] = $connection;
	marketingSyncUpdateConnections($connections);

	return array_key_last($connections);
}

if (!function_exists('array_key_last')) {
	function array_key_last( $array ) {
		$key = NULL;

		if (is_array($array)) {
			end($array);
			$key = key($array);
		}

		return $key;
	}
}
