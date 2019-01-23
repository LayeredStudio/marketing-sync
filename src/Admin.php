<?php
namespace Layered\MarketingSync;

use Layered\MarketingSync\ProviderInterface;
use Layered\MarketingSync\Sync;
use WP_User;

class Admin {

	protected static $_instance = null;

	public static function instance() {
		if (is_null(self::$_instance)) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	protected function __construct() {
		add_action('admin_enqueue_scripts', [$this, 'assets']);
		add_action('admin_init', [$this, 'actions']);
		add_action('admin_menu', [$this, 'menu']);
		add_action('admin_notices', [$this, 'notices']);
		add_filter('plugin_action_links_marketing-sync/marketing-sync.php', [$this, 'actionLinks']);

		$this->providers = marketingSyncProviders();
	}

	public function assets() {
		wp_enqueue_script('marketing-sync-admin', plugins_url('assets/marketing-sync-admin.js', dirname(__FILE__)), ['jquery'], '1.0', true);
		wp_enqueue_style('marketing-sync-admin', plugins_url('assets/marketing-sync-admin.css', dirname(__FILE__)));
	}

	public function actions() {

		if (isset($_POST['marketing-sync-provider-setup'])) {
			unset($_POST['marketing-sync-provider-add']);
			$provider = $this->providers[$_REQUEST['provider']];

			$options = [];

			foreach ($provider->getRequiredFields() as $key => $setting) {
				$options[$key] = $_POST[$key];
			}

			$provider->updateOptions($options);

			if ($provider->testConnection()) {
				$options['status'] = 'connected';
				$provider->updateOptions($options);

				$redirectParams['marketing-sync-action'] = null;
				$redirectParams['provider'] = null;
				$redirectParams['marketing-sync-alert'] = urlencode(sprintf(__('Settings for <strong>%s</strong> are saved!', 'marketing-sync'), $provider->getLabel()));
			} else {
				$redirectParams['marketing-sync-alert'] = urlencode(sprintf(__('Connection test to <strong>%s</strong> failed', 'marketing-sync'), $provider->getLabel()));
				$redirectParams['alert-type'] = 'error';
			}

			wp_redirect(add_query_arg($redirectParams));
			exit;
		}

		if (isset($_POST['marketing-sync-provider-add'])) {
			unset($_POST['marketing-sync-provider-add']);
			$provider = $this->providers[$_REQUEST['provider']];

			$redirectParams = [];

			if ($provider->testConnection($_POST)) {
				$connection = [];
				$connection['provider'] = $_REQUEST['provider'];
				$connection['createdAt'] = $connection['updatedAt'] = time();
				$connection['status'] = 'disabled';
				foreach ($provider->getRequiredFields() as $key => $setting) {
					$connection[$key] = $_POST[$key];
				}

				$id = marketingSyncAddConnection($connection);

				$redirectParams['marketing-sync-action'] = 'edit';
				$redirectParams['id'] = $id;
				$redirectParams['marketing-sync-alert'] = null;
				$redirectParams['alert-type'] = null;

			} else {
				$redirectParams['marketing-sync-alert'] = urlencode(sprintf(__('Connection test to <strong>%s</strong> failed', 'marketing-sync'), $provider->getLabel()));
				$redirectParams['alert-type'] = 'error';
			}

			wp_redirect(add_query_arg($redirectParams));
			exit;
		}

		if (isset($_POST['marketing-sync-provider-save'])) {
			unset($_POST['marketing-sync-provider-save']);
			$connections = marketingSyncGetConnections();
			$connection = $connections[$_GET['id']];

			foreach ($_POST['user_meta'] as $index => $value) {
				if (empty($value) || empty($_POST['fields'][$index])) {
					unset($_POST['user_meta'][$index]);
					unset($_POST['fields'][$index]);
				}
			}

			$connection = array_merge($connection, $_POST);
			$connection['status'] = 'enabled';
			$connections[$_GET['id']] = $connection;
			marketingSyncUpdateConnections($connections);
			$provider = $this->providers[$connection['provider']];

			$redirectParams = [];
			$redirectParams['marketing-sync-alert'] = urlencode(sprintf(__('Settings for %s are saved', 'marketing-sync'), $provider->getLabel()));

			wp_redirect(add_query_arg($redirectParams));
			exit;
		}

		if (isset($_GET['marketing-sync-action']) && $_GET['marketing-sync-action'] === 'delete' && isset($_GET['id'])) {
			$connections = marketingSyncGetConnections();

			if (isset($connections[$_GET['id']])) {
				$connection = $connections[$_GET['id']];
				unset($connections[$_GET['id']]);
				marketingSyncUpdateConnections($connections);
				$params = [
					'marketing-sync-alert'	=>	urlencode(sprintf(__('%s connection was removed', 'marketing-sync'), $connection['provider']))
				];
			} else {
				$params = [
					'marketing-sync-alert'	=>	urlencode(sprintf(__('Connection with ID %s is invalid', 'marketing-sync'), $_GET['id'])),
					'alert-type'			=>	'error'
				];
			}

			wp_redirect($this->adminLink($params));
			exit;
		}

	}

	public function menu() {
		add_menu_page(__('Marketing Sync Options', 'marketing-sync'), 'Marketing Sync', 'manage_options', 'marketing-sync', [$this, 'page'], '', 72);
	}

	public function notices() {
		$notices = [];

		if (!count(marketingSyncGetConnections())) {
			$notices[] = [
				'type'		=>	'warning',
				'message'	=>	sprintf(__('<strong>Marketing Sync</strong> plugin is active, but no providers are enabled. <a href="%s">Enable providers now</a> and sync user data with MailChimp, ActiveCampaign, Intercom and more', 'marketing-sync'), menu_page_url('marketing-sync', false))
			];
		}

		if (isset($_REQUEST['marketing-sync-alert'])) {
			$notices[] = [
				'type'			=>	isset($_REQUEST['alert-type']) ? $_REQUEST['alert-type'] : 'success',
				'message'		=>	$_REQUEST['marketing-sync-alert'],
				'dismissable'	=>	true
			];
		}

		foreach ($notices as $notice) {
			?>
			<div class="notice notice-<?php echo esc_attr($notice['type']) ?> <?php if (isset($notice['dismissable']) && $notice['dismissable'] === true) echo 'is-dismissible' ?>">
				<p><?php echo wp_kses($notice['message'], ['a' => ['href' => [], 'title' => []], 'strong' => []]) ?></p>
			</div>
			<?php
		}
	}

	public function actionLinks(array $links) {
		return array_merge([
			'settings'	=>	'<a href="' . menu_page_url('marketing-sync', false) . '">' . __('Settings', 'marketing-sync') . '</a>'
		], $links);
	}

	public function adminLink(array $params = []) {

		return add_query_arg($params, menu_page_url('marketing-sync', false));
	}

	public function page() {
		$connections = marketingSyncGetConnections();
		?>

		<div class="wrap about-wrap marketing-sync-wrap">
			<h1><?php _e('Marketing Sync', 'quick-login') ?></h1>

			<?php if (isset($_GET['marketing-sync-action']) && $_GET['marketing-sync-action'] === 'provider' && isset($this->providers[$_GET['provider']])) : ?>
				<?php
				$provider = $this->providers[$_GET['provider']];
				?>

				<h3><?php printf(__('Set up %s', 'quick-login'), $provider->getLabel()) ?></h3>

				<form method="post">
					<table class="form-table">
						<tbody>
							<?php foreach ($provider->getRequiredFields() as $key => $setting) : ?>
								<tr>
									<th scope="row"><label for="<?php echo esc_attr($key) ?>"><?php echo $setting['name'] ?></label></th>
									<td>
										<input name="<?php echo esc_attr($key) ?>" type="<?php echo esc_attr($setting['type']) ?>" id="<?php echo esc_attr($key) ?>" <?php if ($setting['required']) echo 'required'  ?> value="<?php echo $provider->getOption($key, $setting['default']) ?>" class="regular-text">
										<?php if ($setting['description']) : ?><p class="description"><?php echo $setting['description'] ?></p><?php endif ?>
									</td>
								</tr>
							<?php endforeach ?>
						</tbody>
						<tfoot>
							<tr>
								<td>
									<p><a href="<?php echo $this->adminLink() ?>" class="button button-secondary"><?php _e('Cancel', 'marketing-sync') ?></a></p>
								</td>
								<td>
									<p class="regular-text text-right"><input type="submit" name="marketing-sync-provider-setup" id="submit" class="button button-primary" value="<?php _e('Continue', 'marketing-sync') ?>"></p>
								</td>
							</tr>
						</tfoot>
					</table>
				</form>

			<?php elseif (isset($_REQUEST['marketing-sync-action']) && $_REQUEST['marketing-sync-action'] == 'add' && isset($this->providers[$_REQUEST['provider']])) : ?>
				<?php
				$provider = $this->providers[$_REQUEST['provider']];
				?>

				<h3><?php printf(__('Set up %s', 'quick-login'), $provider->getLabel()) ?></h3>

				<form method="post">
					<table class="form-table">
						<tbody>
							<?php foreach ($provider->getRequiredFields() as $key => $setting) : ?>
								<tr>
									<th scope="row"><label for="<?php echo esc_attr($key) ?>"><?php echo $setting['name'] ?></label></th>
									<td>
										<input name="<?php echo esc_attr($key) ?>" type="<?php echo esc_attr($setting['type']) ?>" id="<?php echo esc_attr($key) ?>" <?php if ($setting['required']) echo 'required'  ?> value="<?php echo $setting['default'] ?>" class="regular-text">
										<?php if ($setting['description']) : ?><p class="description"><?php echo $setting['description'] ?></p><?php endif ?>
									</td>
								</tr>
							<?php endforeach ?>
						</tbody>
						<tfoot>
							<tr>
								<td>
									<p><a href="<?php echo $this->adminLink() ?>" class="button button-secondary"><?php _e('Cancel', 'marketing-sync') ?></a></p>
								</td>
								<td>
									<p class="regular-text text-right"><input type="submit" name="marketing-sync-provider-add" id="submit" class="button button-primary" value="<?php _e('Continue', 'marketing-sync') ?>"></p>
								</td>
							</tr>
						</tfoot>
					</table>
				</form>

			<?php elseif (isset($_REQUEST['marketing-sync-action']) && $_REQUEST['marketing-sync-action'] == 'edit') : ?>

				<?php
				$connection = $connections[$_GET['id']];
				$provider = $this->providers[$connection['provider']];
				?>

				<h3><?php printf(__('Sync user data with %s', 'quick-login'), $provider->getLabel()) ?></h3>

				<form method="post">
					<table class="form-table">
						<tbody>
							<tr>
								<th scope="row"><label for="filter-role"><?php _e('User Filters', 'marketing-sync-alert') ?></label></th>
								<td>
									<div class="marketing-sync-user-filter">
										<label class="w-small">User role</label>
										<select name="filter-role" id="filter-role" class="w-small">
											<option value="any"><?php _e('All user roles', 'marketing-sync') ?></option>
											<?php wp_dropdown_roles($connection['filter-role'] ?? '') ?>
										</select>
									</div>
								</td>
							</tr>
							<?php foreach ($provider->getExtraFields($connection) as $key => $setting) : ?>
								<tr>
									<th scope="row"><label for="<?php echo esc_attr($key) ?>"><?php echo $setting['name'] ?></label></th>
									<td>
										<?php if (in_array($setting['type'], ['text', 'email', 'number'])) : ?>
											<input name="<?php echo esc_attr($key) ?>" type="<?php echo esc_attr($setting['type']) ?>" id="<?php echo esc_attr($key) ?>" <?php if ($setting['required']) echo 'required'  ?> value="<?php echo $connection[$key] ?>" class="regular-text">
										<?php elseif ($setting['type'] === 'select') : ?>
											<select name="<?php echo esc_attr($key) ?>" id="<?php echo esc_attr($key) ?>" <?php if ($setting['required']) echo 'required'  ?> class="regular-text">
												<?php foreach ($setting['options'] as $value => $label) : ?>
													<option value="<?php echo $value ?>" <?php selected($value, $connection[$key] ?? '') ?>><?php echo $label ?></option>
												<?php endforeach ?>
											</select>
										<?php endif ?>
									</td>
								</tr>
							<?php endforeach ?>
							<tr>
								<th scope="row"><label for="fields">Sync fields</label></th>
								<td>
									<?php
									$wpGroupedFields = [];
									foreach (Sync::getWpFields() as $field) {
										if (!isset($wpGroupedFields[$field['group']])) {
											$wpGroupedFields[$field['group']] = [];
										}
										$wpGroupedFields[$field['group']][] = $field;
									}

									$fields = $provider->getContactFields($connection);
									$groupedFields = [];
									foreach ($fields as $field) {
										if (!isset($groupedFields[$field['group']])) {
											$groupedFields[$field['group']] = [];
										}
										$groupedFields[$field['group']][] = $field;
									}

									if (!isset($connection['fields']) || empty($connection['fields'])) {
										$connection['fields'] = ['0'];
										$connection['user_meta'] = ['0'];
									}
									?>
									<div class="marketing-sync-fieldset">
										<div>
											<label class="w-small label-legend"><?php _e('User field') ?></label>
											<label class="w-small label-legend"><?php printf(__('%s field', 'marketing-sync'), $provider->getLabel()) ?></label>
										</div>

										<div class="marketing-sync-user-field">
											<select class="w-small" disabled>
												<option>Email</option>
											</select>
											<select class="w-small" disabled>
												<option>Email</option>
											</select>
										</div>

										<?php foreach ($connection['user_meta'] as $index => $userMeta) : ?>
											<div class="marketing-sync-user-field">
												<select name="user_meta[]" class="w-small">
													<option value="0">- Select -</option>
													<?php foreach ($wpGroupedFields as $group => $fields) : ?>
														<optgroup label="<?php echo $group ?>">
															<?php foreach ($fields as $field) : ?>
																<option value="<?php echo $field['id'] ?>" <?php selected($field['id'], $userMeta) ?>><?php echo $field['name'] ?></option>
															<?php endforeach ?>
														</optgroup>
													<?php endforeach ?>
												</select>
												<select name="fields[]" class="w-small">
													<option value="0">- Select -</option>
													<?php foreach ($groupedFields as $group => $fields) : ?>
														<optgroup label="<?php echo $group ?>">
															<?php foreach ($fields as $field) : ?>
																<option value="<?php echo $field['id'] ?>" <?php selected($field['id'], $connection['fields'][$index]) ?>><?php echo $field['name'] ?></option>
															<?php endforeach ?>
														</optgroup>
													<?php endforeach ?>
												</select>
											</div>
										<?php endforeach ?>
									</div>
									<span class="button button-secondary button-small marketing-sync-add-field"><?php _e('Add field', 'marketing-sync') ?></span>
								</td>
							</tr>
						</tbody>
						<tfoot>
							<tr>
								<td>
									<p><a href="<?php echo $this->adminLink() ?>" class="button button-secondary"><?php _e('Cancel', 'marketing-sync') ?></a></p>
								</td>
								<td>
									<p class="regular-text text-right"><input type="submit" name="marketing-sync-provider-save" id="submit" class="button button-primary" value="<?php _e('Save', 'marketing-sync') ?>"></p>
								</td>
							</tr>
						</tfoot>
					</table>
				</form>

			<?php else : ?>

				<p class="about-text"><?php _e('Sync user data with marketing tools!', 'marketing-sync') ?></p>


				<h3><span>1.</span> <?php _e('Connect marketing tools', 'marketing-sync') ?></h3>

				<div class="marketing-sync-admin-providers">
					<?php foreach ($this->providers as $provider) : ?>
						<div class="marketing-sync-admin-provider status-<?php echo $provider->getOption('status') ?>" style="--marketing-sync-color: <?php echo $provider->getColor() ?>; --marketing-sync-color-text: <?php echo $provider->getColor('text') ?>">
							<div class="marketing-sync-admin-provider-name">
								<?php echo $provider->getIconSvg() ?>
								<p><?php echo $provider->getLabel() ?></p>
							</div>
							<div class="marketing-sync-admin-provider-actions">
								<?php if ($provider->getOption('status') === 'needs-setup') : ?>
									<a href="<?php echo $this->adminLink(['marketing-sync-action' => 'provider', 'provider' => $provider->getId()]) ?>" class="marketing-sync-admin-provider-action"><?php _e('Connect', 'quick-login') ?></a>
								<?php elseif ($provider->getOption('status') === 'connected') : ?>
									<a href="<?php echo $this->adminLink(['marketing-sync-action' => 'provider', 'provider' => $provider->getId()]) ?>" class="marketing-sync-admin-provider-action"><?php _e('Settings', 'quick-login') ?></a>
								<?php endif ?>

								<span class="marketing-sync-admin-provider-status marketing-sync-status-<?php echo $provider->getOption('status') ?>"></span>
							</div>
						</div>
					<?php endforeach ?>
				</div>


				<div class="clear"></div>
				<h3><span>2.</span> <?php _e('Which user fields to sync?', 'marketing-sync') ?></h3>

				<p>User data is synced constantly with these services:</p>

				<table class="wp-list-table widefat fixed striped posts">
					<thead>
						<tr>
							<th scope="col" id="service" class="manage-column column-service column-primary"><?php _e('Service', 'marketing-sync') ?></th>
							<th scope="col" id="status" class="manage-column column-status"><?php _e('Status', 'marketing-sync') ?></th>
							<th scope="col" id="user-filters" class="manage-column column-user-filters"><?php _e('User filters', 'marketing-sync') ?></th>
							<th scope="col" id="fields" class="manage-column column-fields"><?php _e('Fields', 'marketing-sync') ?></th>
							<th scope="col" id="date-added" class="manage-column column-date-added"><?php _e('Date added', 'marketing-sync') ?></th>
							<th scope="col" id="last-sync" class="manage-column column-last-sync"><?php _e('Last sync', 'marketing-sync') ?></th>
						</tr>
					</thead>

					<tbody id="the-list">
						<?php foreach ($connections as $index => $connection) : ?>
							<?php
							$connection['provider'] = $this->providers[$connection['provider']];
							?>
							<tr>
								<td class="title column-provider has-row-actions column-primary page-title" data-colname="Provider">
									<a class="row-title" href="<?php echo $this->adminLink(['marketing-sync-action' => 'edit', 'id' => $index]) ?>" aria-label="Edit “<?php echo $connection['provider']->getLabel() ?>”"><?php echo $connection['provider']->getLabel() ?></a>

									<div class="row-actions">
										<span class="edit"><a href="<?php echo $this->adminLink(['marketing-sync-action' => 'edit', 'id' => $index]) ?>" aria-label="Edit “<?php echo $connection['provider']->getLabel() ?>”">Edit</a> |</span>
										<span class="trash"><a href="<?php echo $this->adminLink(['marketing-sync-action' => 'delete', 'id' => $index]) ?>" class="submitdelete" aria-label="Remove “<?php echo $connection['provider']->getLabel() ?>” account">Remove</a></span>
									</div>
								</td>

								<td class="column-status" data-colname="Status">
									<?php echo $connection['status'] ?>
								</td>

								<td class="column-role" data-colname="User Filters">
									<?php if ($connection['filter-role']) : ?>
										Role = <?php echo $connection['filter-role'] ?>
									<?php else : ?>
										<?php _e('All users', 'marketing-sync-alert') ?>
									<?php endif ?>
								</td>

								<td class="column-role" data-colname="Fields">
									<?php printf(__('%s fields', 'marketing-sync'), count($connection['fields'])) ?>
								</td>

								<td class="date column-date-aadded" data-colname="Date added">
									<abbr title="<?php echo date(get_option('date_format') . ' ' . get_option('time_format'), $connection['createdAt'] ?? $connection['created_at']) ?>"><?php echo human_time_diff($connection['createdAt'] ?? $connection['created_at']) ?> ago</abbr>
								</td>

								<td class="date column-last-sync" data-colname="Last Sync">
									<?php if (isset($connection['lastSync'])) : ?>
										<abbr title="<?php echo date(get_option('date_format') . ' ' . get_option('time_format'), $connection['lastSync']) ?>"><?php echo human_time_diff($connection['lastSync']) ?> ago</abbr>
									<?php else : ?>
										-
									<?php endif ?>
								</td>
							</tr>
						<?php endforeach ?>

						<?php if (!$connections) : ?>
							<tr class="no-items">
								<td class="colspanchange" colspan="6">No connections added yet :(</td>
							</tr>
						<?php endif ?>
					</tbody>
				</table>

				<div class="actions-bar" style="margin-top: 1rem">
					<div class="pull-right" style="float: right">
						<a href="<?php echo add_query_arg(['marketing-sync-action' => 'sync'], menu_page_url('marketing-sync', false)) ?>" class="button button-small pull-right"><i class="fa fa-cloud-download"></i> Sync now</a>
					</div>

					<a href="<?php echo add_query_arg(['marketing-sync-action' => 'add', 'provider' => 'mailChimp'], menu_page_url('marketing-sync', false)) ?>" class="button button-primary"><i class="fa fa-instagram"></i> Add MailChimp</a>
					<a href="<?php echo add_query_arg(['marketing-sync-action' => 'add', 'provider' => 'activeCampaign'], menu_page_url('marketing-sync', false)) ?>" class="button button-primary"><i class="fa fa-google"></i> Add ActiveCampaign</a>
				</div>

			<?php endif ?>

		</div>
		<?php
	}

}
