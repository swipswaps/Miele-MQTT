<?php
################################################################################################################################################
######
######		Miele-MQTT.php
######		Script by Ole Kristian Lona, to read data from Miele@home, and transfer through MQTT.
######		Version 2.b01
######
################################################################################################################################################

################################################################################################################################################
######		Global variables
################################################################################################################################################

$code='';
$mosquitto_host='';
$mosquitto_user='';
$mosquitto_pass='';
$topicbase='';
$access_token='';
$config='';

################################################################################################################################################
######		getRESTData - Function used to retrieve REST data from server.
################################################################################################################################################

function getRESTData($url,$postdata,$method,$content,$authorization='')
{
	#print $authorization . PHP_EOL;
	#print $postdata . PHP_EOL;
	#print $method . PHP_EOL;
	#print $url . PHP_EOL;
	$ch = curl_init($url);                                                                      
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);                                                                     
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	$headers=array();
	if(strlen($authorization)>> 0 ) {
		array_push($headers, 'Authorization: ' . $authorization);
	}
	
	if(strlen($content) >> 0 ) {
		array_push($headers, 'Content-Type: ' . $content);
	}
	
	
	curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
	if (( strcmp($method,"POST" ) == 0 ) || ( strcmp($method,"PUT" ) == 0 )) {
		curl_setopt($ch,CURLOPT_POSTFIELDS, $postdata);
	}
	$result = curl_exec($ch);
	#print $result . PHP_EOL;
	if (curl_getinfo($ch,CURLINFO_RESPONSE_CODE) == 302 ) {
		$returndata=curl_getinfo($ch,CURLINFO_REDIRECT_URL);
	}
	elseif (curl_getinfo($ch,CURLINFO_RESPONSE_CODE) == 401 ) {
		$returndata=array("code"=>"Unauthorized");
	}
	else {
		$returndata=json_decode($result,true);
	}
	
 return $returndata;
}

################################################################################################################################################
######		prompt_silent - Function "borrowed" from https://www.sitepoint.com/interactive-cli-password-prompt-in-php/
######		Written by: Troels Knak-Nielsen
################################################################################################################################################
function prompt_silent($prompt = "Enter Password:") {
  if (preg_match('/^win/i', PHP_OS)) {
    $vbscript = sys_get_temp_dir() . 'prompt_password.vbs';
    file_put_contents(
      $vbscript, 'wscript.echo(InputBox("'
      . addslashes($prompt)
      . '", "", "password here"))');
    $command = "cscript //nologo " . escapeshellarg($vbscript);
    $password = rtrim(shell_exec($command));
    unlink($vbscript);
    return $password;
  } else {
    $command = "/usr/bin/env bash -c 'echo OK'";
    if (rtrim(shell_exec($command)) !== 'OK') {
      trigger_error("Can't invoke bash");
      return;
    }
    $command = "/usr/bin/env bash -c 'read -s -p \""
      . addslashes($prompt)
      . "\" mypassword && echo \$mypassword'";
    $password = rtrim(shell_exec($command));
    echo "\n";
    return $password;
  }
}




