<?php
use \PDO;

$params = array();
parse_str(implode('&', array_splice($argv, 1)), $params);
define("BASE_DIR", $params['installDir'] . '/');

if(empty($params)) {
	echo "You failed to read the docs. Go read them again\n";
	return;
}

$keyset = "abcdefghijklmABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789@#$%^&*-=+[]()";
$hash = "";

for($i = 0; $i < 48; $i++) {
	$hash .= substr($keyset, rand(0, strlen($keyset) - 1), 1);
}

$pass = "";
for($i = 0; $i < 48; $i++) {
	$pass .= substr($keyset, rand(0, strlen($keyset) - 1), 1);
}

try {

	$fp = fopen(BASE_DIR.'config.json', 'w');
	if($fp === false) {
		throw new \Exception('Could not open config.json');
	}

	fwrite($fp, json_encode(array(
		'mysql' => array(
			'host' => $params['mysqlHost'],
			'port' => $params['mysqlPort']
			'database' => $params['mysqlDatabase'],
			'username' => 'pufferpanel',
			'password' => $pass,
			'ssl' => array(
				'use' => false,
				'client-key' => '/path/to/key.pem',
				'client-cert' => '/path/to/cert.pem',
				'ca-cert' => '/path/to/ca-cert.pem'
			)
		),
		'hash' => $hash
	)));
	fclose($fp);

	if(!file_exists(BASE_DIR.'config.json')) {
		throw new \Exception("Could not create config.json");
	}

	//@TODO Use Port and Database Name here
	$mysql = new PDO('mysql:host='.$params['mysqlHost'], $params['mysqlUser'], $params['mysqlPass'], array(
		PDO::ATTR_PERSISTENT => true,
		PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
	));

	$mysql->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$mysql->beginTransaction();

	$mysqlQueries = file_get_contents("https://raw.githubusercontent.com/PufferPanel/Tools/master/installer/versions/master.sql");
	$mysql->exec($mysqlQueries);

	$query = $mysql->prepare("INSERT INTO `acp_settings` (`setting_ref`, `setting_val`) VALUES
					('company_name', :cname),
					('master_url', :murl),
					('assets_url', :aurl),
					('main_website', :mwebsite),
					('postmark_api_key', NULL),
					('mandrill_api_key', NULL),
					('mailgun_api_key', NULL),
					('sendgrid_api_key', NULL),
					('sendmail_email', NULL),
					('sendmail_method','php'),
					('captcha_pub','6LdSzuYSAAAAAHkmq8LlvmhM-ybTfV8PaTgyBDII'),
					('captcha_priv','6LdSzuYSAAAAAISSAYIJrFGGGJHi5a_V3hGRvIAz'),
					('default_language', :defaultLanguage),
					('force_online', :force),
					('https', 0),
					('use_api', 0),
					('allow_subusers', 0)");

	$query->execute(array(
		':cname' => $params['companyName'],
		':murl' => $params['siteUrl'].'/',
		':mwebsite' => $params['siteUrl'].'/',
		':defaultLanguage' => $params['defaultLanguage'],
		':aurl' => '//'.$params['siteUrl'].'/assets/'
	));

	echo "Settings added\n";

	$uuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
	$mysql->prepare("INSERT INTO `users` VALUES(NULL, :uuid, :username, :email, :password, :language, :time, NULL, NULL, 1, 0, 1, 0, NULL)")->execute(array(
		':uuid' => $uuid,
		':username' => $params['adminName'],
		':email' => $params['adminEmail'],
		':password' => password_hash($params['adminPass'], PASSWORD_BCRYPT),
		':language' => $params['adminLanguage'],
		':time' => time()
	));

	echo "Admin user added\n";

	try {
		$mysql->prepare("DROP USER 'pufferpanel'@'localhost'")->execute();
	} catch(\Exception $ex) {
		//ignoring because no user actually existed
	}
	$mysql->prepare("GRANT SELECT, UPDATE, DELETE, ALTER, INSERT ON pufferpanel.* TO 'pufferpanel'@'localhost' IDENTIFIED BY :pass")->execute(array(
		'pass' => $pass
	));
	echo "PufferPanel SQL user added\n";

	$mysql->commit();

	exit(0);
} catch(\Exception $ex) {

	echo $ex->getMessage()."\n";
	if(isset($mysql) && $mysql->inTransaction()) {
		$mysql->rollBack();
	}
	exit(1);
}
