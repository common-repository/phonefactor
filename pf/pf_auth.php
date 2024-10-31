<?php
/*
 * ---------------
 * 
 * Copyright (c) 2007 Positive Networks, Inc.
 * 
 * Permission is hereby granted, free of charge, to any person
 * obtaining  a copy of this software and associated documentation
 * files (the "Software"),  to deal in the Software without
 * restriction, including without limitation the  rights to use,
 * copy, modify, merge, publish, distribute, sublicense, and/or
 * sell copies of the Software, and to permit persons to whom the
 * Software is furnished to do so, subject to the following
 * conditions:
 * 
 * The above copyright notice and this permission notice shall be
 * included in all copies or substantial portions of the Software.
 * 
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND,
 * EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES
 * OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND
 * NONINFRINGEMENT. IN NO EVENT  SHALL THE AUTHORS OR COPYRIGHT
 * HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY,
 * WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE,  ARISING
 * FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR
 * OTHER  DEALINGS IN THE SOFTWARE.
 * 
 * ---------------
*/

/* 
 * pf_auth.php: An SDK for authenticating with PhoneFactor.
 * version: 1.2
 */

$pf_sdk_elementNames = array();
$pf_sdk_elements = array();

class PfAuth {

	var $cert_filename = '';
	var $pkey_filename = '';

	// 
	// pf_authenticate: authenticates using PhoneFactor.
	// 
	// Arguments:
	//     1) $username: the username to be auth'd
	//     2) $phone: the phone number to PhoneFactor authenticate
	//     3) $country_code: the country code to use for the call.  defaults to 1.
	//     4) allow_int_calls: a boolean value that determines whether international
	//        calls should be allowed.  defaults to false.  note that this only needs to 
	//        be set to true if the call you are making is international, and thus could
	//        cost you money.  see www.phonefactor.net for the PhoneFactor rate table
	//        that shows which calling zones will cost money and which are free.
	//     5) $hostname: the hostname this authentication is being sent from.
	//                   defaults to 'pfsdk-hostname'
	//     6) $ip: the ip address this authentication is being sent from.
	//             defaults to '255.255.255.255'
	// 
	// Return value:
	//     An array containing 3 elements:  a boolean value representing whether the auth
	//     was successful or not, a string representing the status of the phonecall, and 
	//     a string containing an error id if the connection to the PhoneFactor backend
	//     failed.  If the authentication element is a true value, then the other two 
	//     elements can safely be ignored.
	// 
	function pf_authenticate ($license_key, $group_key, $username, $phone, $country_code = '1', $allow_int_calls = false,
								$hostname = 'pfsdk-hostname', $ip = '255.255.255.255')
	{
		$message = $this->create_authenticate_message(
			$username, 
			$phone, 
			$country_code, 
			$allow_int_calls, 
			$hostname, 
			$ip,
			$license_key,
			$group_key);
		
		$response = $this->send_message($message);

		return $this->get_response_status($response);
	}

	// 
	// create_authenticate_message: generates an authenticate message to be sent
	// 	to the PhoneFactor backend.
	//  
	// Arguments:
	//     1) $username: the username to be auth'd
	//     2) $phone: the phone number to PhoneFactor authenticate
	//     3) $country_code: the country code to use for the call.  defaults to 1.
	//     4) $allow_int_calls: boolean value that determines whether international 
	//        calls should be allowed. 
	//     5) $hostname: the hostname this authentication is being sent from
	//     6) $ip: the ip address this authentication is being sent from
	// 
	// Return value:
	//     a complete authentication xml message ready to be sent to the PhoneFactor backend
	// 
	function create_authenticate_message ($username, $phone, $country_code, 
														$allow_int_calls, $hostname, $ip, $license_key, $group_key)
	{
		$xml = "
			<pfpMessage>
				<header>
					<source>
						<component type='pfsdk'>
							<host ip='$ip' hostname='$hostname'/>
						</component>
					</source>
				</header>

				<request request-id='" . rand(0, 10000) . "'>
					<authenticationRequest>
						<customer>
							<licenseKey>
								$license_key
							</licenseKey>
							<groupKey>
								$group_key
							</groupKey>
						</customer>

						<countryCode>
							$country_code
						</countryCode>
						<authenticationType>
							pfsdk
						</authenticationType>
						<username>
							$username
						</username>
						<phonenumber userCanChangePhone='no'>
							$phone
						</phonenumber>
						<userCanChangePin>no</userCanChangePin>
						<allowInternationalCalls>
							" . ($allow_int_calls ? 'yes' : 'no') . "
						</allowInternationalCalls>
						<pinInfo pinMode='standard'/>
					</authenticationRequest>
				</request>
			</pfpMessage>
		";

		return $xml;
	}

