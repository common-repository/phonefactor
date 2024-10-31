<?php
/*
Plugin Name: PhoneFactor Authentication
Description: <a href="http://phonefactor.net">PhoneFactor</a> is a phone-based two-factor system that eliminates the need for expensive tokens and other devices. Users instantly receive a call when logging in and press # to authenticate. It works with any phone. 
Version: 1.1
Author: Crowd Favorite
Author URI: http://crowdfavorite.com
*/

include(dirname(__FILE__) . '/phonefactor-register.php');

// PHP4 sys_get_temp_dir, taken from http://us.php.net/manual/en/function.sys-get-temp-dir.php#71332
if (!function_exists('sys_get_temp_dir')) {
	function sys_get_temp_dir() {
		if (!empty($_ENV['TMP'])) {
			return realpath( $_ENV['TMP'] );
		}
		else if (!empty($_ENV['TMPDIR'])) {
			return realpath($_ENV['TMPDIR']);
		}
		else if (!empty($_ENV['TEMP'])) {
			return realpath($_ENV['TEMP']);
		}
		else {
			$temp_file = tempnam(md5(uniqid(rand(), TRUE)), '');
			if ($temp_file) {
				$temp_dir = realpath(dirname($temp_file));
				unlink($temp_file);
				return $temp_dir;
			}
			else {
				return FALSE;
			}
		}
	}
}

if (function_exists('wp_enqueue_script')) {
	wp_enqueue_script('jquery');	
}

function pf_request_handler() {
	global $wp_version;
	if (!empty($_GET['pf_action'])) {
		switch($_GET['pf_action']) {
			// Version speific admin CSS
			case 'admin_css':
				header("Content-type: text/css");

				// WordPress 2.5 and above
				if (version_compare($wp_version, '2.5', '>=')) {
?>
#phonefactor-options .options p {
	margin-top:0;
	margin-bottom:0;
}
#phone-factor-phone { display:none; }
#phonefactor-options .options,
#your-profile #phonefactor-info {
	width:94%;
	border:0;
	background:#EAF3FA;
	clear:both;
	display:block;
	padding:20px;
	float:none;
}
table.pf_editform {
	width:100%;
}
input.test_button { margin-top: 10px; }
<?php
				// WordPress 2.4 and below
				} else {
?>
#phone-factor-phone { float:right; }
#your-profile fieldset input.test_button {
	border-color:#CCCCCC rgb(153, 153, 153) rgb(153, 153, 153) rgb(204, 204, 204);
	border-style:double;
	border-width:3px;
	color:#333333;
	padding:0.25em;
	font-size:13px;
	width:90px;
	float:right;
}
<?php
				}
				// All versions of WordPress
?>
#pf_whitelist_ips {
	width:98%;
}
<?php
			die;
			// Test telephone number javascript
			case 'admin_js':
			header("Content-type: text/javascript");
?>
pf = {}
pf.testNumber = function(numField,codeField) {	
	aNumber = numField.val(numField.val().replace(/(\W|[a-zA-Z])/g,'')).val();
	aCountryCode = codeField.val();
	var response_div = jQuery('#pf_ajax_response');
	if (response_div.size()) {
		jQuery.get(
			'<?php echo get_bloginfo('wpurl').'/index.php'; ?>',
			{
				pf_action: 'pf_test_number', 
				pf_test_number: aNumber,
				pf_test_country_code: aCountryCode
			},
			pf.testNumberCompleted
		);
		response_div.html('<p>Testing your number. Your phone should ring. Press "#" to complete the test.</p>');
	}
}