################################################################################################################################################
######		createconfig - Function to prompt for config data, and create config file.
################################################################################################################################################
function createconfig($refresh=false) {	
	$configcreated=false;
	$tokenscreated=false;
	global $folder;
	global $code;
	global $mosquitto_host;
	global $mosquitto_user;
	global $mosquitto_pass;
	global $topicbase;
	global $access_token;
	
	$content="application/x-www-form-urlencoded";

	if($refresh == false) {
		
		$userid=readline("Username (email) to connect with: ");
		$password=prompt_silent("Please type your password: ");
		$country=readline('Please state country in the form of "no-NO, en-EN, etc.": ');

		$client_id=readline('Please input the client ID assigned to you by Miele API administrators: ');
		$client_secret=readline('Please input the Client Secret assigned to you by Miele: ');
	
		$mosquitto_host=readline("Type the name of your mosquitto host (leave blank if localhost): ");
		$mosquitto_user=readline("Type login-name for Mosquitto (leave blank if nor using login): ");
		if (strlen($mosquitto_user) >> 0 ) {
			$mosquitto_pass=readline("Type the password for your mosquitto user (will be saved in PLAIN text): ");
		}
		else {
			$mosquitto_pass="";
		}
		$topicbase=readline('Type the base topic name to use for Mosquitto (default: "/miele/": ');
		if (strlen($topicbase) == 0) {
			$topicbase="/miele/";
		}
		if (substr($topicbase,-1) <> "/") {
			$topicbase = $topicbase . "/";
		}

		$authorization='';
		$url="https://api.mcs3.miele.com/oauth/auth";
		$postdata='email=' . urlencode($userid) . '&password=' . urlencode($password) . '&redirect_uri=www.google.com&state=login&response_type=code&client_id=' . $client_id . '&vgInformationSelector=' . $country;
	
		$method="POST";
	
		$data=getRESTData($url,$postdata,$method,$content,'');
	
		if (is_array($data) == FALSE){
			$params=(explode('?',$data))[1];
			foreach (explode('&', $params) as $part) {
				$param=explode("=",$part);
				
				if(strstr($param[0],'code') <> FALSE ) {
					$code=$param[1];
				}
			}
		}
		else {
			return $configcreated;
		}
	
	}
	else {
		echo "Refreshing configuration / authorization..." . PHP_EOL;
		global $config;
		$code=$config['code'];
		$client_secret=$config['client_secret'];
		$client_id=$config['client_id'];
		$mosquitto_host=$config['mosquitto_host'];
		$mosquitto_user=$config['mosquitto_user'];
		$mosquitto_pass=$config['mosquitto_pass'];
		$topicbase=$config['topicbase'];
		
		rename($folder . '/miele-config2.php',$folder . '/miele-config2.php.org');
	}


	if (strlen($code) >> 0 ) {
		$url='https://api.mcs3.miele.com/thirdparty/token?client_id=' . urlencode($client_id) . '&client_secret=' . $client_secret . '&code=' . $code . '&redirect_uri=%2Fv1%2Fdevices&grant_type=authorization_code&state=token';
		$postdata="";
		$method='POST';
		$data=getRESTData($url,$postdata,$method,$content);
		$access_token = $data["access_token"];
		$refresh_token = $data["refresh_token"];
		$tokenscreated = true;
		print "Access token: " . $access_token . PHP_EOL;
	}

	if($tokenscreated == true ) {
		$config="<?php" . PHP_EOL . "return array(" . PHP_EOL . "        'access_token'=> '" . $access_token . "'," . PHP_EOL . "        'refresh_token'=> '" . $refresh_token . "'," . PHP_EOL;
		$config = $config . "	'client_id'=> '" . $client_id . "'," . PHP_EOL;
		$config = $config . "	'client_secret'=> '" . $client_secret . "'," . PHP_EOL;
		$config = $config . "	'code'=> '" . $code . "'," . PHP_EOL;
		$config = $config . "	'mosquitto_host'=> '" . $mosquitto_host . "'," . PHP_EOL;
		$config = $config . "	'mosquitto_user'=> '" . $mosquitto_user . "'," . PHP_EOL;
		$config = $config . "	'mosquitto_pass'=> '" . $mosquitto_pass . "'," . PHP_EOL;
		$config = $config . "	'topicbase'=> '" . $topicbase . "'" . PHP_EOL;
		$config = $config . ");" . PHP_EOL . "?>" . PHP_EOL . PHP_EOL;

		if (file_put_contents($folder . "/miele-config2.php", $config) <> false ) {
			echo "Configuration file created!" . PHP_EOL;
			$configcreated=true;
		}
	}

	return $configcreated;
}

################################################################################################################################################
######
######		This is the main script block
######
################################################################################################################################################
require("phpMQTT.php");

$folder=dirname($_SERVER['PHP_SELF']);

if(count($argv) >> 1 ) {
	if ($argv[1] == '-d') {
		$dump=true;
	}
	else {
		$dump=false;
	}
}
else {
	$dump=false;
}

if (file_exists($folder . '/miele-config2.php') == false ) {
	$configcreated=createconfig();
	if($configcreated == false) {
		exit("Failed to create config! Did you type the correct credentials?" . PHP_EOL);
	}
}

