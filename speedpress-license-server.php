<?php
/**
 * Plugin Name:       SpeedPress License Server
 * Description:       SaaS license console for SpeedPress plugins. Free, Premium yearly, Lifetime, and Agency codes. Expired yearly keys are revoked automatically. Lifetime keys never expire.
 * Version:           1.3.2
 * Author:            SpeedPress
 * Author URI:        https://wpspeedpress.com
 * Text Domain:       speedpress-license-server
 */

defined( 'ABSPATH' ) || exit;

define( 'SPLS_VERSION', '1.3.2' );
define( 'SPLS_FILE', __FILE__ );
define( 'SPLS_PATH', plugin_dir_path( __FILE__ ) );
define( 'SPLS_URL', plugin_dir_url( __FILE__ ) );
define( 'SPLS_OPTION_KEYS', 'spls_keys' );
define( 'SPLS_OPTION_SITES', 'spls_sites' );

add_action( 'admin_menu', 'spls_menu' );
add_action( 'admin_init', 'spls_handle' );
add_action( 'admin_enqueue_scripts', 'spls_assets' );
add_action( 'rest_api_init', 'spls_routes' );
add_action( 'init', 'spls_schedule_expire' );
add_action( 'spls_expire_licenses', 'spls_expire_due_keys' );

/**
 * Menu.
 */
function spls_menu() {
	add_menu_page(
		'SpeedPress Licenses',
		'SP Licenses',
		'manage_options',
		'spls-licenses',
		'spls_page',
		'dashicons-shield-alt',
		58
	);
}

/**
 * Assets.
 *
 * @param string $hook Hook.
 */
function spls_assets( $hook ) {
	wp_enqueue_style( 'spls-menu', SPLS_URL . 'assets/menu.css', array(), SPLS_VERSION );
	if ( false === strpos( (string) $hook, 'spls-licenses' ) ) {
		return;
	}
	wp_enqueue_style( 'spls-admin', SPLS_URL . 'assets/admin.css', array(), SPLS_VERSION );
}

/**
 * Daily expiry pass.
 */
function spls_schedule_expire() {
	if ( ! wp_next_scheduled( 'spls_expire_licenses' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'spls_expire_licenses' );
	}
}

/**
 * Whether a key is lifetime billed.
 *
 * @param array $row Key row.
 * @return bool
 */
function spls_is_lifetime( $row ) {
	$billing = $row['billing'] ?? '';
	$plan    = $row['plan'] ?? '';
	return 'lifetime' === $billing || 'lifetime' === $plan;
}

/**
 * Revoke dated yearly keys. Lifetime is never touched.
 */
function spls_expire_due_keys() {
	$keys  = get_option( SPLS_OPTION_KEYS, array() );
	$dirty = false;
	foreach ( $keys as $code => $row ) {
		if ( spls_is_lifetime( $row ) ) {
			continue;
		}
		if ( 'active' !== ( $row['status'] ?? '' ) ) {
			continue;
		}
		$exp = $row['expires_at'] ?? '';
		if ( ! $exp ) {
			continue;
		}
		if ( strtotime( $exp . ' 23:59:59' ) < time() ) {
			$keys[ $code ]['status'] = 'expired';
			$dirty                   = true;
			spls_mark_sites_for_key( $code, 'expired' );
		}
	}
	if ( $dirty ) {
		update_option( SPLS_OPTION_KEYS, $keys );
	}
}

/**
 * Allowed site statuses.
 *
 * @return array
 */
function spls_statuses() {
	return array(
		'active'         => 'Active',
		'deactivated'    => 'Deactivated',
		'key_removed'    => 'Key removed',
		'plugin_removed' => 'Plugin removed',
		'revoked'        => 'Revoked',
		'expired'        => 'Expired',
	);
}

/**
 * Normalize site URL for matching.
 *
 * @param string $url URL.
 * @return string
 */
