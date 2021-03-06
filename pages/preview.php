<?php
if (!defined('SP_ENDUSER')) die('File not included');

require_once BASE.'/inc/core.php';
require_once BASE.'/inc/utils.php';

$id = preg_replace('/[^0-9]/', '', $_GET['id']);
$type = $_GET['type'];
$node = null;

if ($type == 'log') {
	// Log = database backend
	$dbBackend = new DatabaseBackend($settings->getDatabase());
	$mail = $dbBackend->getMail($id);
	if (!$mail) die('Invalid mail');

	// If in queue, get it from SOAP if possible (so that we get more info)
	if ($mail->msgaction == 'QUEUE' || $mail->msgaction == 'QUARANTINE')
		$node = $settings->getNodeBySerial($mail->serialno);
	if ($node !== null) {
		$client = $node->soap();
		$nodeBackend = new NodeBackend($node);
		$node_mail = $nodeBackend->getMailInQueueOrHistory('messageid='.$mail->msgid.' actionid='.$mail->msgactionid, $errors, $node_type);
		if ($node_mail && !$errors) {
			$mail = $node_mail;
			$type = $node_type;
		}
	}
} else {
	// Fetch data from SOAP
	$node = $settings->getNode($_GET['node']);
	if (!$node) die('Invalid mail');

	$client = $node->soap();
	$nodeBackend = new NodeBackend($node);
	if ($_GET['type'] == 'history')
		$mail = $nodeBackend->getMailInHistory('historyid='.$id, $errors);
	else
		$mail = $nodeBackend->getMailInQueueOrHistory('queueid='.$id, $errors, $type);
	if (!$mail || $errors)
		die('Invalid mail');

	// Action are only available for mail in queue
	if (isset($_POST['action']) && $type == 'queue') {
		if ($_POST['action'] == 'bounce')
			$client->mailQueueBounce(array('id' => $mail->id));
		else if ($_POST['action'] == 'delete')
			$client->mailQueueDelete(array('id' => $mail->id));
		else if ($_POST['action'] == 'retry')
			$client->mailQueueRetry(array('id' => $mail->id));
		header('Location: ?page=preview&type=queue&id='.$mail->id.'&node='.$node->getId());
		die();
	}
}

$action_classes = array(
	'DELIVER' => 'success',
	'QUEUE' => 'info',
	'QUARANTINE' => 'warning',
	'REJECT' => 'danger',
	'DELETE' => 'danger',
	'BOUNCE' => 'warning',
	'ERROR' => 'warning',
	'DEFER' => 'warning',
);
$action_icons = array(
	'DELIVER' => 'ok',
	'QUEUE' => 'transfer',
	'QUARANTINE' => 'inbox',
	'REJECT' => 'ban-circle',
	'DELETE' => 'trash',
	'BOUNCE' => 'exclamation-sign',
	'ERROR' => 'exclamation-sign',
	'DEFER' => 'warning-sign',
);
if ($type == 'queue' && $mail->msgaction == 'DELIVER') $mail->msgaction = 'QUEUE';

// Prepare data
$display_scores = $settings->getDisplayScores();
$logs = $settings->getDisplayTextlog();
$transports = $settings->getDisplayTransport();
$listeners = $settings->getDisplayListener();
if (isset($transports[$mail->msgtransport])) $transport = $transports[$mail->msgtransport];
if (isset($listeners[$mail->msglistener])) $listener = $listeners[$mail->msglistener];
if ($mail->msgaction == 'QUEUE')
	$desc = 'In queue (retry '.$mail->msgretries.')<br /><span class="text-muted">'.htmlspecialchars($mail->msgerror).'</span>';
else
	$desc = htmlspecialchars($mail->msgdescription);
if ($type == 'queue') {
	$uniq = uniqid();
	$command = array('previewmessage', $mail->msgpath, $uniq);
	if ($mail->msgdeltapath)
		$command[] = $mail->msgdeltapath;
	$data = soap_exec($command, $client);
	$data = str_replace("\r\n", "\n", $data);
	$data = explode("$uniq|", $data);
	$result = array();
	$result['HEADERS'] = trim($data[0]);
	for ($i = 1; $i < count($data); ++$i) {
		list($format, $content) = explode("\n", $data[$i], 2);
		$result[$format] = trim($content);
	}
	if (isset($result['TEXT']) || isset($result['HTML'])) {
		$config = HTMLPurifier_Config::createDefault();
		$config->set('Cache.DefinitionImpl', null);
		$config->set('URI.Disable', true);
		$purifier = new HTMLPurifier($config);
		$header = $result['HEADERS'];
		$headerdelta = $result['HEADERS-DELTA'];
		$attachments = $result['ATTACHMENTS'] != "" ? array_map(function ($k) { return explode('|', $k); }, explode("\n", $result['ATTACHMENTS'])) : array();

		$body = isset($result['TEXT']) ? htmlspecialchars($result['TEXT']) : $result['HTML'];
		$body = trim($purifier->purify($body));
		$encode = isset($result['TEXT']) ? 'TEXT' : 'HTML';
	} else {
		$encode = 'HTML';
		$body = '<p class="text-center text-muted">Preview unavailable</p>';
	}
}