$config = include($folder.'/miele-config2.php');
$run=true;
$count=0;

$mosquitto_host=$config['mosquitto_host'];
$mosquitto_user=$config['mosquitto_user'];
$mosquitto_pass=$config['mosquitto_pass'];
$topicbase=$config['topicbase'];
$code=$config['code'];
$access_token=$config['access_token'];

$client_id = "Miele-MQTT"; // make sure this is unique for connecting to sever - you could use uniqid()

$mqtt = new phpMQTT($mosquitto_host, "1883", $client_id);

if(!$mqtt->connect(true, NULL, $mosquitto_user, $mosquitto_pass)) {
	exit(1);
}

$topics[$topicbase . 'command/#'] = array("qos" => 0, "function" => "procmsg");
$mqtt->subscribe($topics, 0);

$count=30;
while($mqtt->proc()){
	if ( $count==30) {
		retrieveandpublish($folder,$dump,$mqtt);
		$count=0;
	}
	sleep(1);
	$count = $count + 1;
}
		

$mqtt->close();

function procmsg($topic, $msg){
	global $access_token;
	$commandTopic=explode('/',$topic);
	for($i = 1; $i <= 10; $i++) {
		if($commandTopic[$i] == "command") {
			$appliance=$commandTopic[$i+1];
			$action=$commandTopic[$i+2];
			$i=10;
		}
	}
	//echo "Sending command: " . $action . " to device: " . $appliance . PHP_EOL;
	//echo $msg . PHP_EOL;
	$url='https://api.mcs3.miele.com/v1/devices/' . $appliance . "/actions";
	$authorization='Bearer ' . $access_token;
	$method='PUT';
	$postdata = array($action=>$msg);
	$data_json = json_encode($postdata);
	//$postdata="{'" . $action . "'," . $msg . '}';
	$data=getRESTData($url,$data_json,$method,'application/json',$authorization);
	//var_dump($data);
}

exit(0);