pf.testNumberCompleted = function(data) {
	var response_div = jQuery('#pf_ajax_response');
	if (response_div.size()) {
		response_div.html(data);
	}
}
<?php
			die;
			// Test telephone number server side code
			case 'pf_test_number':
				$number = (isset($_GET['pf_test_number']) ? $_GET['pf_test_number'] : '');
				$country_code = (isset($_GET['pf_test_country_code']) ? $_GET['pf_test_country_code'] : '');
				if (!empty($number) && !empty($country_code)) {
					if (is_user_logged_in() && current_user_can('edit_posts')) {
						global $userdata;

						get_currentuserinfo();
						$result = pf_authenticate($userdata, $number,$country_code,pf_allow_xnational());
						if ($result[0]) {
							echo '<p>Success!</p>';
						}
						else {
							echo '<p>PhoneFactor authentication response: '.pf_get_error_string($result[1]).'.</p>';
						}
					}
					else {
						echo '<p>You are not logged in.</p>';						
					}
				}
				else {
					if(empty($number)) {
						echo '<p>No number was provided.';
					}
					if(empty($country_code)) { 
						echo '<br />No country code was provided.</p>';
					}
					echo '</p>';
				}
			die;
			// Check is username is available
			case 'pf_username_available':
				$username = (isset($_GET['pf_username']) ? $_GET['pf_username'] : '');

				if (!empty($username)) {
					if (is_user_logged_in() && current_user_can('manage_options')) {

						$post = sprintf('username=%s', urlencode($username));

						// Make request and get response
						$curl_options = array(
							CURLOPT_URL => 'https://pfweb.phonefactor.net/register/check_username',
							CURLOPT_PORT => '443',
							CURLOPT_POST => true,
							CURLOPT_POSTFIELDS => $post,
							CURLOPT_RETURNTRANSFER => TRUE,
						);
						$curl = curl_init();
						foreach ($curl_options as $option => $value) curl_setopt($curl, $option, $value);
						$result = curl_exec($curl);
						$response_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
						
						// Return JSON response
						if($response_code == 200) {
							if(strlen(trim($result)) > 0) {
								echo '{success: true, available: false}';
							} else {
								echo '{success: true, available: true}';
							}
						} else {
							echo '{success: false, response_code: '.$response_code.'}';
						}
						curl_close($curl);
					}
				}
			die;
		}
	}
	if (!empty($_POST['pf_action'])) {
		switch($_POST['pf_action']) {
			case 'pf_update_settings':
				$value = ((isset($_POST['pf_allow_xnational']) && $_POST['pf_allow_xnational'] == 'on') ? 'yes' : 'no');
				update_option('pf_allow_xnational', $value);
				
				$value = ((isset($_POST['pf_disable_auth']) && $_POST['pf_disable_auth'] == 'on') ? 'yes' : 'no');				
				update_option('pf_disable_auth', $value);

				if (isset($_POST['pf_whitelist_ips'])) {
					update_option('pf_whitelist_ips', stripslashes($_POST['pf_whitelist_ips']));
				}
				header('Location: '.get_bloginfo('wpurl').'/wp-admin/options-general.php?page=phonefactor.php&updated=true');
			break;
		}
	}
}
add_action('init', 'pf_request_handler', 10);

