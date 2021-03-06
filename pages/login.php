<?php
if (!defined('SP_ENDUSER')) die('File not included');

require_once BASE.'/inc/core.php';

if (isset($_POST['username']) && isset($_POST['password'])) {
	$session_name = $settings->getSessionName();
	if ($session_name)
		session_name($session_name);
	session_start();
	session_regenerate_id(true);

	$_SESSION['timezone'] = $_POST['timezone'];
	$username = $_POST['username'];
	$password = $_POST['password'];
	foreach ($settings->getAuthSources() as $method) {
		switch ($method['type']) {
			case 'account':
				if ($username === $method['username'] && $password === $method['password'])
				{
					$_SESSION['username'] = $method['username'];
					$_SESSION['source'] = 'local';
					$_SESSION['access'] = $method['access'];
					break 2;
				}
			break;
			case 'smtp':
				$fp = fsockopen($method['host'], $method['port'] ?: '25');
				while ($line = fgets($fp)) {
					if (substr($line, 0, 1) != '2')
						goto smtp_fail;
					if (substr($line, 3, 1) == ' ')
						break;
				}
				fwrite($fp, "EHLO halon-sp-enduser\r\n");
				$method = 'plain';
				while ($line = fgets($fp)) {
					if (substr($line, 0, 1) != '2')
						goto smtp_fail;
					if (substr($line, 4, 5) == 'AUTH ' && strpos($line, 'CRAM-MD5') !== false)
						$method = 'md5';
					if (substr($line, 3, 1) == ' ')
						break;
				}
				if ($method == 'md5') {
					fwrite($fp, "AUTH CRAM-MD5\r\n");
					$line = fgets($fp);
					$chall = substr($line, 4);
					$data = $username.' '.hash_hmac('md5', base64_decode($chall), $password);
					$data = base64_encode($data);
					fwrite($fp, "$data\r\n");
				} else {
					$plain = base64_encode($username . "\0" . $username . "\0" . $password);
					fwrite($fp, "AUTH PLAIN $plain\r\n");
				}
				while ($line = fgets($fp))
					if (substr($line, 3, 1) != '-')
						break;
				if (substr($line, 0, 3) != '235')
					goto smtp_fail;
				fwrite($fp, "QUIT\r\n");
				$_SESSION['username'] = $username;
				$_SESSION['source'] = 'smtp';
				$_SESSION['access'] = array('mail' => array(strtolower($username)));
				break 2;
				smtp_fail:
					fwrite($fp, "QUIT\r\n");
			break;
			case 'ldap':
				$method = new LDAPDatabase($method['uri'], $method['base_dn'], $method['schema'], $method['options'], $method['query'], $method['access']);
				if ($method->check($username, $password))
					break 2;
			break;
			case 'database':
				$dbh = $settings->getDatabase();
				$statement = $dbh->prepare("SELECT * FROM users WHERE username = :username;");
					$statement->execute(array(':username' => $username));
				$row = $statement->fetch();
				if (!$row || $row['password'] !== crypt($password, $row['password']))
					break;

				$_SESSION['username'] = $row['username'];
				$_SESSION['source'] = 'database';
				$_SESSION['access'] = array();
				$statement = $dbh->prepare("SELECT * FROM users_relations WHERE username = :username;");
				$statement->execute(array(':username' => $row['username']));
				while ($row = $statement->fetch()) {
					$_SESSION['access'][$row['type']][] = $row['access'];
				}
				break 2;
			break;
			case 'server':
				// Loop through all the configured nodes; the primary node going
				// down shouldn't take all auth down with it, merely slow it
				for ($i = 0; $i < count($settings->getNodes()); $i++) {
					try {
						// Attempt to connect to the node
						soap_client($i, false, $username, $password)->login();
						
						// Set the client to be logged in
						$_SESSION['username'] = $username;
						$_SESSION['source'] = 'server';
						$_SESSION['access'] = array();
						
						// Use the user's credentials instead of the config's
						$_SESSION['soap_username'] = $username;
						$_SESSION['soap_password'] = $password;
						
						break 3;
					} catch (SoapFault $e) {
						// If the node is unavailable, skip to the next one
						if($e->getMessage() != "Unauthorized")
							continue;
					}
					
					break;
				}
			break;
		}
	}
	if (isset($_SESSION['username'])) {
		header("Location: .");
		die();
	}
	$error = 'Login failed';
	session_destroy();
}

$title = 'Sign in';
require_once BASE.'/partials/header.php';
?>
	<div class="container">
		<div class="col-md-offset-3 col-md-6">
			<div class="panel panel-default" style="margin-top:40px;">
				<div class="panel-heading">
					<h3 class="panel-title">Sign in</h3>
				</div>
				<div class="panel-body">
					<?php if (isset($error)) { ?>
					<div class="alert alert-danger"><?php p($error) ?></div>
					<?php } ?>
					
					<?php if ($settings->getLoginText() !== null) { ?>
					<p>
						<?php p($settings->getLoginText()); ?>
						<hr />
					</p>
					<?php } ?>
					<form class="form-horizontal" method="post" action="?page=login">
						<input type="hidden" name="timezone" id="timezone">
						<div class="form-group">
							<label for="username" class="control-label col-sm-3">Username</label>
							<div class="col-sm-9">
								<input type="text" class="form-control" name="username" id="username" autofocus="autofocus">
							</div>
						</div>
						<div class="form-group">
							<label for="username" class="control-label col-sm-3">Password</label>
							<div class="col-sm-9">
								<input type="password" class="form-control" name="password" id="password">
							</div>
						</div>
						<div class="form-group">
							<div class="col-sm-offset-3 col-sm-9">
								<button type="submit" class="btn btn-primary">Sign in</button>
								<?php if (has_auth_database()) { ?>
									<a class="btn btn-default" href="?page=forgot">Forgot password</a>
								<?php } ?>
							</div>
						</div>
					</form>
				</div>
			</div>
		</div>
	</div>
	<script>
		$("#timezone").val(new Date().getTimezoneOffset());
	</script>
<?php require_once BASE.'/partials/footer.php'; ?>
