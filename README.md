# SpeedPress License Server — complete guide

Use one license console on **your** WordPress site (wpspeedpress.com) to activate any SpeedPress plugin: Product Add-Ons today, the next premium plugin tomorrow. No Mailchimp, no license SaaS, no third-party email.

This guide covers:

1. What you install where  
2. How you issue Free vs Premium codes  
3. How a customer site talks to your server  
4. REST API (copy this into any other plugin)  
5. Statuses and how you contact people after they remove a key  
6. Drop-in PHP client you can paste into a new plugin  
7. Feature gating (free vs premium)  
8. wp-config overrides and local development  
9. Operations, security, and limits  

---

## 1. Two plugins, two roles

| Plugin | Install on | Job |
|---|---|---|
| **SpeedPress License Server** | `wpspeedpress.com` only | Create API codes, store every website, show status |
| **Your product plugin** (Product Add-Ons, or the next one) | Customer WordPress | Ask for the API code, lock features until valid, report site URL + email |

The product plugin never stores the customer list. It only POSTs to your site.

Base URL used by Product Add-Ons:

```
https://wpspeedpress.com
```

Override on a customer site if needed:

```php
// wp-config.php on the CUSTOMER site
define( 'SPPA_LICENSE_SERVER', 'https://wpspeedpress.com' );
```

For a *different* product plugin, use your own constant name, e.g. `SPFOO_LICENSE_SERVER`, pointing at the same host.

After installing the license server, visit **Settings → Permalinks → Save** once so these routes exist:

```
POST /wp-json/speedpress-license/v1/activate
POST /wp-json/speedpress-license/v1/deactivate
POST /wp-json/speedpress-license/v1/report
```

---

## 2. Daily workflow on wpspeedpress.com

Open **WP Admin → SP Licenses**.

### Create a code

1. Choose **Plan**: `Free` or `Premium`  
2. Customer name  
3. Contact email (your record of who bought it)  
4. Internal note  
5. Max sites (Free default 1, Premium often 3+)  
6. Optional expiry date  
7. **Create API code**

You get a code like:

- Free: `SPPA-FREE-7K2P-9QWM`  
- Premium: `SPPA-PRO-4HXT-2N8C`

Send that code to the customer (invoice, email, WhatsApp — your choice). They paste it into the product plugin settings.

### What you see after they activate

The **Websites** table keeps a row forever:

- Site URL (clickable)  
- Site title  
- Admin email from their WordPress (`admin_email`) — mailto link  
- Plan  
- Status  
- API code last used  
- Last seen (UTC)

Filter pills: All / Active / Deactivated / Key removed / Plugin removed / Revoked.

**Need a follow-up** on the dashboard is Deactivated + Key removed + Plugin removed. Those are the people to contact.

### Revoke / restore

Revoke a code if a chargeback or a leaked key. Every site still using that code is marked `revoked`. Restore puts the code back in circulation.

Rows are **never deleted**. If they wipe the key, you still have the URL and email.

---

## 3. What the customer does

In Product Add-Ons: **SpeedPress Product Add-ons → Settings → API code**.

| Button | Local effect | What your server stores |
|---|---|---|
| **Activate** | Unlocks the plugin if the code is valid | Status `active`, plan free/premium, email, versions |
| **Deactivate** | Locks the plugin, key stays in the field | Status `deactivated`, site kept |
| **Remove API code** | Clears the field, plugin stays locked | Status `key_removed`, last key kept on the server |
| They deactivate the plugin in WP | Plugin off | Status `plugin_removed` |
| Weekly cron | Silent | Refreshes `last_seen`. If the key is gone it re-sends `key_removed` |

Until Activate succeeds, Product Add-Ons hides the product builder and storefront fields.

---

## 4. REST API (use this from any plugin)

All requests: `POST`, `Content-Type: application/json`. No auth cookie. The **API code** is the credential.

### 4.1 Activate

`POST {SERVER}/wp-json/speedpress-license/v1/activate`

```json
{
  "key": "SPPA-PRO-4HXT-2N8C",
  "site_url": "https://client-store.com",
  "site_name": "Client Store",
  "admin_email": "owner@client-store.com",
  "plugin_version": "1.0.0",
  "wp_version": "6.6",
  "wc_version": "9.1"
}
```

Success:

```json
{
  "success": true,
  "message": "Activated.",
  "plan": "premium"
}
```

Failure examples:

```json
{ "success": false, "message": "Invalid or revoked API code." }
{ "success": false, "message": "This API code has expired.", "plan": "premium" }
{ "success": false, "message": "This API code is already used on the allowed number of sites." }
```

If they send a bad key **and** a `site_url`, the server still writes a website row as `key_removed` so you know they tried.

### 4.2 Deactivate

`POST {SERVER}/wp-json/speedpress-license/v1/deactivate`

Same JSON body as activate (key + site_url + email).  
Server sets `status = deactivated`, `active = 0`. Row stays.

### 4.3 Report (key removed / plugin removed / anything else)

`POST {SERVER}/wp-json/speedpress-license/v1/report`

