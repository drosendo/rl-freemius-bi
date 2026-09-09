<?php
/**
 * Admin Settings Page Template
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

// Handle form submission
if ( isset( $_POST['rl_fsbi_settings_nonce'] ) && wp_verify_nonce( $_POST['rl_fsbi_settings_nonce'], 'rl_fsbi_settings' ) ) {
	if ( current_user_can( 'manage_options' ) ) {
		update_option( 'rl_fsbi_developer_id', sanitize_text_field( $_POST['rl_fsbi_developer_id'] ?? '' ) );
		update_option( 'rl_fsbi_public_key', sanitize_text_field( $_POST['rl_fsbi_public_key'] ?? '' ) );
		update_option( 'rl_fsbi_secret_key', sanitize_text_field( $_POST['rl_fsbi_secret_key'] ?? '' ) );
		echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved successfully.', 'rl-freemius-bi' ) . '</p></div>';
	}
}

$developer_id = get_option( 'rl_fsbi_developer_id' );
$public_key   = get_option( 'rl_fsbi_public_key' );
$secret_key   = get_option( 'rl_fsbi_secret_key' );
?>

<div class="wrap rl-fsbi-settings">
	<h1><?php echo esc_html__( 'RL Freemius BI Settings', 'rl-freemius-bi' ); ?></h1>

	<form method="post" action="">
		<?php wp_nonce_field( 'rl_fsbi_settings', 'rl_fsbi_settings_nonce' ); ?>

		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row">
						<label for="rl_fsbi_developer_id">
							<?php echo esc_html__( 'Developer ID', 'rl-freemius-bi' ); ?>
						</label>
					</th>
					<td>
						<input type="text" name="rl_fsbi_developer_id" id="rl_fsbi_developer_id" value="<?php echo esc_attr( $developer_id ); ?>" class="regular-text" />
						<p class="description">
							<?php echo esc_html__( 'Your Freemius Developer ID from developer.freemius.com', 'rl-freemius-bi' ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="rl_fsbi_public_key">
							<?php echo esc_html__( 'Public API Key', 'rl-freemius-bi' ); ?>
						</label>
					</th>
					<td>
						<input type="text" name="rl_fsbi_public_key" id="rl_fsbi_public_key" value="<?php echo esc_attr( $public_key ); ?>" class="regular-text" />
						<p class="description">
							<?php echo esc_html__( 'Your Freemius public API key (starts with pk_)', 'rl-freemius-bi' ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="rl_fsbi_secret_key">
							<?php echo esc_html__( 'Secret API Key', 'rl-freemius-bi' ); ?>
						</label>
					</th>
					<td>
						<input type="password" name="rl_fsbi_secret_key" id="rl_fsbi_secret_key" value="<?php echo esc_attr( $secret_key ); ?>" class="regular-text" />
						<p class="description">
							<?php echo esc_html__( 'Your Freemius secret API key (starts with sk_). Keep this secure!', 'rl-freemius-bi' ); ?>
						</p>
					</td>
				</tr>
			</tbody>
		</table>

		<?php submit_button(); ?>
	</form>

	<hr />

	<h2><?php echo esc_html__( 'API Credentials Help', 'rl-freemius-bi' ); ?></h2>
	<ol>
		<li><?php echo esc_html__( 'Visit developer.freemius.com', 'rl-freemius-bi' ); ?></li>
		<li><?php echo esc_html__( 'Navigate to "Account" → "REST API" or similar section', 'rl-freemius-bi' ); ?></li>
		<li><?php echo esc_html__( 'Copy your Developer ID and API keys', 'rl-freemius-bi' ); ?></li>
		<li><?php echo esc_html__( 'Paste them below and save', 'rl-freemius-bi' ); ?></li>
	</ol>

	<h2><?php echo esc_html__( 'Database Tables', 'rl-freemius-bi' ); ?></h2>
	<p><?php echo esc_html__( 'The following tables have been created:', 'rl-freemius-bi' ); ?></p>
	<ul>
		<li><code><?php global $wpdb; echo esc_html( $wpdb->prefix ); ?>rl_fsbi_payments</code></li>
		<li><code><?php echo esc_html( $wpdb->prefix ); ?>rl_fsbi_subscriptions</code></li>
		<li><code><?php echo esc_html( $wpdb->prefix ); ?>rl_fsbi_licenses</code></li>
		<li><code><?php echo esc_html( $wpdb->prefix ); ?>rl_fsbi_plans</code></li>
		<li><code><?php echo esc_html( $wpdb->prefix ); ?>rl_fsbi_balances</code></li>
	</ul>
</div>
