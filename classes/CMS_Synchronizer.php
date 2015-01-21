<?php
class CMS_Synchronizer {
	public static $error;
	
	// The main sync function
	public static function cms_update() {
		global $cms_fields_to_hide;

		if ( get_option( 'cms_update' ) ) {
			$cms_settings = ( array ) get_option( 'cms_settings' );
			$cms_api_key = $cms_settings[ 'api_key' ];
			$cms_list_id = $cms_settings[ 'list_id' ];
			$cms_user_fields = ( array ) $cms_settings[ 'user_fields' ];
			
			if ( $cms_api_key && $cms_list_id ) {
				if ( ! class_exists( 'CS_REST_Lists' ) ) {
					require_once CMS_PLUGIN_PATH . 'campaignmonitor-createsend-php/csrest_lists.php';
				}
				if ( ! class_exists( 'CS_REST_Subscribers' ) ) {
					require_once CMS_PLUGIN_PATH . 'campaignmonitor-createsend-php/csrest_subscribers.php';
				}
				
				$auth = array( 'api_key' => $cms_api_key );
				$wrap_l = new CS_REST_Lists( $cms_list_id, $auth );
				$wrap_s = new CS_REST_Subscribers( $cms_list_id, $auth );
				
				// Update custom fields
				$missing_fields = $cms_user_fields;
				if ( ! empty( $cms_settings[ 'user_role' ] ) ) {
					$missing_fields[] = '_cms_wp_user_role';
				}

				$result = $wrap_l->get_custom_fields();
				if ( ! $result->was_successful() ) {
					self::$error = $result->response;
					return false;
				}
				if ( is_array( $result->response ) ) {
					foreach( $result->response as $key => $field ) {
						$k = str_replace( '[', '', str_replace( ']', '', $field->Key ) );
						
						if( ! in_array( $k, $missing_fields ) || in_array( $k, $cms_fields_to_hide ) ) {
							$result = $wrap_l->delete_custom_field( $field->Key );
							if ( ! $result->was_successful() ) {
								self::$error = $result->response;
								return false;
							}
						}
						
						if ( in_array( $k, $missing_fields ) ) {
							unset( $missing_fields[ array_search( $k, $missing_fields ) ] );
						}
					}
				}
				
				foreach( $missing_fields as $key => $field ) {
					if ( ! in_array( $field, $cms_fields_to_hide ) ) {
						$result = $wrap_l->create_custom_field( array(
							'FieldName' => $field,
							'Key' => $field,
							'DataType' => CS_REST_CUSTOM_FIELD_TYPE_TEXT
						) );
						if ( ! $result->was_successful() ) {
							self::$error = $result->response;
							return false;
						}
					}
				}

				// Update active users
				$missing_users = array();
				$u = get_users();
				foreach( $u as $key => $value ) {
					$missing_users[] = $value->user_email;
				}

				$result = $wrap_l->get_active_subscribers( '', 1, 1000 );
				if ( ! $result->was_successful() ) {
					self::$error = $result->response;
					return false;
				}
				
				$i = 2;
				while ( count( $result->response->Results ) ) {
					foreach( $result->response->Results as $key => $subscriber ) {
						set_time_limit ( 60 );
						
						$user = get_user_by( 'email', $subscriber->EmailAddress );

						if ( ! $user ) {
							// Subscriber delete
							$result_tmp = $wrap_s->delete( $subscriber->EmailAddress );
							if ( ! $result_tmp->was_successful() ) {
								self::$error = $result_tmp->response;
								return false;
							}
						} else {
							// Unsubscribe if needed
							if ( get_user_meta( $user->ID, 'cms-subscribe-for-newsletter', true ) === "0" ) {
								$result_tmp = $wrap_s->unsubscribe( $user->user_email );
								if ( ! $result_tmp->was_successful() ) {
									self::$error = $result_tmp->response;
									return false;
								}
							}

							$args = array();

							if ( trim( $user->first_name . ' ' . $user->last_name ) != trim( $subscriber->Name ) ) {
								$args[ 'Name' ] = $user->first_name . ' ' . $user->last_name;
							}
							
							$custom_values = array();
							foreach( $subscriber->CustomFields as $field ) {
								$k = str_replace( '[', '', str_replace( ']', '', $field->Key ) );
								
								$custom_values[ $k ] = $field->Value;
							}
							foreach( $cms_user_fields as $key => $field ) {
								if ( ! in_array( $field, $cms_fields_to_hide ) ) {
									if ( empty( $custom_values[ $field ] ) || trim( $custom_values[ $field ] ) != trim( get_user_meta( $user->ID, $field, true ) ) ) {
										// We export scalar values only
										if ( is_scalar( get_user_meta( $user->ID, $field, true ) ) ) {
											$args[ 'CustomFields' ][] = array( 'Key' => $field, 'Value' => get_user_meta( $user->ID, $field, true ) );
										} else {
											$args[ 'CustomFields' ][] = array( 'Key' => $field, 'Value' => '' );
										}
									}
								}
							}
							if ( ! empty( $cms_settings[ 'user_role' ] ) && ! empty( $user->roles[ 0 ] ) ) {
								if ( empty( $custom_values[ '_cms_wp_user_role' ] ) || trim( $custom_values[ '_cms_wp_user_role' ] ) != trim( $user->roles[ 0 ] ) ) {
									$args[ 'CustomFields' ][] = array( 'Key' => '_cms_wp_user_role', 'Value' => $user->roles[ 0 ] );
								}
							}
							
							if ( count( $args ) ) {
								$result_tmp = $wrap_s->update( $user->user_email, $args );
								if ( ! $result_tmp->was_successful() ) {
									self::$error = $result_tmp->response;
									return false;
								}
							}
						}
						
						if ( in_array( $subscriber->EmailAddress, $missing_users ) ) {
							unset( $missing_users[ array_search( $subscriber->EmailAddress, $missing_users ) ] );
						}
					}
					
					$result = $wrap_l->get_active_subscribers( '', $i, 1000 );
					if ( ! $result->was_successful() ) {
						self::$error = $result->response;
						return false;
					}
					$i ++;
				}

				// Update unsubscribed users
				$unsubscribed = array();
				$result = $wrap_l->get_unsubscribed_subscribers( '', 1, 1000 );
				if ( ! $result->was_successful() ) {
					self::$error = $result->response;
					return false;
				}
				
				$i = 2;
				while ( count( $result->response->Results ) ) {
					foreach( $result->response->Results as $key => $subscriber ) {
						set_time_limit ( 60 );
						
						$user = get_user_by( 'email', $subscriber->EmailAddress );

						if ( ! $user ) {
							// Subscriber resubscribe & delete (delete does not work for unsubscribed users)
							$args = array(
								'Resubscribe' => true
							);
							$result_tmp = $wrap_s->update( $subscriber->EmailAddress, $args );
							if ( ! $result_tmp->was_successful() ) {
								self::$error = $result_tmp->response;
								return false;
							}

							$result_tmp = $wrap_s->delete( $subscriber->EmailAddress );
							if ( ! $result_tmp->was_successful() ) {
								self::$error = $result_tmp->response;
								return false;
							}
						} else {
							$args = array();
							
							if ( trim( $user->first_name . ' ' . $user->last_name ) != trim( $subscriber->Name ) ) {
								$args[ 'Name' ] = $user->first_name . ' ' . $user->last_name;
							}
							
							$custom_values = array();
							foreach( $subscriber->CustomFields as $field ) {
								$k = str_replace( '[', '', str_replace( ']', '', $field->Key ) );
								
								$custom_values[ $k ] = $field->Value;
							}
							foreach( $cms_user_fields as $key => $field ) {
								if ( ! in_array( $field, $cms_fields_to_hide ) ) {
									if ( empty( $custom_values[ $field ] ) || trim( $custom_values[ $field ] ) != trim( get_user_meta( $user->ID, $field, true ) ) ) {
										// We export scalar values only
										if ( is_scalar( get_user_meta( $user->ID, $field, true ) ) ) {
											$args[ 'CustomFields' ][] = array( 'Key' => $field, 'Value' => get_user_meta( $user->ID, $field, true ) );
										} else {
											$args[ 'CustomFields' ][] = array( 'Key' => $field, 'Value' => '' );
										}
									}
								}
							}
							if ( ! empty( $cms_settings[ 'user_role' ] ) && ! empty( $user->roles[ 0 ] ) ) {
								if ( empty( $custom_values[ '_cms_wp_user_role' ] ) || trim( $custom_values[ '_cms_wp_user_role' ] ) != trim( $user->roles[ 0 ] ) ) {
									$args[ 'CustomFields' ][] = array( 'Key' => '_cms_wp_user_role', 'Value' => $user->roles[ 0 ] );
								}
							}
							
							// Resubscribe if needed
							if ( get_user_meta( $user->ID, 'cms-subscribe-for-newsletter', true ) !== "0" ) {
								$args[ 'Resubscribe' ] = true;
							}

							if ( count( $args ) ) {
								$result_tmp = $wrap_s->update( $user->user_email, $args );
								if ( ! $result_tmp->was_successful() ) {
									self::$error = $result_tmp->response;
									return false;
								}
							}
						}
						
						if ( in_array( $subscriber->EmailAddress, $missing_users ) ) {
							unset( $missing_users[ array_search( $subscriber->EmailAddress, $missing_users ) ] );
						}
					}
					
					$result = $wrap_l->get_unsubscribed_subscribers( '', $i, 1000 );
					if ( ! $result->was_successful() ) {
						self::$error = $result->response;
						return false;
					}
					$i ++;
				}

				// Get bounced users
				$bounced = array();
				$result = $wrap_l->get_bounced_subscribers( '', 1, 1000 );
				if ( ! $result->was_successful() ) {
					self::$error = $result->response;
					return false;
				}
				
				$i = 2;
				while ( count( $result->response->Results ) ) {
					foreach( $result->response->Results as $key => $subscriber ) {
						$bounced[] = $subscriber->EmailAddress;
					}

					$result = $wrap_l->get_bounced_subscribers( '', $i, 1000 );
					if ( ! $result->was_successful() ) {
						self::$error = $result->response;
						return false;
					}
					$i ++;
				}

				// Add missing subscribers
				$subscribers = array();
				$users_to_unsubscribe = array();
				foreach( $missing_users as $key => $user_email ) {
					if ( ! in_array( $user_email, $bounced ) ) {
						// Subscriber does not exist, let's add him
						$user = get_user_by( 'email', $user_email );

						if ( $user ) {
							$args = array(
								'EmailAddress' => $user->user_email,
								'Name' => $user->first_name . ' ' . $user->last_name,
								'CustomFields' => array(
								)
							);

							foreach( $cms_user_fields as $key => $field ) {
								if ( ! in_array( $field, $cms_fields_to_hide ) ) {
									// We export scalar values only
									if ( is_scalar( get_user_meta( $user->ID, $field, true ) ) ) {
										$args[ 'CustomFields' ][] = array( 'Key' => $field, 'Value' => get_user_meta( $user->ID, $field, true ) );
									} else {
										$args[ 'CustomFields' ][] = array( 'Key' => $field, 'Value' => '' );
									}
								}
							}
							if ( ! empty( $cms_settings[ 'user_role' ] ) && ! empty( $user->roles[ 0 ] ) ) {
								$args[ 'CustomFields' ][] = array( 'Key' => '_cms_wp_user_role', 'Value' => $user->roles[ 0 ] );
							}

							$subscribers[] = $args;
							if ( get_user_meta( $user->ID, 'cms-subscribe-for-newsletter', true ) === "0" ) {
								$users_to_unsubscribe[] = $user;
							}
						}
					}
				}

				while ( count( $subscribers ) ) {
					set_time_limit ( 60 );
					
					$subscribers1000 = array();
					for ( $i = 0; $i < 1000; $i++ ) {
						$subscribers1000[] = array_shift( $subscribers );
						if ( ! count( $subscribers ) ) {
							break;
						}
					}
					
					$result = $wrap_s->import( $subscribers1000, true );
					if ( ! $result->was_successful() ) {
						self::$error = $result->response;
						return false;
					}
				}

				// Unsubscribe users with "Subscribe for newsletter" unchecked
				foreach( $users_to_unsubscribe as $key => $value ) {
					$result = $wrap_s->unsubscribe( $value->user_email );
					if ( ! $result->was_successful() ) {
						self::$error = $result->response;
						return false;
					}
				}
			}

			delete_option( 'cms_update' );
		}
		
		return true;
	}
}