```json
{
  "key": "SPPA-PRO-4HXT-2N8C",
  "site_url": "https://client-store.com",
  "site_name": "Client Store",
  "admin_email": "owner@client-store.com",
  "plugin_version": "1.0.0",
  "status": "key_removed"
}
```

Allowed `status` values:

| status | When to send |
|---|---|
| `active` | Only via `/activate` |
| `deactivated` | User clicked Deactivate |
| `key_removed` | User cleared the code |
| `plugin_removed` | `register_deactivation_hook` |
| `revoked` | You revoked it in the console (server-side) |

Matching rule: same site URL (scheme/slash/case ignored) updates the existing row. The site is never removed.

---

## 5. Drop-in client for a new premium plugin

Copy this into `includes/class-license-client.php` and change the four constants at the top.

```php
<?php
/**
 * Minimal SpeedPress license client.
 * Point it at the same license server as Product Add-Ons.
 */
defined( 'ABSPATH' ) || exit;

class MyPlugin_License {

	const OPTION     = 'myplugin_license';           // unique per plugin
	const SERVER     = 'https://wpspeedpress.com';
	const CONST_URL  = 'MYPLUGIN_LICENSE_SERVER';    // optional wp-config override
	const CONST_DEV  = 'MYPLUGIN_LICENSE_BYPASS';    // optional local unlock
	const CAP        = 'manage_options';
	const CRON_HOOK  = 'myplugin_license_check';
	const PREFIX     = 'myplugin';

	public static function server() {
		if ( defined( self::CONST_URL ) && constant( self::CONST_URL ) ) {
			return untrailingslashit( constant( self::CONST_URL ) );
		}
		return self::SERVER;
	}

	public static function data() {
		return wp_parse_args(
			get_option( self::OPTION, array() ),
			array(
				'key'      => '',
				'last_key' => '',
				'plan'     => '',
				'status'   => 'inactive',
				'message'  => '',
			)
		);
	}

	public static function is_active() {
		if ( defined( self::CONST_DEV ) && constant( self::CONST_DEV ) ) {
			return true;
		}
		$d = self::data();
		return 'active' === $d['status'] && ! empty( $d['key'] );
	}

	public static function is_premium() {
		return self::is_active() && 'premium' === self::data()['plan'];
	}

	public static function boot() {
		add_action( 'admin_init', array( __CLASS__, 'handle' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'cron' ) );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'weekly', self::CRON_HOOK );
		}
	}

	private static function payload( $key ) {
		return array(
			'key'            => $key,
			'site_url'       => home_url(),
			'site_name'      => get_bloginfo( 'name' ),
			'admin_email'    => get_option( 'admin_email' ),
			'plugin_version' => defined( 'MYPLUGIN_VERSION' ) ? MYPLUGIN_VERSION : '',
			'wp_version'     => get_bloginfo( 'version' ),
		);
	}

	private static function post( $route, $body ) {
		$res = wp_remote_post(
			trailingslashit( self::server() ) . 'wp-json/speedpress-license/v1/' . $route,
			array(
				'timeout' => 15,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		return is_array( $data ) ? $data : new WP_Error( 'bad', 'License server error' );
	}

	public static function activate( $key ) {
		$key = strtoupper( preg_replace( '/[^A-Z0-9\-]/i', '', $key ) );
		$out = self::post( 'activate', self::payload( $key ) );
		$ok  = ! is_wp_error( $out ) && ! empty( $out['success'] );
		update_option(
			self::OPTION,
			array(
				'key'      => $key,
				'last_key' => $key,
				'plan'     => $ok ? ( $out['plan'] ?? '' ) : '',
				'status'   => $ok ? 'active' : 'inactive',
				'message'  => is_wp_error( $out ) ? $out->get_error_message() : ( $out['message'] ?? '' ),
			)
		);
		return $ok;
	}

	public static function deactivate() {
		$d = self::data();
		$key = $d['key'] ?: $d['last_key'];
		self::post( 'deactivate', self::payload( $key ) );
		update_option( self::OPTION, array_merge( $d, array( 'status' => 'inactive', 'message' => 'Deactivated' ) ) );
	}

	public static function remove_key() {
		$d   = self::data();
		$key = $d['key'] ?: $d['last_key'];
		$body = self::payload( $key );
		$body['status'] = 'key_removed';
		self::post( 'report', $body );
		update_option( self::OPTION, array( 'key' => '', 'last_key' => $key, 'status' => 'inactive', 'plan' => $d['plan'], 'message' => 'Key removed' ) );
	}

	public static function report_removed() {
		$d   = self::data();
		$key = $d['key'] ?: $d['last_key'];
		$body = self::payload( $key );
		$body['status'] = 'plugin_removed';
		self::post( 'report', $body );
	}

	public static function cron() {
		$d = self::data();
		if ( ! empty( $d['key'] ) ) {
			self::activate( $d['key'] );
			return;
		}
		if ( ! empty( $d['last_key'] ) ) {
			$body = self::payload( $d['last_key'] );
			$body['status'] = 'key_removed';
			self::post( 'report', $body );
		}
	}

	public static function handle() {
		if ( empty( $_POST['myplugin_license_action'] ) || ! current_user_can( self::CAP ) ) {
			return;
		}
		check_admin_referer( 'myplugin_license' );
		$action = sanitize_key( wp_unslash( $_POST['myplugin_license_action'] ) );
		$key    = sanitize_text_field( wp_unslash( $_POST['myplugin_license_key'] ?? '' ) );
		if ( 'activate' === $action ) {
			self::activate( $key );
		} elseif ( 'deactivate' === $action ) {
			self::deactivate();
		} elseif ( 'remove' === $action ) {
			self::remove_key();
		}
	}
}
```