function spls_norm_url( $url ) {
	$url = esc_url_raw( $url );
	$url = untrailingslashit( strtolower( $url ) );
	$url = preg_replace( '#^https?://#', '', $url );
	return $url;
}

/**
 * Create / revoke / restore keys.
 */
function spls_handle() {
	if ( empty( $_POST['spls_action'] ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	check_admin_referer( 'spls_keys' );
	$action = sanitize_key( wp_unslash( $_POST['spls_action'] ) );
	$keys   = get_option( SPLS_OPTION_KEYS, array() );

	if ( 'create' === $action ) {
		$plan = sanitize_key( wp_unslash( $_POST['spls_plan'] ?? 'free' ) );
		if ( ! in_array( $plan, array( 'free', 'premium', 'lifetime', 'agency' ), true ) ) {
			$plan = 'free';
		}
		$billing = 'lifetime' === $plan ? 'lifetime' : 'yearly';
		$prefix  = array(
			'free'     => 'SPPA-FREE',
			'premium'  => 'SPPA-PRO',
			'lifetime' => 'SPPA-LIFE',
			'agency'   => 'SPPA-AGY',
		);
		$code    = ( $prefix[ $plan ] ?? 'SPPA' ) . '-' . strtoupper( wp_generate_password( 4, false, false ) ) . '-' . strtoupper( wp_generate_password( 4, false, false ) );
		$expires = sanitize_text_field( wp_unslash( $_POST['spls_expires'] ?? '' ) );
		$max     = absint( $_POST['spls_max'] ?? 1 );
		if ( 'agency' === $plan && $max < 2 ) {
			$max = 10;
		}
		if ( 'lifetime' === $plan ) {
			$expires = '';
			$max     = max( 1, $max );
		} elseif ( in_array( $plan, array( 'premium', 'agency' ), true ) && ! $expires ) {
			$expires = gmdate( 'Y-m-d', strtotime( '+1 year' ) );
		}
		$keys[ $code ] = array(
			'key'        => $code,
			'plan'       => $plan,
			'billing'    => $billing,
			'note'       => sanitize_text_field( wp_unslash( $_POST['spls_note'] ?? '' ) ),
			'customer'   => sanitize_text_field( wp_unslash( $_POST['spls_customer'] ?? '' ) ),
			'email'      => sanitize_email( wp_unslash( $_POST['spls_email'] ?? '' ) ),
			'status'     => 'active',
			'created_at' => time(),
			'max_sites'  => max( 1, $max ),
			'expires_at' => $expires,
		);
		update_option( SPLS_OPTION_KEYS, $keys );
		add_settings_error( 'spls', 'created', 'API code created: ' . $code, 'updated' );
	}

	if ( 'revoke' === $action || 'restore' === $action ) {
		$code = sanitize_text_field( wp_unslash( $_POST['spls_key'] ?? '' ) );
		if ( isset( $keys[ $code ] ) ) {
			$keys[ $code ]['status'] = 'revoke' === $action ? 'revoked' : 'active';
			update_option( SPLS_OPTION_KEYS, $keys );
			if ( 'revoke' === $action ) {
				spls_mark_sites_for_key( $code, 'revoked' );
			}
		}
	}

	if ( 'update' === $action ) {
		$code = sanitize_text_field( wp_unslash( $_POST['spls_key'] ?? '' ) );
		if ( isset( $keys[ $code ] ) ) {
			$plan = sanitize_key( wp_unslash( $_POST['spls_plan'] ?? $keys[ $code ]['plan'] ) );
			if ( ! in_array( $plan, array( 'free', 'premium', 'lifetime', 'agency' ), true ) ) {
				$plan = $keys[ $code ]['plan'] ?? 'free';
			}
			$billing = 'lifetime' === $plan ? 'lifetime' : 'yearly';
			$expires = sanitize_text_field( wp_unslash( $_POST['spls_expires'] ?? '' ) );
			if ( 'lifetime' === $plan ) {
				$expires = '';
			}
			$keys[ $code ]['plan']       = $plan;
			$keys[ $code ]['billing']    = $billing;
			$keys[ $code ]['note']       = sanitize_text_field( wp_unslash( $_POST['spls_note'] ?? '' ) );
			$keys[ $code ]['customer']   = sanitize_text_field( wp_unslash( $_POST['spls_customer'] ?? '' ) );
			$keys[ $code ]['email']      = sanitize_email( wp_unslash( $_POST['spls_email'] ?? '' ) );
			$keys[ $code ]['max_sites']  = max( 1, absint( $_POST['spls_max'] ?? 1 ) );
			$keys[ $code ]['expires_at'] = $expires;
			$keys[ $code ]['updated_at'] = time();
			update_option( SPLS_OPTION_KEYS, $keys );
			add_settings_error( 'spls', 'updated', 'License updated: ' . $code, 'updated' );
		}
	}

	if ( 'delete' === $action ) {
		$code = sanitize_text_field( wp_unslash( $_POST['spls_key'] ?? '' ) );
		if ( isset( $keys[ $code ] ) ) {
			unset( $keys[ $code ] );
			update_option( SPLS_OPTION_KEYS, $keys );
			$sites = get_option( SPLS_OPTION_SITES, array() );
			foreach ( $sites as &$row ) {
				if ( ( $row['key'] ?? '' ) === $code ) {
					$row['status'] = 'revoked';
					$row['active'] = 0;
				}
			}
			unset( $row );
			update_option( SPLS_OPTION_SITES, $sites );
			add_settings_error( 'spls', 'deleted', 'License deleted: ' . $code, 'updated' );
		}
	}

	if ( 'delete_site' === $action ) {
		$url   = esc_url_raw( wp_unslash( $_POST['spls_site'] ?? '' ) );
		$sites = get_option( SPLS_OPTION_SITES, array() );
		$keep  = array();
		foreach ( $sites as $row ) {
			if ( spls_norm_url( $row['site_url'] ?? '' ) !== spls_norm_url( $url ) ) {
				$keep[] = $row;
			}
		}
		update_option( SPLS_OPTION_SITES, $keep );
		add_settings_error( 'spls', 'site_deleted', 'Website removed from the list.', 'updated' );
	}
}

/**
 * Mark all sites on a key.
 *
 * @param string $code   Key.
 * @param string $status Status.
 */
function spls_mark_sites_for_key( $code, $status ) {
	$sites = get_option( SPLS_OPTION_SITES, array() );
	foreach ( $sites as &$row ) {
		if ( ( $row['key'] ?? '' ) === $code ) {
			$row['status']    = $status;
			$row['active']    = 0;
			$row['last_seen'] = time();
		}
	}
	unset( $row );
	update_option( SPLS_OPTION_SITES, $sites );
}

/**
 * REST.
 */
function spls_routes() {
	$open = array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
	);
	register_rest_route( 'speedpress-license/v1', '/activate', array_merge( $open, array( 'callback' => 'spls_activate' ) ) );
	register_rest_route( 'speedpress-license/v1', '/deactivate', array_merge( $open, array( 'callback' => 'spls_deactivate' ) ) );
	register_rest_route( 'speedpress-license/v1', '/report', array_merge( $open, array( 'callback' => 'spls_report' ) ) );
}

/**
 * Shared payload parse.
 *
 * @param WP_REST_Request $request Request.
 * @return array
 */
function spls_body( $request ) {
	$body = $request->get_json_params();
	if ( ! is_array( $body ) ) {
		$body = $request->get_params();
	}
	return is_array( $body ) ? $body : array();
}

/**
 * Upsert a site row. Never deletes.
 *
 * @param array $incoming Incoming fields.
 * @return array
 */
function spls_upsert_site( $incoming ) {
	$sites = get_option( SPLS_OPTION_SITES, array() );
	$norm  = spls_norm_url( $incoming['site_url'] ?? '' );
	$key   = strtoupper( preg_replace( '/[^A-Z0-9\-]/i', '', $incoming['key'] ?? '' ) );
	$found = false;

	foreach ( $sites as &$row ) {
		$same_url = spls_norm_url( $row['site_url'] ?? '' ) === $norm && $norm;
		if ( $same_url ) {
			$row['key']            = $key ? $key : ( $row['key'] ?? '' );
			$row['site_url']       = esc_url_raw( $incoming['site_url'] ?? $row['site_url'] );
			$row['site_name']      = sanitize_text_field( $incoming['site_name'] ?? ( $row['site_name'] ?? '' ) );
			$row['admin_email']    = sanitize_email( $incoming['admin_email'] ?? ( $row['admin_email'] ?? '' ) );
			$row['plugin_version'] = sanitize_text_field( $incoming['plugin_version'] ?? ( $row['plugin_version'] ?? '' ) );
			$row['wp_version']     = sanitize_text_field( $incoming['wp_version'] ?? ( $row['wp_version'] ?? '' ) );
			$row['wc_version']     = sanitize_text_field( $incoming['wc_version'] ?? ( $row['wc_version'] ?? '' ) );
			$row['plan']           = sanitize_key( $incoming['plan'] ?? ( $row['plan'] ?? '' ) );
			$row['status']         = sanitize_key( $incoming['status'] ?? ( $row['status'] ?? 'active' ) );
			$row['active']         = ! empty( $incoming['active'] ) ? 1 : 0;
			$row['last_seen']      = time();
			$found                 = true;
			break;
		}
	}
	unset( $row );

	if ( ! $found ) {
		$sites[] = array(
			'id'             => wp_generate_uuid4(),
			'key'            => $key,
			'site_url'       => esc_url_raw( $incoming['site_url'] ?? '' ),
			'site_name'      => sanitize_text_field( $incoming['site_name'] ?? '' ),
			'admin_email'    => sanitize_email( $incoming['admin_email'] ?? '' ),
			'plugin_version' => sanitize_text_field( $incoming['plugin_version'] ?? '' ),
			'wp_version'     => sanitize_text_field( $incoming['wp_version'] ?? '' ),
			'wc_version'     => sanitize_text_field( $incoming['wc_version'] ?? '' ),
			'plan'           => sanitize_key( $incoming['plan'] ?? '' ),
			'status'         => sanitize_key( $incoming['status'] ?? 'active' ),
			'active'         => ! empty( $incoming['active'] ) ? 1 : 0,
			'first_seen'     => time(),
			'last_seen'      => time(),
		);
	}

	update_option( SPLS_OPTION_SITES, $sites );
	return $sites;
}

/**
 * Activate.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function spls_activate( $request ) {
	spls_expire_due_keys();
	$body = spls_body( $request );
	$key  = strtoupper( preg_replace( '/[^A-Z0-9\-]/i', '', $body['key'] ?? '' ) );
	$site = esc_url_raw( $body['site_url'] ?? '' );
	$keys = get_option( SPLS_OPTION_KEYS, array() );

	if ( empty( $keys[ $key ] ) || ! in_array( $keys[ $key ]['status'] ?? '', array( 'active' ), true ) ) {
		if ( $site ) {
			spls_upsert_site(
				array(
					'key'         => $key,
					'site_url'    => $site,
					'site_name'   => $body['site_name'] ?? '',
					'admin_email' => $body['admin_email'] ?? '',
					'status'      => 'key_removed',
					'active'      => 0,
				)
			);
		}
		return rest_ensure_response( array( 'success' => false, 'message' => 'Invalid, revoked, or expired API code.' ) );
	}

	$key_row = $keys[ $key ];
	if ( ! spls_is_lifetime( $key_row ) && ! empty( $key_row['expires_at'] ) && strtotime( $key_row['expires_at'] . ' 23:59:59' ) < time() ) {
		$keys[ $key ]['status'] = 'expired';
		update_option( SPLS_OPTION_KEYS, $keys );
		spls_mark_sites_for_key( $key, 'expired' );
		return rest_ensure_response( array( 'success' => false, 'message' => 'This API code has expired.', 'plan' => $key_row['plan'], 'billing' => $key_row['billing'] ?? 'yearly' ) );
	}

	$sites  = get_option( SPLS_OPTION_SITES, array() );
	$active = array();
	foreach ( $sites as $row ) {
		if ( ( $row['key'] ?? '' ) === $key && 'active' === ( $row['status'] ?? '' ) ) {
			$active[ spls_norm_url( $row['site_url'] ) ] = true;
		}
	}
	$max = absint( $key_row['max_sites'] ?? 1 );
	if ( count( $active ) >= $max && empty( $active[ spls_norm_url( $site ) ] ) ) {
		return rest_ensure_response( array( 'success' => false, 'message' => 'This API code is already used on the allowed number of sites.' ) );
	}

	spls_upsert_site(
		array(
			'key'            => $key,
			'site_url'       => $site,
			'site_name'      => $body['site_name'] ?? '',
			'admin_email'    => $body['admin_email'] ?? '',
			'plugin_version' => $body['plugin_version'] ?? '',
			'wp_version'     => $body['wp_version'] ?? '',
			'wc_version'     => $body['wc_version'] ?? '',
			'plan'           => $key_row['plan'],
			'status'         => 'active',
			'active'         => 1,
		)
	);

	return rest_ensure_response(
		array(
			'success'    => true,
			'message'    => 'Activated.',
			'plan'       => $key_row['plan'],
			'billing'    => $key_row['billing'] ?? ( spls_is_lifetime( $key_row ) ? 'lifetime' : 'yearly' ),
			'expires_at' => spls_is_lifetime( $key_row ) ? '' : ( $key_row['expires_at'] ?? '' ),
		)
	);
}

/**
 * Deactivate (keeps the site row).
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function spls_deactivate( $request ) {
	$body = spls_body( $request );
	spls_upsert_site(
		array(
			'key'            => $body['key'] ?? '',
			'site_url'       => $body['site_url'] ?? '',
			'site_name'      => $body['site_name'] ?? '',
			'admin_email'    => $body['admin_email'] ?? '',
			'plugin_version' => $body['plugin_version'] ?? '',
			'status'         => 'deactivated',
			'active'         => 0,
		)
	);
	return rest_ensure_response( array( 'success' => true, 'message' => 'Deactivated. Site kept on file.' ) );
}

/**
 * Generic status report.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function spls_report( $request ) {
	$body   = spls_body( $request );
	$status = sanitize_key( $body['status'] ?? 'key_removed' );
	if ( ! isset( spls_statuses()[ $status ] ) ) {
		$status = 'key_removed';
	}
	spls_upsert_site(
		array(
			'key'            => $body['key'] ?? '',
			'site_url'       => $body['site_url'] ?? '',
			'site_name'      => $body['site_name'] ?? '',
			'admin_email'    => $body['admin_email'] ?? '',
			'plugin_version' => $body['plugin_version'] ?? '',
			'wp_version'     => $body['wp_version'] ?? '',
			'wc_version'     => $body['wc_version'] ?? '',
			'status'         => $status,
			'active'         => 0,
		)
	);
	return rest_ensure_response( array( 'success' => true, 'message' => 'Status saved.', 'status' => $status ) );
}

/**
 * Admin UI.
 */
function spls_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	spls_expire_due_keys();
	settings_errors( 'spls' );
	$keys   = get_option( SPLS_OPTION_KEYS, array() );
	$sites  = get_option( SPLS_OPTION_SITES, array() );
	$filter = sanitize_key( wp_unslash( $_GET['spls_status'] ?? 'all' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$edit   = sanitize_text_field( wp_unslash( $_GET['spls_edit'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$editing = ( $edit && isset( $keys[ $edit ] ) ) ? $keys[ $edit ] : null;

	$counts = array(
		'sites'   => count( $sites ),
		'active'  => 0,
		'premium' => 0,
		'removed' => 0,
	);
	foreach ( $sites as $row ) {
		if ( 'active' === ( $row['status'] ?? '' ) ) {
			++$counts['active'];
		}
		if ( in_array( $row['status'] ?? '', array( 'key_removed', 'plugin_removed', 'deactivated', 'expired' ), true ) ) {
			++$counts['removed'];
		}
		if ( in_array( $row['plan'] ?? '', array( 'premium', 'lifetime', 'agency' ), true ) ) {
			++$counts['premium'];
		}
	}
	?>
	<div class="wrap spls-app">
		<div class="spls-hero">
			<div class="spls-brand">
				<div class="spls-logo">SP</div>
				<div>
					<div class="spls-kicker">SpeedPress</div>
					<h1>License console</h1>
					<p>Free, Premium ($29/yr), Lifetime ($100), Agency ($29 × sites / year). Yearly codes expire on the date you set. Lifetime never expires.</p>
				</div>
			</div>
		</div>

		<div class="spls-stats">
			<div class="spls-stat"><div class="spls-stat-ico"><span class="dashicons dashicons-admin-network"></span></div><strong><?php echo esc_html( (string) count( $keys ) ); ?></strong><span>API codes</span></div>
			<div class="spls-stat"><div class="spls-stat-ico"><span class="dashicons dashicons-admin-site-alt3"></span></div><strong><?php echo esc_html( (string) $counts['sites'] ); ?></strong><span>Websites on file</span></div>
			<div class="spls-stat"><div class="spls-stat-ico"><span class="dashicons dashicons-yes-alt"></span></div><strong><?php echo esc_html( (string) $counts['active'] ); ?></strong><span>Active now</span></div>
			<div class="spls-stat"><div class="spls-stat-ico"><span class="dashicons dashicons-star-filled"></span></div><strong><?php echo esc_html( (string) $counts['premium'] ); ?></strong><span>Paid sites</span></div>
			<div class="spls-stat"><div class="spls-stat-ico"><span class="dashicons dashicons-email-alt"></span></div><strong><?php echo esc_html( (string) $counts['removed'] ); ?></strong><span>Need a follow-up</span></div>
		</div>

		<div class="spls-layout">
			<div class="spls-card">
				<div class="spls-card-head"><span class="spls-card-ico"><span class="dashicons dashicons-<?php echo $editing ? 'edit' : 'plus-alt'; ?>"></span></span><h2><?php echo $editing ? 'Edit license' : 'New API code'; ?></h2></div>
				<?php if ( $editing ) : ?>
					<p class="spls-muted">Editing <span class="spls-code"><?php echo esc_html( $editing['key'] ); ?></span> · <a href="<?php echo esc_url( admin_url( 'admin.php?page=spls-licenses' ) ); ?>">Cancel</a></p>
				<?php endif; ?>
				<form method="post">
					<?php wp_nonce_field( 'spls_keys' ); ?>
					<input type="hidden" name="spls_action" value="<?php echo $editing ? 'update' : 'create'; ?>" />
					<?php if ( $editing ) : ?>
						<input type="hidden" name="spls_key" value="<?php echo esc_attr( $editing['key'] ); ?>" />
					<?php endif; ?>
					<div class="spls-field">
						<label>Plan</label>
						<select name="spls_plan">
							<?php
							$plans = array(
								'free'     => 'Free — $0, limited fields',
								'premium'  => 'Premium — $29 / year / site',
								'lifetime' => 'Lifetime — $100 / site, never expires',
								'agency'   => 'Agency — $29 × sites / year',
							);
							$cur = $editing['plan'] ?? 'free';
							foreach ( $plans as $pk => $pl ) {
								printf( '<option value="%s"%s>%s</option>', esc_attr( $pk ), selected( $cur, $pk, false ), esc_html( $pl ) );
							}
							?>
						</select>
					</div>
					<div class="spls-field">
						<label>Customer</label>
						<input type="text" name="spls_customer" value="<?php echo esc_attr( $editing['customer'] ?? '' ); ?>" />
					</div>
					<div class="spls-field">
						<label>Contact email</label>
						<input type="email" name="spls_email" value="<?php echo esc_attr( $editing['email'] ?? '' ); ?>" />
					</div>
					<div class="spls-field">
						<label>Internal note</label>
						<input type="text" name="spls_note" value="<?php echo esc_attr( $editing['note'] ?? '' ); ?>" />
					</div>
					<div class="spls-field">
						<label>Max sites</label>
						<input type="number" name="spls_max" value="<?php echo esc_attr( (string) ( $editing['max_sites'] ?? 1 ) ); ?>" min="1" />
					</div>
					<div class="spls-field">
						<label>Expires (empty = Lifetime / never)</label>
						<input type="date" name="spls_expires" value="<?php echo esc_attr( $editing['expires_at'] ?? '' ); ?>" />
					</div>
					<button class="spls-btn" type="submit">
						<span class="dashicons dashicons-<?php echo $editing ? 'saved' : 'plus-alt2'; ?>"></span>
						<?php echo $editing ? 'Update license' : 'Create API code'; ?>
					</button>
				</form>
			</div>

			<div>
				<div class="spls-card">
					<div class="spls-card-head"><span class="spls-card-ico"><span class="dashicons dashicons-lock"></span></span><h2>Codes</h2></div>
					<table class="spls-table">
						<thead>
							<tr>
								<th>Code</th>
								<th>Plan</th>
								<th>Billing</th>
								<th>Expires</th>
								<th>Status</th>
								<th></th>
							</tr>
						</thead>
						<tbody>
						<?php if ( ! $keys ) : ?>
							<tr><td colspan="6">No codes yet.</td></tr>
						<?php endif; ?>
						<?php foreach ( $keys as $row ) : ?>
							<tr>
								<td><span class="spls-code"><?php echo esc_html( $row['key'] ); ?></span>
									<div class="spls-muted"><?php echo esc_html( $row['customer'] ?? '' ); ?> <?php echo esc_html( $row['email'] ?? '' ); ?></div>
								</td>
								<td><span class="spls-pill spls-pill-<?php echo esc_attr( 'free' === ( $row['plan'] ?? '' ) ? 'free' : 'premium' ); ?>"><?php echo esc_html( $row['plan'] ?? 'free' ); ?></span></td>
								<td><?php echo esc_html( $row['billing'] ?? ( 'lifetime' === ( $row['plan'] ?? '' ) ? 'lifetime' : 'yearly' ) ); ?></td>
								<td><?php echo spls_is_lifetime( $row ) ? 'Never' : esc_html( $row['expires_at'] ?: '—' ); ?></td>
								<td><span class="spls-pill spls-pill-<?php echo esc_attr( $row['status'] ); ?>"><?php echo esc_html( $row['status'] ); ?></span></td>
								<td class="spls-actions">
									<a class="spls-btn-edit" href="<?php echo esc_url( add_query_arg( 'spls_edit', rawurlencode( $row['key'] ), admin_url( 'admin.php?page=spls-licenses' ) ) ); ?>"><span class="dashicons dashicons-edit"></span> Edit</a>
									<form method="post">
										<?php wp_nonce_field( 'spls_keys' ); ?>
										<input type="hidden" name="spls_key" value="<?php echo esc_attr( $row['key'] ); ?>" />
										<?php if ( 'active' === $row['status'] ) : ?>
											<button class="spls-btn-warn" name="spls_action" value="revoke"><span class="dashicons dashicons-lock"></span> Revoke</button>
										<?php else : ?>
											<button class="spls-btn-ok" name="spls_action" value="restore"><span class="dashicons dashicons-unlock"></span> Restore</button>
										<?php endif; ?>
									</form>
									<form method="post" onsubmit="return confirm('Delete this license code? Sites stay on file as revoked.');">
										<?php wp_nonce_field( 'spls_keys' ); ?>
										<input type="hidden" name="spls_key" value="<?php echo esc_attr( $row['key'] ); ?>" />
										<button class="spls-btn-danger" name="spls_action" value="delete"><span class="dashicons dashicons-trash"></span> Delete</button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
		</div>

		<div class="spls-card">
			<div class="spls-card-head"><span class="spls-card-ico"><span class="dashicons dashicons-admin-site-alt3"></span></span><h2>Websites</h2></div>
			<div class="spls-filters">
				<?php
				$filters = array( 'all' => 'All' ) + spls_statuses();
				foreach ( $filters as $slug => $label ) :
					$url = add_query_arg( 'spls_status', $slug, admin_url( 'admin.php?page=spls-licenses' ) );
					?>
					<a class="spls-pill <?php echo $filter === $slug ? 'spls-pill-premium' : 'spls-pill-free'; ?>" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</div>
			<table class="spls-table">
				<thead>
					<tr>
						<th>Website</th>
						<th>Contact</th>
						<th>Plan</th>
						<th>Status</th>
						<th>API code</th>
						<th>Last seen</th>
						<th></th>
					</tr>
				</thead>
				<tbody>
				<?php
				$shown = 0;
				foreach ( $sites as $row ) :
					if ( 'all' !== $filter && ( $row['status'] ?? '' ) !== $filter ) {
						continue;
					}
					++$shown;
					$email = $row['admin_email'] ?? '';
					?>
					<tr>
						<td class="spls-site">
							<a href="<?php echo esc_url( $row['site_url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $row['site_url'] ); ?></a>
							<div class="spls-muted"><?php echo esc_html( $row['site_name'] ?? '' ); ?></div>
						</td>
						<td>
							<?php if ( $email ) : ?>
								<a href="<?php echo esc_url( 'mailto:' . $email ); ?>"><?php echo esc_html( $email ); ?></a>
							<?php else : ?>
								<span class="spls-muted">No email</span>
							<?php endif; ?>
						</td>
						<td><span class="spls-pill spls-pill-<?php echo esc_attr( 'free' === ( $row['plan'] ?? '' ) ? 'free' : 'premium' ); ?>"><?php echo esc_html( $row['plan'] ?: '—' ); ?></span></td>
						<td><span class="spls-pill spls-pill-<?php echo esc_attr( $row['status'] ?? '' ); ?>"><?php echo esc_html( $row['status'] ?? '' ); ?></span></td>
						<td><span class="spls-code"><?php echo esc_html( $row['key'] ?? '' ); ?></span></td>
						<td><?php echo ! empty( $row['last_seen'] ) ? esc_html( gmdate( 'Y-m-d H:i', (int) $row['last_seen'] ) ) : '—'; ?></td>
						<td>
							<form method="post" onsubmit="return confirm('Remove this website from the list?');">
								<?php wp_nonce_field( 'spls_keys' ); ?>
								<input type="hidden" name="spls_site" value="<?php echo esc_attr( $row['site_url'] ?? '' ); ?>" />
								<button class="spls-btn-danger" name="spls_action" value="delete_site"><span class="dashicons dashicons-trash"></span> Delete</button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				<?php if ( ! $shown ) : ?>
					<tr><td colspan="6">No websites in this filter.</td></tr>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
	</div>
	<?php
}