// Display PhoneFactor setup phone number alert
function pf_notice_phone_number() {
	if (!pf_authentication_disabled()) {
		global $user_ID;

		$data = get_usermeta($user_ID, 'pf_user_data');
		$odata = get_option('pf_data');

		// If PhoneFactor registered and user hasn't setup phone number
		if ((!isset($data) || !isset($data['pf_user_phone']) || empty($data['pf_user_phone'])) && isset($odata) && isset($odata['pf_pkey']) && !empty($odata['pf_pkey']) && function_exists('curl_init') && current_user_can('edit_posts')) {
			print('
				<div class="updated fade-ff0000">
					<p><strong>PhoneFactor authentication is enabled and you have not set a phone number. Please edit your
					<a href="'.get_bloginfo('wpurl').'/wp-admin/profile.php#phonefactor-info">profile</a> to get started.</strong></p>
				</div>
			');
		}
	}
}
add_action('admin_notices', 'pf_notice_phone_number');

// Display PhoneFactor registration alert
function pf_notice_register() {
	if (!pf_authentication_disabled()) {
		$data = get_option('pf_data');

		// If PhoneFactor is not registered and user is administator
		if ((!isset($data) || !isset($data['pf_pkey']) || empty($data['pf_pkey'])) && function_exists('curl_init') && current_user_can('manage_options')) {
			print('
				<div class="updated fade-ff0000">
					<p><strong>PhoneFactor authentication is enabled and you have not registered this blog. Please
					<a href="'.get_bloginfo('wpurl').'/wp-admin/admin.php?page=phonefactor-register.php&pf_action=render_register">register</a> to get started.</strong></p>
				</div>
			');
		}
	}
}
add_action('admin_notices', 'pf_notice_register');

// Display PhoneFactor curl alert
function pf_notice_curl() {
	if (!pf_authentication_disabled()) {
		// If curl doesn't exists and that https is enabled
		if (!function_exists('curl_init') && current_user_can('manage_options')) {
			print('
				<div class="updated fade-ff0000 curl_notice">
					<p><a href="http://www.php.net/curl" target="_blank">CURL</a> must be installed for PhoneFactor to function properly, please <a href="http://curl.haxx.se/libcurl/php/install.html" target="_blank">install CURL</a> to continue.</p>
				</div>
			');
		}		
		elseif (function_exists('curl_init') && current_user_can('manage_options')) {
			// check that curl has https support
			if(!pf_curl_has_https_support()) { 
				print('
					<div class="updated fade-ff0000 curl_notice">
						<p><a href="http://www.php.net/curl" target="_blank">CURL</a> must be configured with SSL for PhoneFactor to function properly, please <a href="http://curl.haxx.se/libcurl/php/install.html" target="_blank">install CURL</a> with SSL Support to continue.</p>
					</div>
				');
			}
		}
	}
}
add_action('admin_notices', 'pf_notice_curl');

function pf_curl_has_https_support() {
	$v = curl_version();
	return in_array('https',$v['protocols']);
}

function pf_admin_head() {
	print('
		<link rel="stylesheet" type="text/css" href="'.get_bloginfo('wpurl').'/index.php?pf_action=admin_css" />
		<script type="text/javascript" src="'.get_bloginfo('wpurl').'/index.php?pf_action=admin_js"></script>
	');
}
add_action('admin_head', 'pf_admin_head');

function pf_register_form_fields() {
	if (!pf_authentication_disabled()) {
		if (isset($_POST['pf_user_phone'])) {
			$pf_user_phone = 'value="'.stripslashes($_POST['pf_user_phone']).'"';
		}
		if (isset($_POST['pf_country_code'])) {
			$pf_country_code = 'value="'.stripslashes($_POST['pf_country_code']).'"';
		}
		else {
			$pf_country_code = 'value="1"';
		}
		print('
		<p>
			Phone Number for <a href="http://phonefactor.com">PhoneFactor</a> Authentication:<br/>
			<span style="font-size:smaller">Your phone will be called. Answer the call and press "#" to complete registration.</span><br />');
			echo '<span style="margin: 10px 0px;"><label for="pf_country_code"><span style="font-size:smaller;">Country code (\'1\' for USA): </span></label><input type="text" name="pf_country_code" id="pf_country_code" size="3" class="input" '.$pf_country_code.' tabindex="25" /></span>';
			print('
			<span style="margin: 10px 0px; display: block;"><label for="pf_user_phone"><span style="font-size:smaller;">Phone number: </span></label><input type="text" name="pf_user_phone" id="pf_user_phone" size="16" class="input" '.$pf_user_phone.' tabindex="26"/></span>
			</label>
		</p>
		');
	}
}
add_action('register_form', 'pf_register_form_fields');

function pf_login_form_fields() {
	global $wp_version;

	if (!pf_authentication_disabled() && !pf_address_whitelisted()) {
		if (version_compare($wp_version, '2.5', '>=')) {
			$style_phonefactor = 'border-spacing:10px; background:#fff; color:#222; border:1px solid #328AB2; display:block;';
			$style_phonefactor_clear = 'clear:both;';
		} else {
			$style_phonefactor = 'border-spacing:10px; background:#fff; color:#222; border:2px solid #777;';
			$style_phonefactor_clear = 'display:none;';
		}
		print('
			<table style="'.$style_phonefactor .'">
				<tr>
					<td>
						<img src="'.get_bloginfo('wpurl').'/wp-content/plugins/phonefactor/images/PF_PFlogo_lock_small.png"/>
					</td>
					<td>
						<a href="http://phonefactor.net">PhoneFactor</a> authentication is active. The phone number associated with your account will be called. Answer the call and press "#" to authenticate.
					</td>
				</tr>
			</table>
			<div style="'.$style_phonefactor_clear .'">&nbsp;</div>
		');
	}
}
add_action('login_form', 'pf_login_form_fields');

// Display edit phone number form
function pf_edit_user_form_fields() {
	// Hide for level 0 users
	if(!current_user_can('edit_posts')) return;

	$data = get_option('pf_data');
	if ((!isset($data) || !isset($data['pf_pkey']) || empty($data['pf_pkey'])) && current_user_can('manage_options')) return;

	global $profileuser, $wp_version;
	$user_info = get_usermeta($profileuser->ID, 'pf_user_data');

	if (version_compare($wp_version, '2.5', '>=')) {
?>
	<h3 id="phonefactor-information">PhoneFactor Information</h3>
	<style type="text/css">
		#your-profile #pf_country_code { width: 75px; }
		#your-profile td#pf_short, #your-profile td#pf_long { vertical-align: top; }
		#your-profile td#pf_short { width: 1%; }
	</style>
	<table class="form-table">
	<tr>
	<th><label for="pf_user_phone">Phone Number:</label></th>
	<td id="pf_short"><input type="text" name="pf_country_code" id="pf_country_code" class="pf_country_code" value="<?php echo $user_info['pf_country_code'] ?>" size="4" /><br />Country Code</td> 
	<td id="pf_long"><input type="text" name="pf_user_phone" id="pf_user_phone" size="16" value="<?php echo $user_info['pf_user_phone']; ?>" /><!-- Enter the phone number you use for PhoneFactor authentication--><br />Area Code + Phone Number (ex: 3035551234)
		<div id="pf_ajax_response"></div>
	<?php	// curl https support check
			if(pf_curl_has_https_support()) {
	?>
				<script type="text/javascript">
					if (typeof(jQuery) !== 'undefined') {
						document.write('<input type="button" class="test_button" value="Test" onclick="pf.testNumber(jQuery(\'#pf_user_phone\'),jQuery(\'#pf_country_code\'))" /><div style="clear:both;"></div>')
					}   
				</script>
	<?php
			} // end curl https support check
	?>
	</td>
	</tr>
	</table>
<?php
	} else {
?>
	<style type="text/css">
	#your-profile fieldset input[type=checkbox] {
		width:inherit;
	}
	#your-profile #pf_country_code {
		width: 20%;
	}
	#your-profile #pf_user_phone {
		width: 75%;
	}
	</style>
	<fieldset id="phonefactor-info">
		<legend>PhoneFactor Information</legend>
		<p>
			<label for="pf_country_code">Country Code</label> &amp; <label for="pf_user_phone">Phone Number for PhoneFactor authentication<br/>
			<input id="pf_country_code" name="pf_country_code" value="<?php echo $user_info['pf_country_code']; ?>" type="text" />
			<input id="pf_user_phone" name="pf_user_phone" value="<?php echo $user_info['pf_user_phone']; ?>" type="text" /></label>
		</p>
<?php
		// curl https support check
		if(pf_curl_has_https_support()) {
?>
			<script type="text/javascript">
				if (typeof(jQuery) !== 'undefined') {
					document.write('<input type="button" class="test_button" value="Test" onclick="pf.testNumber(jQuery(\'#pf_user_phone\'),jQuery(\'#pf_country_code\'))" /><div style="clear:both;"></div>')
				}
			</script>
<?php
		} // end curl https support check
?>
		<div id="pf_ajax_response"></div>
	</fieldset>
<?php
	}
?>
<?php
}
add_action('edit_user_profile', 'pf_edit_user_form_fields');  // for admins
add_action('show_user_profile', 'pf_edit_user_form_fields');  // for users

function pf_profile_edited_by_user() {
	return pf_store_user_profile('user');
}
add_action('personal_options_update', 'pf_profile_edited_by_user');

function pf_profile_edited_by_admin() {
	return pf_store_user_profile('admin');
}
add_action('profile_update', 'pf_profile_edited_by_admin');

// Called after user profile is edited
function pf_store_user_profile($editor) {
	global $user_id;

	if ($editor == 'admin') {
		global $user_id;
	}
	else {
		global $user_ID;
		$user_id = $user_ID;
	}

	if (isset($_POST['pf_user_phone'])) {
		$user_info['pf_user_phone'] = preg_replace("/(\W|[a-zA-Z])/",'',stripslashes($_POST['pf_user_phone']));
	} else {
		$user_info['pf_user_phone'] = '';
	}
	
	if(isset($_POST['pf_country_code'])) {
		$user_info['pf_country_code'] = $_POST['pf_country_code'];
	} else {
		$user_info['pf_country_code'] = '';
	}

	return update_usermeta($user_id, 'pf_user_data', $user_info);
}

// Called after a new user is inserted, either by registration or by manual add
function pf_store_user_register_prefs($user_id) {
	$user_info = array();

	if (isset($_POST['pf_user_phone'])) {
		$user_info['pf_user_phone'] = $_POST['pf_user_phone'];
	}

	// will be escaped and serialized
	$success = update_usermeta($user_id, 'pf_user_data', $user_info);
}
add_action('user_register', 'pf_store_user_register_prefs');

// Check to see client is in whitelist
function pf_address_whitelisted() {
	$whitelist = pf_whitelist_ips();
	if(!is_array($whitelist)) return false;

	return in_array($_SERVER['REMOTE_ADDR'], $whitelist);
}

// Generic password reset
function pf_reset_password($user) {
	global $wpdb;

	// Random password
	$new_pass = substr(md5(uniqid(microtime())), 0, 7); 
	// Set password
	$wpdb->query("UPDATE $wpdb->users SET user_pass = MD5('$new_pass'), user_activation_key = '' WHERE user_login = '$user->user_login'");

	// Remove user from cache
	wp_cache_delete($user->ID, 'users');
	wp_cache_delete($user->user_login, 'userlogins');
}

function pf_user_fraud($user) {
	// Notify user
	mail($user->user_email, 'WordPress Fraud Detected', 
			'PhoneFactor has reset your password because we believe someone is trying to break into your account, to reset your password visit the following link: '.get_bloginfo('wpurl').'/wp-login.php?action=lostpassword');

	do_action('pf_user_fraud', $user);

	// Set random password
	pf_reset_password($user);
}

function pf_authenticate_login(&$user_login, &$user_pass) {
	if (!pf_authentication_disabled() && !empty($user_login) && !empty($user_pass) && !pf_address_whitelisted()) {
		if(user_pass_ok($user_login, $user_pass)) {
			$userdata = get_userdatabylogin($user_login);

			// Cancel check if user can't edit posts
			$wp_user = new WP_User($userdata->ID);
			if(!$wp_user->has_cap('edit_posts')) return;

			if (!empty($userdata)) {
				$usermeta = get_usermeta($userdata->ID, 'pf_user_data');
				if (isset($usermeta['pf_user_phone']) && !empty($usermeta['pf_user_phone'])) {
					$result = pf_authenticate($userdata, $usermeta['pf_user_phone'], $usermeta['pf_country_code'], pf_allow_xnational());
					if ($result[0]) {
						return;
					} elseif ($result[1] == '112') {
						// If fraud code delete reset password and e-mail user
						pf_user_fraud($userdata);
					}
					// Only reliable way to cancel login
					$user_pass = md5(uniqid(microtime()));
				}
			}
		}
	}
}
add_action('wp_authenticate', 'pf_authenticate_login', 10, 2);

function pf_menu_items() {
	if (current_user_can('manage_options')) {
		add_options_page(
			'PhoneFactor Authentication Options'
			, 'PhoneFactor'
			, 10
			, basename(__FILE__)
			, 'pf_options_form'
		);
	}
	// Hack to display generic page in the admin interface
	if (isset($_GET['pf_action']) && $_GET['pf_action'] == 'render_register') {
		add_menu_page(
			'PhoneFactor Registration'
			, 'PhoneFactor Registration'
			, 0
			, 'phonefactor-register.php'
			, 'pf_render_register'
		);
	}
}
add_action('admin_menu', 'pf_menu_items');

function pf_options_form() {
	if (isset($_GET['pf-updated']) && $_GET['pf-updated']) {
		print('
			<div id="message" class="updated fade">
				<p>PhoneFactor Options updated.</p>
			</div>
		');
	}
	
	$disable_auth_checked = (pf_authentication_disabled() ? 'checked="checked"' : '');
	$allow_xnational_checked = (pf_allow_xnational() ? 'checked="checked"' : '');
	$whitelist_ips = implode(', ', pf_whitelist_ips());
	
	print('
		<div id="phonefactor-options" class="wrap">
			<h2>PhoneFactor Options</h2>
			<form action="'.get_bloginfo('wpurl').'/wp-admin/options-general.php" method="post">
				<a href="http://phonefactor.net">
					<img src="'.get_bloginfo('wpurl').'/wp-content/plugins/phonefactor/images/PF_PFlogo_lock.png" id="phone-factor-phone"/>
				</a>
				<fieldset id="phonefactor-options-general" class="options">
					<p style="margin-bottom: 1em;">
						<input type="checkbox" name="pf_disable_auth" id="pf_disable_auth" '.$disable_auth_checked.'/>
						<label for="pf_disable_auth"> Disable PhoneFactor Authentication</label>
					</p>
					<p>
						<input type="checkbox" name="pf_allow_xnational" id="pf_allow_xnational" '.$allow_xnational_checked.' />
						<label for="pf_allow_xnational"> Allow Calls that Carry a Fee</label>
					</p>
					<p>
						<a href="http://phonefactor.com">PhoneFactor</a> is free to zones in 30+ countries.  For calls to other countries, PhoneFactor passes along the cost of the authentication call.  To learn more about PhoneFactor Global Services, <a href="http://www.phonefactor.com/products/global-services/">click here</a> or give PhoneFactor a call at 1.877.NO.TOKEN (1.877.668.6536).</p>
					<input type="hidden" name="pf_action" value="pf_update_settings" />
				</fieldset>
				<h3>Advanced Options</h3>
				<fieldset class="options">
					<p>
						<label for="pf_whitelist_ips">Whitelist the follow IP addresses (comma separated)</label><br />
						<textarea name="pf_whitelist_ips" size="30" id="pf_whitelist_ips">'.$whitelist_ips.'</textarea>
					</p>
				</fieldset>
				<p class="submit">
					<input type="submit" name="submit" value="Update PhoneFactor Options" />
				</p>
			</form>
		</div>
	');
}

function pf_validate_registration_info($errors) {
	if (!pf_authentication_disabled()) {
		global $userdata, $errors;
		if (!isset($_POST['pf_user_phone']) || ($_POST['pf_user_phone'] == '')) {
			if (is_array($errors)){
				$errors['pf-empty-phone'] = __('<strong>ERROR</strong>: Please enter your phone number for PhoneFactor authentication.');
			}
			else {
				$errors->add('pf-empty-phone',__('<strong>ERROR</strong>: Please enter your phone number for PhoneFactor authentication.'));				
			}
		}
		else {
			get_currentuserinfo();
			$result = pf_authenticate($userdata, $_POST['pf_user_phone'], $_POST['pf_country_code'], pf_allow_xnational());
			if (!$result[0]) {
				if (is_array($errors)){
					$errors['pf-auth-failed'] = __('<strong>ERROR</strong>: '.pf_get_error_string($result[1]).' -- PhoneFactor authentication failed.');
				}
				else {
					$errors->add('pf-auth-failed',__('<strong>ERROR</strong>: '.pf_get_error_string($result[1]).' -- PhoneFactor authentication failed.'));					
				}
			}
		}
	}
	return $errors;
}
add_filter('registration_errors', 'pf_validate_registration_info');

function pf_authentication_disabled() {
	$value = get_option('pf_disable_auth');
	return (isset($value) && $value == 'yes');
}

function pf_allow_xnational() {
	$value = get_option('pf_allow_xnational');
	return (isset($value) && $value == 'yes');
}

function pf_whitelist_ips() {
	$value = get_option('pf_whitelist_ips');
	if (!isset($value) || empty($value)) return array();
	$value = str_replace(' ', '', $value);
	$value = split(',', $value);
	return $value;
}

// the return value from PfAuth::pf_authenticate is an array with three elements,
// the result of the authentication (boolean), the result of the phonecall
// itself, and the result of the connection with the PhoneFactor backend,
// respectively. pf_get_error_string maps the second value in the array to an error string.
function pf_authenticate($userdata, $user_phone, $country_code = '1', $allow_xnational = false) {
	require('pf/pf_auth.php');
	// Remove non-numeric characters from the telephone number
	$user_phone = preg_replace('/[^\d]/', '', $user_phone);
	$country_code = preg_replace('/[^\d]/', '', $country_code);
	
	// Get certificate and private key from database
	$pf_data = get_option('pf_data');

	if(!isset($pf_data)
			|| !isset($pf_data['pf_pkey']) || empty($pf_data['pf_pkey'])
			|| !isset($pf_data['pf_cert']) || empty($pf_data['pf_cert']))
	{
		$result = array();
		$result[0] = true;
		return $result;
	}

	$cert = $pf_data['pf_cert'];
	$pkey = $pf_data['pf_pkey'];
	//$username = $pf_data['pf_username'];
	$username = $userdata->user_login;

	// Get temp directory
	$tmp_dir = sys_get_temp_dir();

	if(!$tmp_dir) die('Could not find temporary directory.');

	// Create temp certificate and private key files
	$pf_cert_filename = pf_create_temp_file($tmp_dir, $cert);
	$pf_pkey_filename = pf_create_temp_file($tmp_dir, $pkey);

	// Setup PhoneFactor authentication
	$sdk = new PfAuth();
	$sdk->cert_filename = $pf_cert_filename;
	$sdk->pkey_filename = $pf_pkey_filename;

	// Make request
	$result = $sdk->pf_authenticate(
		$pf_data['pf_license_key'],
		$pf_data['pf_group_key'],
		$username,
		$user_phone,		
		$country_code,		
		$allow_xnational,
		$_SERVER['SERVER_NAME'],
		$_SERVER['SERVER_ADDR']
	);

	// Clean up temp files
	pf_destroy_temp_file($pf_cert_filename);
	pf_destroy_temp_file($pf_pkey_filename);

	// Return results
	return $result;
}

// Create temp file
function pf_create_temp_file($base, $value) {
	$filename = tempnam($base, 'pf_');
	$handle = fopen($filename, 'w');
	fwrite($handle, $value);
	fclose($handle);
	return $filename;
}

// Delete temp file
function pf_destroy_temp_file($filename) {
	unlink($filename);
}

// Collection of error codes
function pf_get_error_string($result_code) {
	$strs = array(
		1 => 'Incorrect Phone Input',
		2 => 'No PIN Entered',
		3 => '# Not Pressed After Entry',
		4 => 'No Phone Input - Timed Out',
		5 => 'PIN Expired and Not Changed',
		6 => 'Used Cache',
		10 => 'Call Disconnected',
		11 => 'Call Timed Out',
		12 => 'Invalid Phone Input',
		13 => 'Got Voicemail',
		14 => 'User is Blocked',
		100 => 'Invalid Phone Number',
		101 => 'Phone Busy',
		102 => 'Configuration Issue',
		103 => 'International Calls Not Allowed',
		104 => 'PIN Mode Not Allowed',
		105 => 'Account Locked',
		106 => 'Invalid Message',
		107 => 'Invalid Phone Number Format',
		108 => 'User Hung Up the Phone',
		109 => 'Insufficient Balance',
		110 => 'Phone Extensions Not Allowed',
		111 => 'Invalid Extension',
		112 => 'Fraud Code Entered'
	);
	return $strs[(int)$result_code];
}

?>
