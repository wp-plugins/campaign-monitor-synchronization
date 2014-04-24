<?php
/*
Plugin Name: Campaign Monitor Synchronization
Description: This plugin automatically creates and maintains a mailinglist on Campaign Monitor mirroring the list of WordPress users. 
Version: 1.0.9
Author: Carlo Roosen, Elena Mukhina
Author URI: http://www.carloroosen.com/
Plugin URI: http://www.carloroosen.com/campaign-monitor-synchronization/
*/

define( 'CMS_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'CMS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Global variables
global $cms_fields_to_hide;

// Add restore defaults to Wordpress cron
register_activation_hook( __FILE__, 'cms_cron_activation' );
register_deactivation_hook( __FILE__, 'cms_cron_deactivation' );

add_action( 'admin_menu', 'cms_plugin_menu' );
add_action( 'cms_cron_update', 'cms_cron' );
add_action( 'init', 'cms_api_init' );
add_action( 'deleted_user', 'cms_user_delete' );
add_action( 'profile_update', 'cms_user_update', 10, 2 );
add_action( 'user_register', 'cms_user_insert' );

add_filter( 'cron_schedules', 'cms_cron_add_quarter_hour' );
add_filter( 'update_user_metadata', 'cms_user_meta_update', 10, 5 );

require_once CMS_PLUGIN_PATH . 'classes/CMS_Synchronizer.php';

function cms_cron_activation() {
	wp_schedule_event( time(), 'quarter_hour', 'cms_cron_update' );
}

function cms_cron_deactivation() {
	wp_clear_scheduled_hook( 'cms_cron_update' );
}

function cms_plugin_menu() {
	if ( basename( $_SERVER['SCRIPT_FILENAME'] ) == 'plugins.php' && isset( $_GET['page'] ) && $_GET['page'] == 'campaignmonitor-sync' ) {
		// Check permissions
		if ( !current_user_can( 'manage_options' ) )  {
			wp_die( __( 'You do not have sufficient permissions to access this page.', 'campaignmonitor-sync' ) );
		}
		
		if ( $_SERVER[ 'REQUEST_METHOD' ] == 'POST' ) {
			if ( isset( $_POST[ 'cms_user_fields' ] ) ) {
				update_option( 'cms_user_fields', base64_encode( serialize( ( array ) $_POST[ 'cms_user_fields' ] ) ) );
			} else {
				update_option( 'cms_user_fields', base64_encode( serialize( array() ) ) );
			}
			update_option( 'cms_api_key', $_POST[ 'cms_api_key' ] );
			update_option( 'cms_list_id', $_POST[ 'cms_list_id' ] );
			
			// Make forced sync
			update_option( 'cms_update', 1 );
			$result = CMS_Synchronizer::cms_update();

			if ( $result ) {
				wp_redirect( home_url( '/wp-admin/plugins.php?page=campaignmonitor-sync&saved=true' ) );
			} else {
				wp_redirect( home_url( '/wp-admin/plugins.php?page=campaignmonitor-sync&error=' . urlencode( CMS_Synchronizer::$error->Message . ( ! empty( CMS_Synchronizer::$error->ResultData ) ? '<br />Error details: ' . json_encode( CMS_Synchronizer::$error->ResultData ) : '' ) ) ) );
			}
		}
	}
	
	add_plugins_page( 'Campaign Monitor Synchronization Options', 'Campaign Monitor Synch', 'manage_options', 'campaignmonitor-sync', 'cms_plugin_page' );
}

function cms_plugin_page() {
	global $wpdb;
	global $cms_fields_to_hide;
	
	// Check permissions
	if ( !current_user_can( 'manage_options' ) )  {
		wp_die( __( 'You do not have sufficient permissions to access this page.', 'campaignmonitor-sync' ) );
	}

	// Get user meta keys
	$querystr = "
		SELECT DISTINCT umeta.meta_key
		FROM $wpdb->users as u, $wpdb->usermeta as umeta
		WHERE u.id = umeta.user_id
		ORDER BY umeta.meta_key
	";
	$items = $wpdb->get_results( $querystr, OBJECT );
	
	$cms_user_fields = ( array ) unserialize( base64_decode( get_option( 'cms_user_fields' ) ) );
	
	if ( isset( $_REQUEST['saved'] ) )
		echo '<div id="message" class="updated fade"><p><strong> ' . __( 'Settings saved.', 'campaignmonitor-sync' ) . '</strong></p></div>';
	if ( isset( $_REQUEST['error'] ) )
		echo '<div id="message" class="updated fade"><p><strong> ' . __( 'Campaign Monitor synchronization error.', 'campaignmonitor-sync' ) . '<br />' . __( urldecode( $_REQUEST['error'] ), 'campaignmonitor-sync' ) . '</strong></p></div>';
	?>
	<div class="wrap">
		<div id="icon-themes" class="icon32">
			<br>
		</div>
		<form method="post">
			<h2><?php _e( 'Campaign Monitor Synchronization Options', 'campaignmonitor-sync' ); ?></h2>
			<div class="inside">
				<table border="0">
					<tbody>
						<tr>
							<td colspan="2">
								<p>
									<?php _e( 'This plugin makes a mirror of the Wordpress user list in Campaign Monitor.', 'campaignmonitor-sync' );?>
									<br />
									<?php _e( 'Items on the Campaign Monitor list will be removed when they do not match the userlist in Wordpress.', 'campaignmonitor-sync' );?>
									<!--<br />
									<?php _e( 'If this is not what you want, try the campagin-monitor-dual-registration plugin instead.', 'campaignmonitor-sync' );?>-->
								</p>
							</td>
						</tr>
						<tr>
							<td colspan="2"><h3><?php _e( 'General', 'campaignmonitor-sync' );?></h3></td>
						</tr>
						<tr>
							<td style="vertical-align: top;"><?php _e( 'User fields', 'campaignmonitor-sync' ); ?>:</td>
							<td>
								<?php
								foreach( $items as $item ) {
									if ( in_array( $item->meta_key, $cms_fields_to_hide ) ) {
										continue;
									}
									?>
								<label><input type="checkbox" name="cms_user_fields[]" value="<?php echo esc_attr( $item->meta_key ); ?>"<?php echo ( in_array( $item->meta_key, $cms_user_fields ) ? ' checked="true"' : '' ); ?> /> <?php echo $item->meta_key; ?></label><br />
									<?php
								}
								?>
							</td>
						</tr>
						<tr>
							<td colspan="2"><h3><?php _e( 'API', 'campaignmonitor-sync' );?></h3></td>
						</tr>
						<tr>
							<td style="vertical-align: top;"><label for="cms_api_key"><?php _e( 'API key', 'campaignmonitor-sync' ); ?>:</label></td>
							<td><input type="text" name="cms_api_key" id="cms_api_key" value="<?php echo esc_attr( get_option( 'cms_api_key' ) ); ?>" size="70" /></td>
						</tr>
						<tr>
							<td style="vertical-align: top;"><label for="cms_list_id"><?php _e( 'List ID', 'campaignmonitor-sync' ); ?>:</label></td>
							<td><input type="text" name="cms_list_id" id="cms_list_id" value="<?php echo esc_attr( get_option( 'cms_list_id' ) ); ?>" size="70" /></td>
						</tr>
						<tr>
							<td></td>
							<td><input type="submit" value="save and sync" /></td>
						</tr>
					</tbody>
				</table>
			</div>
		</form>
	</div>
	<?php
}

function cms_cron() {
	CMS_Synchronizer::cms_update();
}

function cms_api_init() {
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
		'wp_user_level'
	);
	$cms_fields_to_hide = apply_filters( 'cms_edit_fileds_to_hide', $cms_fields_to_hide );
}

function cms_user_delete( $user_id ) {
	update_option( 'cms_update', 1 );
}

function cms_load_translation_file() {
	load_plugin_textdomain( 'campaignmonitor-sync', '', CMS_PLUGIN_PATH . 'translations' );
}

function cms_user_update( $user_id, $old_user_data ) {
	update_option( 'cms_update', 1 );
}

function cms_user_insert( $user_id ) {
	update_option( 'cms_update', 1 );
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
	
	$cms_user_fields = ( array ) unserialize( base64_decode( get_option( 'cms_user_fields' ) ) );
	
	// The same value, no needs to update
	if (  $meta_value == get_user_meta( $user_id, $meta_key, true ) )
		return;
	
	// Field should not be updated
	if ( ! in_array( $meta_key, $cms_user_fields ) || in_array( $meta_key, $cms_fields_to_hide ) )
		return;

	update_option( 'cms_update', 1 );
}