// Retrieveing information
function retrieveandpublish($folder,$dump,$mqtt) {
	global $mosquitto_host;
	global $mosquitto_user;
	global $mosquitto_pass;
	global $access_token;
	global $topicbase;

	$authorization='';

	if (strlen($access_token) >> 0 ) {
		$url='https://api.mcs3.miele.com/v1/devices/';
		$authorization='Bearer ' . $access_token;
		$method='GET';
		$data=getRESTData($url,'',$method,'application/json',$authorization);
		if (array_search("Unauthorized",$data) != "" ) {
			createconfig(true);
			$config = include($folder . '/miele-config2.php');
			$authorization='Bearer ' . $access_token;
			$method='GET';
			$data=getRESTData($url,'',$method,'',$authorization);
		}
		if ($dump == true ) {
			//var_dump($data);
		}
	}


	if ($dump == false) {
		foreach ($data as $appliance) {
			$appliance_id=$appliance['ident']['deviceIdentLabel']['fabNumber'];
			$appliance_type=$appliance['ident']['type']['value_localized'];
			switch ($appliance_type) {
				case "Dishwasher":
					$programStatus=$appliance['state']['status']['value_localized'];
					$programType=$appliance['state']['programType']['value_raw'];
					$programPhaseRaw=$appliance['state']['programPhase']['value_raw'];
					switch ($programPhaseRaw) {
						case "1792":
							// Purpose unknown, observed when programmed (without phase) and off.
							$programPhase="Not running";
							break;
						case "1793":
							$programPhase="Reactivating";
							break;
						case "1794":
							$programPhase="Pre-wash";
							break;
						case "1795":
							$programPhase="Main wash";
							break;
						case "1796":
							$programPhase="Rinse";
							break;
						case "1797":
							$programPhase="Interim Rinse";
							break;
						case "1798":
							$programPhase="Final rinse";
							break;
						case "1799":
							$programPhase="Drying";
							break;
						case "1800":
							$programPhase="Finished";
							break;
						case "1801":
							$programPhase="Pre-Wash";
							break;
						default:
							$programPhase="Unknown: " . $programPhaseRaw;
							break;
					}
					$timeleft=sprintf("%'.02d:%'.02d",$appliance['state']['remainingTime'][0],$appliance['state']['remainingTime'][1]);
					$timerunning=sprintf("%'.02d:%'.02d",$appliance['state']['elapsedTime'][0],$appliance['state']['elapsedTime'][1]);
					$light_on=$appliance['state']['light'];
					$dryingstep=$appliance['state']['dryingStep']['value_localized'];
					$ventilationstep=$appliance['state']['ventilationStep']['value_localized'];
					$topicbase = $topicbase . $appliance_id . '/';
					$mqtt->publish($topicbase . "ApplianceType", "'".$appliance_type."'");
					$mqtt->publish($topicbase . "ProgramStatus", "'".$programStatus."'");
					$mqtt->publish($topicbase . "ProgramType", "'".$programType."'");
					$mqtt->publish($topicbase . "ProgramPhase", "'".$programPhase."'");
					$mqtt->publish($topicbase . "TimeLeft", $timeleft);
					$mqtt->publish($topicbase . "TimeRunning", $timerunning);
					$mqtt->publish($topicbase . "LightON", "'" . $light_on . "'");
					$mqtt->publish($topicbase . "DryingStep", "'" . $dryingstep . "'");
					$mqtt->publish($topicbase . "VentilationStep", "'" . $ventilationstep . "'");

					//echo "Appliance type: " . $appliance_type . PHP_EOL;
					//echo "Program status: " . $programStatus . PHP_EOL;
					//echo "Program type: " . $programType . PHP_EOL;
					//echo "Program phase: " . $programPhase . PHP_EOL;
					//echo "Time left: " . $timeleft . PHP_EOL;
					//echo "Time elapsed: " . $timerunning . PHP_EOL;
					//echo "Light On: " . $light_on . PHP_EOL;
					//echo "DryingStep: " . $dryingstep . PHP_EOL;
					//echo "Ventilationstep: " . $ventilationstep . PHP_EOL . PHP_EOL;
					break;
				case "Washing Machine":
					$programStatus=$appliance['state']['status']['value_localized'];
					$programType= $appliance['state']['programType']['value_raw'];
					$programPhaseRaw=$appliance['state']['programPhase']['value_raw'];
					switch ($programPhaseRaw) {
						case "256":
							// Purpose unknown, observed when programmed (without phase) and off.
							$programPhase="Not running";
							break;
						case "257":
							$programPhase="Pre-Wash";
							break;
						case "258":
							$programPhase="Soak";
							break;
						case "259":
							$programPhase="Pre-Wash";
							break;
						case "260":
							$programPhase="Main Wash";
							break;
						case "261":
							$programPhase="Rinse";
							break;
						case "262":
							$programPhase="Rinse Hold";
							break;
						case "263":
							$programPhase="Main Wash";
							break;
						case "264":
							$programPhase="Cooling down";
							break;
						case "265":
							$programPhase="Drain";
							break;
						case "266":
							$programPhase="Spin";
							break;
						case "267":
							$programPhase="Anti-crease";
							break;
						case "268":
							$programPhase="Finished";
							break;
						case "269":
							$programPhase="Venting";
							break;
						case "270":
							$programPhase="Starch Stop";
							break;
						case "271":
							$programPhase="Freshen-up + Moisten";
							break;
						case "272":
							$programPhase="Steam Smoothing";
							break;
						case "279":
							$programPhase="Hygiene";
							break;
						case "280":
							$programPhase="Drying";
							break;
						case "285":
							$programPhase="Disinfection";
							break;
						case "295":
							$programPhase="Steam Smoothing";
							break;
						default:
							$programPhase="Unknown: " . $programPhaseRaw;
							break;
					}
					$timeleft=sprintf("%'.02d:%'.02d",$appliance['state']['remainingTime'][0],$appliance['state']['remainingTime'][1]);
					$timerunning=sprintf("%'.02d:%'.02d",$appliance['state']['elapsedTime'][0],$appliance['state']['elapsedTime'][1]);
					$topicbase = $topicbase . $appliance_id . '/';
					$mqtt->publish($topicbase . "ApplianceType", "'".$appliance_type."'");
					$mqtt->publish($topicbase . "ProgramStatus", "'".$programStatus."'");
					$mqtt->publish($topicbase . "ProgramType", "'".$programType."'");
					$mqtt->publish($topicbase . "ProgramPhase", "'".$programPhase."'");
					$mqtt->publish($topicbase . "TimeLeft", $timeleft);
					$mqtt->publish($topicbase . "TimeRunning", $timerunning);
					//echo "Appliance type: " . $appliance_type . PHP_EOL;
					//echo "Program status: " . $programStatus . PHP_EOL;
					//echo "Program type: " . $programType . PHP_EOL;
					//echo "Program phase: " . $programPhase . PHP_EOL;
					//echo "Time left: " . $timeleft . PHP_EOL;
					//echo "Time elapsed: " . $timerunning . PHP_EOL . PHP_EOL;
					break;
				case "Clothes Dryer":
					$programStatus=$appliance['state']['status']['value_localized'];
					$programType= $appliance['state']['programType']['value_raw'];
					$programPhaseRaw=$appliance['state']['programPhase']['value_raw'];
					switch ($programPhaseRaw) {
						case "512":
							// Purpose unknown, observed when programmed (without phase) and off.
							$programPhase="Not running";
							break;
						case "513":
							$programPhase="Program Running";
							break;
						case "514":
							$programPhase="Drying";
							break;
						case "515":
							$programPhase="Machine Iron";
							break;
						case "516":
							$programPhase="Hand Iron";
							break;
						case "517":
							$programPhase="Normal";
							break;
						case "518":
							$programPhase="Normal Plus";
							break;
						case "519":
							$programPhase="Cooling down";
							break;
						case "520":
							$programPhase="Hand Iron";
							break;
						case "521":
							$programPhase="Anti-crease";
							break;
						case "522":
							$programPhase="Finished";
							break;
						case "523":
							$programPhase="Extra Dry";
							break;
						case "524":
							$programPhase="Hand Iron";
							break;
						case "526":
							$programPhase="Moisten";
							break;
						case "528":
							$programPhase="Timed Drying";
							break;
						case "529":
							$programPhase="Warm Air";
							break;
						case "530":
							$programPhase="Steam Smoothing";
							break;
						case "531":
							$programPhase="Comfort Cooling";
							break;
						case "532":
							$programPhase="Rinse out lint";
							break;
						case "533":
							$programPhase="Rinses";
							break;
						case "534":
							$programPhase="Smoothing";
							break;
						case "538":
							$programPhase="Slightly Dry";
							break;						
						case "539":
							$programPhase="Safety Cooling";
							break;
						default:
							$programPhase="Unknown: " . $programPhaseRaw;
						break;
					}
					$timeleft=sprintf("%'.02d:%'.02d",$appliance['state']['remainingTime'][0],$appliance['state']['remainingTime'][1]);
					$timerunning=sprintf("%'.02d:%'.02d",$appliance['state']['elapsedTime'][0],$appliance['state']['elapsedTime'][1]);
					$topicbase = $topicbase . $appliance_id . '/';
					$mqtt->publish($topicbase . "ApplianceType", "'".$appliance_type."'");
					$mqtt->publish($topicbase . "ProgramStatus", "'".$programStatus."'");
					$mqtt->publish($topicbase . "ProgramType", "'".$programType."'");
					$mqtt->publish($topicbase . "ProgramPhase", "'".$programPhase."'");
					$mqtt->publish($topicbase . "TimeLeft", $timeleft);
					$mqtt->publish($topicbase . "TimeRunning", $timerunning);
					//echo "Appliance type: " . $appliance_type . PHP_EOL;
					//echo "Program status: " . $programStatus . PHP_EOL;
					//echo "Program type: " . $programType . PHP_EOL;
					//echo "Program phase: " . $programPhase . PHP_EOL;
					//echo "Time left: " . $timeleft . PHP_EOL;
					//echo "Time elapsed: " . $timerunning . PHP_EOL . PHP_EOL;
					break;
				default:
					echo "Appliance type " . $appliance_type . " is not defined. Please define it, or send information to have it added." . PHP_EOL;
					break;
			}
		}
	}
}
?>