	// 
	// send_message: sends a message to the PhoneFactor backend
	// 
	// Arguments:
	//     1) $message: the message to be sent
	// 
	// Return value:
	//     The response text from the PhoneFactor backend.  This will
	//     likely be an XML message ready to be parsed.  Note that the 
	//     return value could be NULL if the communication with the 
	//     backend was not possible.
	// 
	function send_message($message)
	{
		$curl = curl_init('https://pfd.phonefactor.net/pfd/pfd.pl');

		$curl_options = array(
			CURLOPT_PORT => '443',
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => $message,
			CURLOPT_RETURNTRANSFER => TRUE,
			CURLOPT_SSL_VERIFYHOST => FALSE,
			CURLOPT_SSL_VERIFYPEER => FALSE,
			CURLOPT_SSLCERT => $this->cert_filename,
			CURLOPT_SSLKEY => $this->pkey_filename,
		);

		foreach ($curl_options as $option => $value)
			curl_setopt($curl, $option, $value);

		$doc = curl_exec($curl);
		$error = curl_errno($curl);

		if ($error == 58) {
			// The user should never see this, I added a check in the plugin to accept the login if this happens
			print "Your PhoneFactor registration is invalid, you will need to manually remove 'pf_user_data' for each user from the usermeta table and 'pf_data' from the options table.";
		} elseif ($error) {
			print curl_error($curl);
		}

		curl_close($curl);

		return $doc;
	}

	// 
	// startElement: handler for the beginning of an XML element
	// 
	// Arguments:
	//     1) $parser: a reference to the XML parser
	//     2) $name: the name of the XML element being parsed
	//     3) $attrs: the attributes found in this element
	// 
	// Return value:
	//     none
	// 
	function startElement ($parser, $name, $attrs)
	{
		global $pf_sdk_elementNames, $pf_sdk_elements;

		$pf_sdk_elementNames[] = "$name";

		$pf_sdk_elements[$name]['attrs'] = array();

		foreach ($attrs as $key => $value)
		{
			$pf_sdk_elements[$name]['attrs'][$key] = $value;
		}
	}

	// 
	// endElement: handler for the end of an XML element
	// 
	// Arguments:
	//     1) $parser: a reference to the XML parser
	//     2) $name: the name of the XML element being parsed
	// 
	// Return value:
	//     none
	// 
	function endElement ($parser, $name)
	{
	}

	// 
	// characterData: handler for character data
	// 
	// Arguments:
	//     1) $parser: a reference to the XML parser
	//     2) $data: the character data between element tags
	// 
	// Return value:
	//     none
	// 
	function characterData ($parser, $data)
	{
		global $pf_sdk_elementNames, $pf_sdk_elements;

		$name = array_pop($pf_sdk_elementNames);

		$pf_sdk_elements[$name]['data'] = trim($data);
	}

	// 
	// get_response_status: parses the response from the PhoneFactor backend
	// 
	// Arguments:
	//     1) $response: the XML response string to be parsed
	// 
	// Return value:
	//     Same as the return value for pf_authenticate
	// 
	function get_response_status ($response)
	{
		global $pf_sdk_elements;

		if (!$response)
			return array(false, 0, 0);

		$disposition = false;
		$authenticated = false;
		$call_status = 0;
		$error_id = 0;
		$ret = false;

		$xml_parser = xml_parser_create();

		xml_set_element_handler($xml_parser, array($this, 'startElement'), array($this,'endElement'));
		xml_set_character_data_handler($xml_parser, array($this, 'characterData'));

		xml_parse($xml_parser, $response);
		xml_parser_free($xml_parser);

		if ($pf_sdk_elements['STATUS']['attrs']['DISPOSITION'] == 'success')
			$disposition = true;
		else
			$ret = false;

		if ($pf_sdk_elements['AUTHENTICATED']['data'] == 'yes')
		{
			$authenticated = true;
			$ret = true;
		}
		else
			$ret = false;

		$call_status = $pf_sdk_elements['CALLSTATUS']['data'];
		$error_id = $pf_sdk_elements['ERROR-ID']['data'];

		return array($ret, $call_status, $error_id);
	}
}
?>