Boot it from the product plugin:

```php
add_action( 'plugins_loaded', array( 'MyPlugin_License', 'boot' ) );

register_deactivation_hook( __FILE__, function () {
	if ( class_exists( 'MyPlugin_License' ) ) {
		MyPlugin_License::report_removed();
	}
	wp_clear_scheduled_hook( 'myplugin_license_check' );
} );
```

Settings form (any admin page):

```php
<?php
$lic = MyPlugin_License::data();
?>
<form method="post">
	<?php wp_nonce_field( 'myplugin_license' ); ?>
	<p>Status: <?php echo esc_html( $lic['status'] . ' ' . $lic['plan'] ); ?></p>
	<input type="text" name="myplugin_license_key" value="<?php echo esc_attr( $lic['key'] ); ?>" />
	<button name="myplugin_license_action" value="activate">Activate</button>
	<button name="myplugin_license_action" value="deactivate">Deactivate</button>
	<button name="myplugin_license_action" value="remove">Remove API code</button>
</form>
```

Gate features:

```php
if ( ! MyPlugin_License::is_active() ) {
	return; // plugin locked
}

if ( MyPlugin_License::is_premium() ) {
	// premium-only modules
} else {
	// free plan limits
}
```

---

## 6. Free vs Premium — how to think about it

The license server only answers: **is this code valid, and which plan is it?**  
Your product plugin decides what that means.

Suggested split:

| | Free code | Premium code |
|---|---|---|
| Activate plugin | Yes | Yes |
| Max sites on the code | 1 | 3–10 |
| Expiry | Optional | Optional (subscription end) |
| Product Add-Ons | All current features, or cap field count | Everything |
| Next plugin | Lite modules | Full modules |

`/activate` returns `"plan": "free"` or `"plan": "premium"`. Store that and branch in PHP.

You can issue both plans from the same console. You do not need a second server.

---

## 7. wp-config flags

On a **customer** site:

```php
define( 'SPPA_LICENSE_SERVER', 'https://wpspeedpress.com' );
```

On **your laptop** while building a plugin (skips the lock):

```php
define( 'SPPA_LICENSE_BYPASS', true );
```

For a new plugin, use that plugin’s constants from the drop-in (`MYPLUGIN_LICENSE_SERVER`, `MYPLUGIN_LICENSE_BYPASS`).

Never ship a plugin with `LICENSE_BYPASS` enabled.

---

## 8. Operations

**New sale**  
Create Premium code → paste into the invoice → customer activates → they appear under Active.

**Trial / free download**  
Create Free code (max sites = 1). When they pay, create a Premium code and have them swap it. The website row updates in place because the URL matches.

**They delete the key**  
Row stays, status `key_removed`, email still there. Filter that list and mail them.

**They uninstall the plugin**  
`plugin_removed`. Same follow-up list.

**Chargeback**  
Revoke the code. Next weekly check on their site fails and the plugin locks.

**Expired Premium**  
Set an expiry when you create the code. `/activate` and the weekly cron then return “expired”.

**Move the license server**  
Install the same plugin on the new host, export/import `spls_keys` and `spls_sites` options (or keep using the same database). Point product plugins at the new URL with the server constant.

Options used on the license server:

- `spls_keys` — all API codes  
- `spls_sites` — all websites  

---

## 9. Security notes (read this)

- The REST routes are public on purpose. The secret is the API code, not a WordPress cookie.  
- Treat codes like passwords. Do not print them in public changelogs.  
- HTTPS only (`https://wpspeedpress.com`).  
- You currently store site URL + admin email. Put that in your privacy policy.  
- This is not unbreakable DRM. A determined user can patch `is_active()`. The point is a clean commercial workflow and a list of real websites, not a war with crackers.  
- Rate-limit at the host (nginx / Cloudflare) if you start seeing scans on `/wp-json/speedpress-license/`.  
- Do not put the license server on a customer site.

---

## 10. Checklist for the next SpeedPress premium plugin

1. License server already running on wpspeedpress.com.  
2. Copy the drop-in client, change option name + constants + cron hook so two plugins on one site do not clash.  
3. Settings screen with Activate / Deactivate / Remove.  
4. `is_active()` around paid features.  
5. `is_premium()` around premium-only features.  
6. `register_deactivation_hook` → `report_removed()`.  
7. Weekly cron already in the drop-in.  
8. Create a Free and a Premium test code. Activate both on staging.  
9. Remove the key on staging and confirm the website still shows as `key_removed` in **SP Licenses**.  
10. Document the API code field in that plugin’s own readme.

One console. Any number of product plugins. Same three endpoints.
