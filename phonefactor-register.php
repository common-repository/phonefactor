<?php

if (!function_exists('htmlspecialchars_decode')) {
	function htmlspecialchars_decode($str, $options='') {
		return strtr($str, array_flip(get_html_translation_table(HTML_ENTITIES, ENT_QUOTES)));
	}
}

function pf_reg_admin_head() {
?>
<script>

pf_reg_check_username_timeout = null;

function pf_reg_check_username(username) {
		clearTimeout(pf_reg_check_username_timeout);
		pf_reg_check_username_timeout = setTimeout("pf_reg_check_username_run('"+username+"');", 1000);
}

function pf_reg_check_username_run(username) {
	if(username.length > 0) {
		jQuery.get('<?php echo get_bloginfo('wpurl'); ?>/index.php?pf_action=pf_username_available', { pf_username: username }, function(response) {
			try {
				eval('result = '+response);
				if(result.available) {
					text = 'The username <strong style="color:green;">'+username+'</strong> is available.';
				} else {
					text = 'The username <strong style="color:red;">'+username+'</strong> is not available.';
				}
				jQuery('#username-status').html(text);
			} catch(err) {}
		});
	}
}

</script>

<style type="text/css">
	div.error { padding:6px 10px; }
<?php if (isset($_GET['pf_action']) && $_GET['pf_action'] == 'render_register'): ?>
	div.updated { display:none; }
	div.curl_notice { display: block; }
<?php endif; ?>
</style>

<?php if (version_compare($wp_version, '2.5', '<')) {
?>
<style type="text/css">
	table.pf_editform { width:100%; border-collapse:collapse; }
	table.pf_editform input { background:#fff; }
	table.pf_editform tr { background-color:#eee; border-bottom:solid 10px #fff; }
	table.pf_editform th { width:150px; text-align:left; }
	table.pf_editform th, table.pf_editform td { padding:10px; vertical-align:top; }
	input.pf_pass1 { margin-bottom: 5px; }
</style>
<?php
	}
}
add_action('admin_head', 'pf_reg_admin_head');

class PhoneFactorResponse {

	var $status;
	var $message;
	var $licenseKey;
	var $groupKey;
	var $cert;
	var $xml;

	function PhoneFactorResponse($xml) {
		$this->xml = $xml;
		$this->status = $this->getAttributeValue('response', 'status');
		$this->message = $this->getBlockValue('message');
		$this->licenseKey = $this->getBlockValue('licenseKey');
		$this->groupKey = $this->getBlockValue('groupKey');
		$this->cert = $this->getBlockValue('cert');
		$this->pkey = $this->getBlockValue('pkey');
	}

	function getBlockValue($name) {
		preg_match( '/\<'.$name.'\>(.*?)\<\/'.$name.'\>/s', $this->xml, $value );
		if (isset($value) && isset($value[0]))
			return $value[1];
		return '';
	}

	function getAttributeValue($name, $attribute) {
	    preg_match( '/\<'.$name.'.*'.$attribute.'=\'(.*?)\'.*\>.*\<\/'.$name.'\>/s', $this->xml, $value );
		if (isset($value) && isset($value[0]))
		    return $value[1];
		return '';
	}
}

function pf_reg_get_curl_error_string($error) {
	$errors = array(
		0 => 'OK',
		1 => 'UNSUPPORTED PROTOCOL',
		2 => 'FAILED INIT',
		3 => 'URL MALFORMAT',
		4 => 'URL MALFORMAT USER',
		5 => 'COULDNT RESOLVE PROXY',
		6 => 'COULDNT RESOLVE HOST',
		7 => 'COULDNT CONNECT',
		8 => 'FTP WEIRD SERVER REPLY',
	);
	return($errors[$error]);
}

function pf_render_register() {
	global $wp_version, $userdata;

	get_currentuserinfo();
	$usermeta = get_usermeta($userdata->ID, 'pf_user_data');
	$country_code = '1';

	$success = false;

	if (isset($_POST['phone'])) {
		$username = stripslashes($_POST['username']);
		$pass1 = stripslashes($_POST['pass1']);
		$pass2 = stripslashes($_POST['pass2']);
		$full_name = stripslashes($_POST['full_name']);
		$email = stripslashes($_POST['email']);
		$phone = preg_replace("/(\W|[a-zA-Z])/",'',stripslashes($_POST['phone']));
		$country_code = stripslashes(str_replace('+','',$_POST['country_code']));
		$password = $pass1;
		$agreetoterms = stripslashes($_POST['agreetoterms']);

		if (empty($username) || empty($pass1) || empty($pass2) || empty($full_name) || empty($phone) || empty($country_code)) {
			$error = 'All fields are required.';
		} elseif($pass1 != $pass2) {
			$error = 'Passwords do not match.';
		} 
		// take out the valid telephone number check b/c of int'l numbers -- cf 2009-02-05
		// elseif(!eregi('^([A-Z0-9._%+-]+)@([A-Z0-9.-]+)\.([A-Z]{2,4})$', $email)) {
		// 		 	$error = 'Please enter a valid telephone number.';
		//} 
		  elseif(!eregi('^([A-Z0-9._%+-]+)@([A-Z0-9.-]+)\.([A-Z]{2,4})$', $email)) {
			$error = 'Please enter a valid e-mail address.';
		} elseif (strlen($password) < 5) {
			$error = 'Password must be 5 characters or more.';
		} elseif ($country_code == '0') {
			$error = 'Country code cannot be 0';
		} elseif ('yes' != $agreetoterms) {
			$error = 'You must agree to the Terms &amp; Conditions';
		} else {
			$post_list = array(
				'full' => '1',
				'username' => $username,
				'password' => $password,
				'email' => $email,
				'customer_name' => $full_name,
				'country_code' => $country_code,
				'phone' => $phone,
				'oem' => 'wordpress',
				'Register' => 'true',
			);
			$post_string = '';
			foreach($post_list as $name => $value) {
				$post_string .= sprintf('%s=%s&', urlencode($name), urlencode($value));
			}
			$post_string = rtrim($post_string,'&');

			$curl_options = array(
				CURLOPT_URL => 'https://pfweb.phonefactor.net/register/one_step_register',
				CURLOPT_PORT => '443',
				CURLOPT_POST => true,
				CURLOPT_POST => count($post_list),
				CURLOPT_POSTFIELDS => $post_string,
				CURLOPT_RETURNTRANSFER => TRUE,
				CURLOPT_SSL_VERIFYPEER => false
			);

			$curl = curl_init();
			foreach ($curl_options as $option => $value)
				curl_setopt($curl, $option, $value);
			$result = curl_exec($curl);
			$response_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
			$error_num = curl_errno ($curl);
			curl_close($curl);

			if ($error_num != 0) {
					$error = 'There was an error connecting to the PhoneFactor servers.<br/>CURL ERROR: <code>'.$error_num.' - '.pf_reg_get_curl_error_string($error_num).'</code>';
			}
			elseif ($response_code == '200') {
				$response = new PhoneFactorResponse($result);

				if ($response->status == 'success') {
					$pf_data = get_option('pf_data');
					$pf_data['pf_license_key'] = $response->licenseKey;
					$pf_data['pf_group_key'] = $response->groupKey;
					$pf_data['pf_cert'] = $response->cert;
					$pf_data['pf_pkey'] = $response->pkey;
					$pf_data['pf_username'] = $username;

					$usermeta['pf_user_phone'] = $phone;
					$usermeta['pf_country_code'] = $country_code;

					update_option('pf_data', $pf_data);
					update_usermeta($userdata->ID, 'pf_user_data', $usermeta);

					$success = true;
				} else if ($response->status == 'error') {
					$error = $response->message;
				}
			} elseif ($response_code == '500') {
				$error = 'The PhoneFactor servers are currently unavailable, please try again later.';
			}
		}
	}
	if ($success) {
?>
<div class="wrap">
<h2>PhoneFactor Registration Successful</h2>
<p>You have successfully registered for PhoneFactor, please edit your <a href="<?php echo get_bloginfo('wpurl'); ?>/wp-admin/profile.php#phonefactor-info">profile</a> to get started.</p>
</div>
<?php
	} else {
		if (isset($error)) echo '<div class="error">'.htmlspecialchars_decode($error).'</div>';
		if (version_compare($wp_version, '2.5', '>=')) {
			$tableAttr = 'form-table';
		} else {
			$tableAttr = 'editform pf_editform';
		}
?>
<div class="wrap">
<form method="post" action="<?php echo get_bloginfo('wpurl').'/wp-admin/admin.php?page=phonefactor-register.php&pf_action=render_register'; ?>">
<h2>PhoneFactor Registration</h2>
<h3>Account</h3>
<table class="<?php echo $tableAttr; ?>">
	<tr>
		<th><label for="username">Username:</label></th>
		<td><input type="text" name="username" id="username" onkeyup="pf_reg_check_username(this.value);" onblur="pf_reg_check_username(this.value);" value="<?php echo htmlspecialchars($username); ?>"/><span id="username-status"></span></td>
	</tr>
	<tr>
		<th><label for="pass1">Password:</label></th>
		<td><input type="password" name="pass1" id="pass1" size="16" value="" class="pf_pass1" /><br />
		<input type="password" name="pass2" id="pass2" size="16" value="" /> Type your password again.<br />
	</tr>
</table>

<h3>Contact</h3>
<style type="text/css">
	#country_code { width: 75px; }
	td#pf_short, td#pf_long { vertical-align: top; }
	td#pf_short { width: 1%; }
</style>
<table class="<?php echo $tableAttr; ?>">
	<tr>
		<th><label for="full_name">Full name:</label></th>
		<td colspan="2"><input type="text" name="full_name" id="full_name" value="<?php echo htmlspecialchars($full_name); ?>"/></td>
	</tr>
	<tr>
		<th><label for="phone">Phone number:</label></th>
		<td id="pf_short"><input type="text" name="country_code" id="country_code" value="<?php echo htmlspecialchars($country_code); ?>" /><br />Country Code</td>
		<td id="pf_long"><input type="text" name="phone" id="phone" value="<?php echo htmlspecialchars($phone) ?>"/><br />Phone Number (ex: 3035551234)</td>
	</tr>
	<tr>
		<th><label for="email">E-mail:</label></th>
		<td colspan="2"><input type="text" name="email" id="email" value="<?php echo htmlspecialchars($email); ?>"/></td>
	</tr>
	<tr>
		<th><label for="agreetoterms">Terms &amp; Conditions:</label></th>
		<td colspan="2"><input type="checkbox" name="agreetoterms" id="agreetoterms" value="yes" /> I agree to the <b><a href="http://www.phonefactor.com/terms/wpserviceagreement.pdf" target="_terms">PhoneFactor Terms &amp; Conditions</a></b>.</td>
	</tr>
</table>
<p class="submit"><input type="submit" name="pf_submit" value="Register" /></p>
</form>
</div>
<?php
	}
}

?>