$title = 'Message';
$show_back = true;
$body_class = 'has-bottom-bar';
$javascript[] = 'static/js/preview.js';
$javascript[] = 'static/js/diff_match_patch.js';
$javascript[] = 'static/js/diff.js';
require_once BASE.'/partials/header.php';
?>
	<nav class="navbar navbar-toolbar navbar-static-top hidden-xs">
		<div class="container-fluid">
			<div class="navbar-header">
				<a id="history_back" class="navbar-brand" href="javascript:history.go(-1);">&larr;&nbsp;Back</a>
			</div>
			<ul class="nav navbar-nav navbar-right">
				<?php if ($logs && $node) { ?>
					<li><a href="?page=log&id=<?php p($mail->id) ?>&node=<?php p($node->getId()) ?>&type=<?php p($type) ?>"><i class="glyphicon glyphicon-book"></i>&nbsp;Text log</a></li>
				<?php } ?>
					<?php if ($type == 'queue' && $node) { ?>
					<li><a href="?page=download&id=<?php p($mail->id) ?>&node=<?php p($node->getId()) ?>"><i class="glyphicon glyphicon-download"></i>&nbsp;Download</a></li>
					<li class="divider"></li>
					<li><a data-action="delete"><i class="glyphicon glyphicon-trash"></i>&nbsp;Delete</a></li>
					<li><a data-action="bounce"><i class="glyphicon glyphicon-repeat"></i>&nbsp;Bounce</a></li>
					<li><a data-action="retry"><i class="glyphicon glyphicon-play-circle"></i>&nbsp;Retry/release</a></li>
				<?php } ?>
			</ul>
		</div>
	</nav>
	<?php if (($logs && count($settings->getNodes())) || $type == 'queue') { ?>
	<nav class="navbar navbar-default navbar-fixed-bottom visible-xs" id="bottom-bar">
		<div class="container-fluid">
			<ul class="nav navbar-nav">
				<li class="dropdown">
					<a href="#" class="dropdown-toggle" data-toggle="dropdown" role="button" aria-expanded="false">Actions <span class="caret"></span></a>
					<ul class="dropdown-menu" role="menu">
						<?php if ($logs && $node) { ?>
							<li><a href="?page=log&id=<?php p($mail->id) ?>&node=<?php p($node->getId()) ?>&type=<?php p($type) ?>"><i class="glyphicon glyphicon-book"></i>&nbsp;Text log</a></li>
						<?php } ?>
						<?php if ($type == 'queue') { ?>
							<li><a href="?page=download&id=<?php p($mail->id) ?>&node=<?php p($node->getId()) ?>"><i class="glyphicon glyphicon-download"></i>&nbsp;Download</a></li>
							<li class="divider"></li>
							<li><a data-action="delete"><i class="glyphicon glyphicon-trash"></i>&nbsp;Delete message</a></li>
							<li><a data-action="bounce"><i class="glyphicon glyphicon-repeat"></i>&nbsp;Bounce message</a></li>
							<li><a data-action="retry"><i class="glyphicon glyphicon-play-cicle"></i>&nbsp;Retry/release message</a></li>
						<?php } ?>
					</ul>
				</li>
			</ul>
		</div>
	</nav>
	<?php } ?>
	<div class="container-fluid">
		<div class="row">
			<div class="col-md-5 col-md-push-7">
				<div class="panel panel-default panel-<?php p($action_classes[$mail->msgaction]); ?>">
					<div class="panel-heading">
						<h3 class="panel-title">Details</h3>
					</div>
					<div class="panel-body">
						<dl class="dl-horizontal">
							<dt>Action</dt><dd>
								<span class="glyphicon glyphicon-<?php echo $action_icons[$mail->msgaction] ?>"></span>
								<?php p($mail->msgaction) ?>
							</dd>
							<dt>From</dt><dd class="wrap"><?php p($mail->msgfrom) ?>&nbsp;</dd>
							<dt>To</dt><dd class="wrap"><?php p($mail->msgto) ?>&nbsp;</dd>
							<dt>Date</dt><dd><?php p(strftime2('%Y-%m-%d %H:%M:%S', $mail->msgts0 - $_SESSION['timezone'] * 60)) ?></dd>
							<?php if ($mail->msgsize) { ?><dt>Size</dt><dd class="wrap"><?php p(format_size($mail->msgsize)) ?>&nbsp;</dd><?php } ?>
							<?php if ($desc) { ?><dt>Details</dt><dd><?php pp($desc) ?></dd><?php } ?>
							<?php if ($listener) { ?><dt>Received by</dt><dd><?php p($listener) ?></dd><?php } ?>
							<dt>Server</dt><dd><?php p($mail->msgfromserver) ?>&nbsp;</dd>
							<?php if ($mail->msgsasl !== '') { ?><dt>User</dt><dd><?php p($mail->msgsasl) ?></dd><?php } ?>
							<?php if ($transport) { ?><dt>Destination</dt><dd><?php p($transport) ?></dd><?php } ?>
							<dt>ID</dt><dd><?php p($mail->msgid) ?></dd>
						</dl>
					</div>
				</div>
				
				<?php
				if ($display_scores) {
					$scores = history_parse_scores($mail);
				?>
				<div class="panel panel-default <?php if (count($scores) == 0) { ?>hidden-xs<?php } ?>">
					<div class="panel-heading">
						<h3 class="panel-title">Scores</h3>
					</div>
					<table class="table">
						<thead>
							<tr>
								<th>Engine</th>
								<th>Result</th>
								<th class="hidden-xs">Signature</th>
							</tr>
						</thead>
						<tbody>
						<?php
						if (count($scores) > 0)
						foreach ($scores as $score) { ?>
							<tr>
							<td><?php p($score['name']) ?></td>
							<td><?php p($score['score']) ?></td>
							<td class="text-muted hidden-xs wrap"><?php p($score['text']) ?></td>
							</tr>
						<?php } else { ?>
							<tr>
								<td colspan="3" class="text-muted text-center">No Scores</td>
							</tr>
						<?php } ?>
						</tbody>
					</table>
				</div>
				<?php } ?>
			</div>
			
			<div class="col-md-7 col-md-pull-5">
				<div class="panel panel-default">
					<div class="panel-heading">
						<h3 class="panel-title"><?php p($mail->msgsubject) or pp('<span class="text-muted">No Subject</span>'); ?></h3>
					</div>
					<?php
					if (!isset($body))
						echo '<div class="panel-body msg-body"><p class="text-muted text-center">Content unavailable<br /><small>Message is not in queue or quarantine</small></p></div>';
					else if ($encode == 'TEXT')
						echo '<pre class="panel-body msg-body">'.$body.'</pre>';
					else if ($encode == 'HTML')
						echo '<div class="panel-body msg-body">'.$body.'</div>';
					?>
					
					<?php if (count($attachments) > 0) { ?>
					<div class="panel-footer">
						<ul class="list-inline">
							<?php foreach ($attachments as $i => $a) { ?>
								<li class="nowrap">
									<i class="glyphicon glyphicon-paperclip"></i>
									<?php p($a[2]); ?>&nbsp;<small class="text-muted">(<?php p(format_size($a[1])); ?>)</small>
								</li>
							<?php } ?>
						</ul>
					</div>
					<?php } ?>
				</div>
				
				<?php if ($header != '') { ?>
				<div class="panel panel-default">
					<div class="panel-heading">
						<div class="pull-right preview-headers-legend">
							<div style="background-color: #ddffdd; border: 1px solid #ccc;"></div>
							<p style="color: green;">Added</p>
							<div style="background-color: #ffdddd; border: 1px solid #ccc;"></div>
							<p style="color: red;">Removed</p>
						</div>
						<h3 class="panel-title">Headers</h3>
					</div>
					<div class="panel-body preview-headers-container">
						<div class="preview-headers wrap" id="preview-headers-go-here"></div>
					</div>
				</div>
				<script>
					var headers_original = <?php echo json_encode($header); ?>;
					var headers_modified = <?php echo json_encode($headerdelta); ?>;
					$("#preview-headers-go-here").html(diff_lineMode(headers_original,
						headers_modified ? headers_modified : headers_original, true));
				</script>
				<?php } ?>
			</div>
		</div>
		<?php if ($node) { ?>
		<form id="actionform" method="post" action="?page=preview&node=<?php p($node->getId()) ?>&id=<?php p($mail->id) ?>">
			<input type="hidden" name="action" id="action" value="">
			<input type="hidden" name="referer" id="referer" value="<?php p(isset($_POST['referer']) ? $_POST['referer'] : $_SERVER['HTTP_REFERER']); ?>">
		</form>
		<?php } ?>
	</div>
<?php require_once BASE.'/partials/footer.php'; ?>
