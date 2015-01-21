<?php
/*
Plugin Name: Campaign Monitor Synchronization
Description: This plugin automatically creates and maintains a mailinglist on Campaign Monitor mirroring the list of WordPress users. 
Version: 1.0.13
Author: Carlo Roosen, Elena Mukhina
Author URI: http://www.carloroosen.com/
Plugin URI: http://www.carloroosen.com/campaign-monitor-synchronization/
*/

define( 'CMS_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'CMS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Global variables
global $cms_fields_to_hide;

register_activation_hook( __FILE__, 'cms_activation' );
register_deactivation_hook( __FILE__, 'cms_deactivation' );

add_action( 'admin_init', 'cms_settings' );
add_action( 'admin_menu', 'cms_settings_menu' );
add_action( 'cms_cron_update', 'cms_cron' );
add_action( 'init', 'cms_init' );
add_action( 'deleted_user', 'cms_user_delete' );
add_action( 'edit_user_profile', 'cms_add_custom_user_profile_fields' );
add_action( 'show_user_profile', 'cms_add_custom_user_profile_fields' );
add_action( 'edit_user_profile_update', 'cms_save_custom_user_profile_fields' );
add_action( 'personal_options_update', 'cms_save_custom_user_profile_fields' );
add_action( 'profile_update', 'cms_user_update', 10, 2 );
add_action( 'update_option_cms_settings', 'cms_save_and_sync' );
add_action( 'user_register', 'cms_user_insert' );
add_action( 'wp_ajax_cms-cm-sync', 'cms_cm_sync' );
add_action( 'wp_ajax_nopriv_cms-cm-sync', 'cms_cm_sync' );

add_filter( 'cron_schedules', 'cms_cron_add_quarter_hour' );
add_filter( 'update_user_metadata', 'cms_user_meta_update', 10, 5 );

require_once CMS_PLUGIN_PATH . 'classes/CMS_Synchronizer.php';

function cms_activation() {
	// Set default settings
	if ( ! get_option( 'cms_settings' ) ) {
		remove_action( 'update_option_cms_settings', 'cms_save_and_sync' );
		
		$cms_settings = array(
			'sync_timestamp' => 0,
			'user_fields' => array(),
			'user_role' => 0,
			'api_key' => '',
			'list_id' => ''
		);
		update_option( 'cms_settings', $cms_settings );
	}

	// WP Cron
	wp_schedule_event( time(), 'quarter_hour', 'cms_cron_update' );
}

function cms_deactivation() {
	// Remove WP Cron
	wp_clear_scheduled_hook( 'cms_cron_update' );

	// Remove the webhook if needed
	if ( ! class_exists( 'CS_REST_Lists' ) ) {
		require_once CMS_PLUGIN_PATH . 'campaignmonitor-createsend-php/csrest_lists.php';
	}

	$cms_settings = ( array ) get_option( 'cms_settings' );
	$cms_api_key = $cms_settings[ 'api_key' ];
	$cms_list_id = $cms_settings[ 'list_id' ];
	$auth = array( 'api_key' => $cms_api_key );
	$wrap_l = new CS_REST_Lists( $cms_list_id, $auth );

	$c = false;
	$result = $wrap_l->get_webhooks();
	foreach( $result->response as $hook ) {
		if ( $hook->Url == admin_url( 'admin-ajax.php?action=cms-cm-sync' ) ) {
			$c = $hook->WebhookID;
			break;
		}
	}

	if ( $c ) {
		$result = $wrap_l->delete_webhook( $c );
	}
}

function cms_settings() {
	register_setting( 'cms_settings_group', 'cms_settings', 'cms_settings_sanitize' );

	add_settings_section( 'general', __( 'General', 'cms_plugin' ), 'cms_settings_general', 'cms_plugin' );
	add_settings_field( 'user_fields', __( 'User fields', 'cms_plugin' ), 'cms_settings_user_fields', 'cms_plugin', 'general' );
	add_settings_field( 'user_role', __( 'User role', 'cms_plugin' ), 'cms_settings_user_role', 'cms_plugin', 'general' );

	add_settings_section( 'api', __( 'API', 'cms_plugin' ), 'cms_settings_api', 'cms_plugin' );
	add_settings_field( 'api_key', __( 'API key', 'cms_plugin' ), 'cms_settings_api_key', 'cms_plugin', 'api' );
	add_settings_field( 'list_id', __( 'List ID', 'cms_plugin' ), 'cms_settings_list_id', 'cms_plugin', 'api' );
}

function cms_settings_sanitize( $cms_settings ) {
	$cms_settings[ 'sync_timestamp' ] = current_time( 'timestamp' );
	return $cms_settings;
}

function cms_settings_general() {
}

function cms_settings_user_fields() {
	global $wpdb;
	global $cms_fields_to_hide;

	$cms_settings = ( array ) get_option( 'cms_settings' );
	$cms_user_fields = ( array ) $cms_settings[ 'user_fields' ];
	
	// Get user meta keys
	$querystr = "
		SELECT DISTINCT umeta.meta_key
		FROM $wpdb->users as u, $wpdb->usermeta as umeta
		WHERE u.id = umeta.user_id
		ORDER BY umeta.meta_key
	";
	$items = $wpdb->get_results( $querystr, OBJECT );
	
	foreach( $items as $item ) {
		if ( in_array( $item->meta_key, $cms_fields_to_hide ) ) {
			continue;
		}
		echo '<label><input type="checkbox" name="cms_settings[user_fields][]" value="' . esc_attr( $item->meta_key ) . '" ' . checked( in_array( $item->meta_key, $cms_user_fields ), true, false ) . ' /> ' . $item->meta_key . '</label><br />';
	}
}

function cms_settings_user_role() {
	$cms_settings = ( array ) get_option( 'cms_settings' );
	echo '<input type="hidden" name="cms_settings[user_role]" value="0" />';
	echo '<input type="checkbox" name="cms_settings[user_role]" value="1" ' . checked( $cms_settings[ 'user_role'], 1, false ) . ' />';
}

function cms_settings_api() {
}

function cms_settings_api_key() {
	$cms_settings = ( array ) get_option( 'cms_settings' );
	echo '<input type="text" name="cms_settings[api_key]" value="' . esc_attr( $cms_settings[ 'api_key'] ) . '" size="70" />';
}

function cms_settings_list_id() {
	$cms_settings = ( array ) get_option( 'cms_settings' );
	echo '<input type="text" name="cms_settings[list_id]" value="' . esc_attr( $cms_settings[ 'list_id'] ) . '" size="70" />';
}

function cms_settings_menu() {
	add_options_page( __( 'Campaign Monitor Synchronization Options', 'cms_plugin' ), __( 'CM Synchronization', 'cms_plugin' ), 'manage_options', 'cms_plugin', 'cms_settings_page' );
}

function cms_settings_page() {
	?>
	<div class="wrap">
		<h2><?php _e( 'Campaign Monitor Synchronization Options', 'cms_plugin' ); ?></h2>
		<p>
			<?php _e( 'This plugin makes a mirror of the Wordpress user list in Campaign Monitor.', 'cms_plugin' );?>
			<br />
			<?php _e( 'Items on the Campaign Monitor list will be removed when they do not match the userlist in Wordpress.', 'cms_plugin' );?>
			<!--<br />
			<?php _e( 'If this is not what you want, try the campagin-monitor-dual-registration plugin instead.', 'cms_plugin' );?>-->
		</p>
		<form action="options.php" method="POST">
			<?php settings_fields( 'cms_settings_group' ); ?>
			<?php do_settings_sections( 'cms_plugin' ); ?>
			<?php submit_button( __( 'save and sync', 'cms_plugin' ) ); ?>
		</form>
	</div>
	<?php
}

function cms_cron() {
	CMS_Synchronizer::cms_update();
}

function cms_init() {
	global $cms_fields_to_hide;
	
	$cms_fields_to_hide = array(
		'admin_color',
		'closedpostboxes_nav-menus',
		'comment_shortcuts',
		'dismissed_wp_pointers',
		'managenav-menuscolumnshidden',
		'metaboxhidden_nav-menus',
		'nav_menu_recently_edited',
		'rich_editing',
		'show_admin_bar_front',
		'show_welcome_panel',
		'use_ssl',
		'wp_capabilities',
		'wp_dashboard_quick_press_last_post_id',
		'wp_user-settings',
		'wp_user-settings-time',
		'wp_user_level',
		'session_tokens',
		'cms-subscribe-for-newsletter'
	);
	$cms_fields_to_hide = apply_filters( 'cms_edit_fileds_to_hide', $cms_fields_to_hide );
	
	// Backward compatibility
	$cms_settings = ( array ) get_option( 'cms_settings' );
	if ( get_option( 'cms_user_fields' ) ) {
		$cms_settings[ 'user_fields' ] = ( array ) unserialize( base64_decode( get_option( 'cms_user_fields' ) ) );
		update_option( 'cms_settings', $cms_settings );
		delete_option( 'cms_user_fields' );
	}
	if ( get_option( 'cms_api_key' ) ) {
		$cms_settings[ 'api_key' ] = get_option( 'cms_api_key' );
		update_option( 'cms_settings', $cms_settings );
		delete_option( 'cms_api_key' );
	}
	if ( get_option( 'cms_list_id' ) ) {
		$cms_settings[ 'list_id' ] = get_option( 'cms_list_id' );
		update_option( 'cms_settings', $cms_settings );
		delete_option( 'cms_list_id' );
	}
}

function cms_user_delete( $user_id ) {
	update_option( 'cms_update', 1 );
}

function cms_load_translation_file() {
	load_plugin_textdomain( 'campaignmonitor-sync', '', CMS_PLUGIN_PATH . 'translations' );
}

function cms_add_custom_user_profile_fields( $user ) {
?>
<h3><?php _e( 'Subscribe for newsletter' ); ?></h3>
<table class="form-table">
<tr>
<th>
<label for="cms-subscribe-for-newsletter"><?php _e( 'Subscribe for newsletter' ); ?>
</label></th>
<td>
<input type="hidden" name="cms-subscribe-for-newsletter" value="0" /><input type="checkbox" name="cms-subscribe-for-newsletter" id="cms-subscribe-for-newsletter" value="1"<?php echo( get_user_meta( $user->ID, 'cms-subscribe-for-newsletter', true ) !== "0" ? ' checked="checked"' : '' ); ?> /><br />
</td>
</tr>
</table>
<?php
}

function cms_save_custom_user_profile_fields( $user_id ) {
	global $wpdb;

	if ( ! current_user_can( 'edit_user', $user_id ) ) {
		return false;
	}

	update_user_meta( $user_id, 'cms-subscribe-for-newsletter', $_POST[ 'cms-subscribe-for-newsletter' ] );
}

function cms_user_update( $user_id, $old_user_data ) {
	update_option( 'cms_update', 1 );
}

function cms_save_and_sync( $cms_settings_old ) {
	$cms_settings = ( array ) get_option( 'cms_settings' );
	$cms_settings_old = ( array ) $cms_settings_old;

	if ( $cms_settings[ 'sync_timestamp' ] > $cms_settings_old[ 'sync_timestamp' ] ) {
		// Handle the webhook
		if ( ! class_exists( 'CS_REST_Lists' ) ) {
			require_once CMS_PLUGIN_PATH . 'campaignmonitor-createsend-php/csrest_lists.php';
		}

		$cms_api_key = $cms_settings[ 'api_key' ];
		$cms_list_id = $cms_settings[ 'list_id' ];
		$auth = array( 'api_key' => $cms_api_key );
		$wrap_l = new CS_REST_Lists( $cms_list_id, $auth );

		$c = true;
		$result = $wrap_l->get_webhooks();
		if ( ! $result->was_successful() ) {
			add_settings_error( 'cms_settings', 'cms-error', __( $result->response->Message, 'cms_plugin' ) );
		}

		foreach( $result->response as $hook ) {
			if ( $hook->Url == admin_url( 'admin-ajax.php?action=cms-cm-sync' ) ) {
				$c = false;
				break;
			}
		}

		if ( $c ) {
			$result = $wrap_l->create_webhook( array(
				'Events' => array( CS_REST_LIST_WEBHOOK_SUBSCRIBE, CS_REST_LIST_WEBHOOK_DEACTIVATE ),
				'Url' => admin_url( 'admin-ajax.php?action=cms-cm-sync' ),
				'PayloadFormat' => CS_REST_WEBHOOK_FORMAT_JSON
			) );
			if ( ! $result->was_successful() ) {
				add_settings_error( 'cms_settings', 'cms-error', __( $result->response->Message, 'cms_plugin' ) );
			}
		}

		// Make forced sync
		update_option( 'cms_update', 1 );
		$result = CMS_Synchronizer::cms_update();
		if ( ! $result ) {
			add_settings_error( 'cms_settings', 'cms-error', __( CMS_Synchronizer::$error->Message, 'cms_plugin' ) . ( ! empty( CMS_Synchronizer::$error->ResultData ) ? '<br />' . __( 'Error details: ', 'cms_plugin' ) . json_encode( CMS_Synchronizer::$error->ResultData ) : '' ) );
		}
	}
}

function cms_user_insert( $user_id ) {
	update_option( 'cms_update', 1 );
}

function cms_cm_sync() {
	global $cms_fields_to_hide;

	// Get plugin settings
	$cms_settings = ( array ) get_option( 'cms_settings' );
	$cms_list_id = $cms_settings[ 'list_id' ];

	if ( ! class_exists( 'CS_REST_SERIALISATION_get_available' ) ) {
		require_once CMS_PLUGIN_PATH . 'campaignmonitor-createsend-php/class/serialisation.php';
	}
	if ( ! class_exists( 'CS_REST_Log' ) ) {
		require_once CMS_PLUGIN_PATH . 'campaignmonitor-createsend-php/class/log.php';
	}

	// Get a serialiser for the webhook data - We assume here that we're dealing with json
	$serialiser = CS_REST_SERIALISATION_get_available( new CS_REST_Log( CS_REST_LOG_NONE ) );

	// Read all the posted data from the input stream
	$raw_post = file_get_contents("php://input");

	// And deserialise the data
	$deserialised_data = $serialiser->deserialise( $raw_post );

	// List ID check
	$list_id = $deserialised_data->ListID;
	if ( trim( $list_id ) == trim( $cms_list_id ) ) {
		remove_action( 'profile_update', 'cms_user_update', 10 );
		remove_action( 'user_register', 'cms_user_insert' );
		remove_filter( 'update_user_metadata', 'cms_user_meta_update', 10 );
		
		foreach( $deserialised_data->Events as $subscriber ) {
			$user = get_user_by( 'email', $subscriber->EmailAddress );
			
			if ( $user ) {
				if ( $subscriber->Type == "Subscribe" ) {
					update_user_meta( $user->ID, 'cms-subscribe-for-newsletter', 1 );
				} else {
					update_user_meta( $user->ID, 'cms-subscribe-for-newsletter', 0 );
				}
			}
		}
	}
	
	echo 'ok';
	die();
}

function cms_cron_add_quarter_hour( $schedules ) {
	$schedules[ 'quarter_hour' ] = array(
		'interval' => 900,
		'display' => __( 'Quarter hour' )
	);
	
	return $schedules;
}

function cms_user_meta_update( $temp, $user_id, $meta_key, $meta_value ) {
	global $cms_fields_to_hide;
	
	$cms_settings = ( array ) get_option( 'cms_settings' );
	$cms_user_fields = ( array ) $cms_settings[ 'user_fields' ];
	
	// The same value, no needs to update
	if (  $meta_value === get_user_meta( $user_id, $meta_key, true ) )
		return;
	
	// Field should not be updated
	if ( ! in_array( $meta_key, $cms_user_fields ) || ( in_array( $meta_key, $cms_fields_to_hide ) && $meta_key != 'cms-subscribe-for-newsletter' ) )
		return;

	update_option( 'cms_update', 1 );
}
