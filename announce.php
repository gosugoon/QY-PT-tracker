<?php

include 'Tracker/Config.php';
use Tracker\Config;

require_once('include/bittorrent_announce.php');
require_once('include/benc.php');

$sqlLink = get_mysql_link();
$row = null;
$res = null;
$errHandle = null;

$delay = 4; // avoid too slow

/**
 * old torrent's promotion state
 *
 *   0: none
 *   1: normal
 *   2: free
 *   3: 2x
 *   4: 2x free
 *   5: half
 *   6: 2x half
 *   7: 30%
 *
 * It Wlll Be Deprecated In Next Update !
 */

$SP_MAP = [
  '0' => 1,
  '1' => 1,
  '2' => 0.1,
  '3' => 1,
  '4' => 0.1,
  '5' => 0.5,
  '6' => 0.5,
  '7' => 0.3,
];

function Notice($err) {
  // TODO:
  benc_resp_raw('d' .benc_str('failure reason') .benc_str($err) .'e');
  die();
}

function getParam($name) {
  return $_GET[$name] ?? null;
};

function hashPad($hash) {
  return str_pad($hash, 20);
}

function esc($str) {
  global $sqlLink;
  return $sqlLink->real_escape_string($str);
}

$info = [
  'event' => getParam('event') ?: '',

  'port' => intval(getParam('port')),
  'left' => intval(getParam('left')),
  'uploaded' => intval(getParam('uploaded')),
  'downloaded' => intval(getParam('downloaded')),

  'agent' => $_SERVER['HTTP_USER_AGENT'],
  'peerid' => getParam('peer_id'),
  'compact' => intval(getParam('compact')),
  'downloadkey' => getParam('downloadkey'),
  'passkey' => getParam('passkey'),
  'infohash' => getParam('info_hash'),
  'noPeerId' => intval(getParam('no_peer_id')),

  'ip' => getip(),
  'ipv6' => getParam('ipv6') ?: '',

  'ts' => time(),
];

$info['hash'] = preg_replace_callback('/./s', function ($matches) {
  return sprintf('%02x', ord($matches[0]));
}, hashPad($info['infohash']));

$peerid = esc($info['peerid']);
$infohash = esc($info['infohash']);

$numwant = max(intval(getParam('numwant')), intval(getParam('num_want')), 0);
$info['numwant'] = min($numwant ? $numwant : 16, 16);

// seeder
$seeder = ($info['left'] == 0);

// check fields
foreach (['passkey', 'infohash', 'peerid'] as $k) {
  if ($info[$k] === null) {
    Notice("Missing key: $k");
  }
}

if (strlen($info['infohash']) != 20) Notice('Invalid "info_hash"');
if (strlen($info['peerid']) != 20) Notice('Invalid "peer_id"');

if (strlen($info['passkey']) != 32) Notice('Invalid "passkey"');
if ($info['port'] <= 0 || $info['port'] > 0xffff) Notice('Invalid "port"');


// validate passkey
// get user info
if (!$row = $Cache->get_value('tracker_user_' .$info['passkey'] .'_content')) {
  $res = $sqlLink->query("SELECT id, downloadpos, enabled, uploaded, downloaded, class, parked, clientselect, showclienterror FROM users WHERE passkey='". esc($info['passkey'])."' LIMIT 0, 1")
    or Notice('Error: 0x0001');
  $row = $res->fetch_assoc();
  if (!$row) {
    // TODO: record invalid passkey
    Notice('Invalid passkey! Please re-download .torrent file.');
  }
  $Cache->cache_value('tracker_user' .$info['passkey'] .'_content', $row, 1850);
}


$user = $row;

// validate user

if ($user['parked'] == 'yes')
  Notice('Account is parked!');
if ($user['enabled'] == 'no')
  Notice('Account is disabled!');
if ($user['downloadpos'] == 'no')
  Notice('Download priviledge is disabled!');

// validate HP
if (!$row = $Cache->get_value('tracker_userbonus_' .$info['passkey'] .'_content')) {
  $res = $sqlLink->query("SELECT bonus FROM tracker_bonus WHERE id = '$user[id]' LIMIT 0,1")
    or Notice('Error: 0x0003');
  $row = $res->fetch_assoc();
  if (!$row) {
    Notice('Please enable tracker first');
  }
  $Cache->cache_value('tracker_userbonus_' .$info['passkey'] .'_content', $row, 1950);
}

if (!$seeder && $row['bonus'] < 0) {
  $errHandle = function () { Notice('You run out of HP.(HP耗尽)'); };

  if ($info['uploaded'] == 0 && $info['downloaded'] == 0) {
    $errHandle();
  }
}

// validate client
// TODO: new method
$clicheck_res = check_client($info['peerid'], $info['agent']);
$client_familyid = check_client_family($info['peerid'], $info['agent'], $clicheck_res);

if ($clicheck_res || !$client_familyid) {
  if ($user['showclienterror'] == 'no') {
    // TODO: record invalid client version
    $sqlLink->query("UPDATE users SET showclienterror = 'yes' WHERE id = '$user[id]'")
      or Notice('Error: 0x1001');
  }
  Notice($clicheck_res);
} elseif ($user['showclienterror'] == 'yes') {
  $userUpdateSet[] = "showclienterror = 'no'";
}

// validate torrent
// get torrent info
// TODO
if (!$row = $Cache->get_value('tracker_hash_' .$info['hash'] .'_content')) {
  $res = $sqlLink->query("SELECT id, owner, sp_state, seeders, leechers, UNIX_TIMESTAMP(added) AS added, banned, timestampdiff(DAY, last_action, NOW()) as diff_action_day FROM torrents WHERE info_hash = '$infohash' LIMIT 0,1")
    or Notice('Error: 0x0004');
  $row = $res->fetch_assoc();
  if (!$row) {
    // TODO: record invalid hashinfo
    Notice('Torrent not exists.(种子未出现)');
  }

  $Cache->cache_value('tracker_hash_' .$info['hash'] .'_content', $row, 1870);
}

$torrent = $row;

// validate torrent priviledge
if ($torrent['banned'] == 'yes' && $user['class'] < $seebanned_class && $torrent['owner'] != $user['id'])
  Notice('Torrent banned!(种子被禁止)');


// get global promotion state
if (!$globalPromotionState = $Cache->get_value('tracker_global_promotion_state')) {
  $res = $sqlLink->query("SELECT * FROM torrents_state LIMIT 1")
    or Notice('Error: 0x0004');
  $row = $res->fetch_row();

  $globalPromotionState = $row ? $row[0] : 1;

  $Cache->cache_value('tracker_global_promotion_state', $globalPromotionState, 1805);
}


// validate torrent-user info
// check torrent download key
$downloadkey = esc($info['downloadkey']);
if (!$row = $Cache->get_value("tracker_snatch_$torrent[id]_$user[id]_content")) {
  $res = $sqlLink->query("SELECT id FROM tracker_snatch WHERE torrent = '$torrent[id]' AND userid = '$user[id]' AND downloadkey = '$downloadkey'")
    or Notice('Error: 0x001a');
  $row = $res->fetch_assoc();
  if (!$row) {
    // TODO: record invaild key
    Notice('Invalid key! Please re-download .torrent file.(请重新下载 .torrent 文件)');
  }

  $Cache->cache_value("tracker_snatch_$torrent[id]_$user[id]_content", $row, 2017);
}

$snatch = $row;

// evalute interval time
$annIntervals = Tracker\Config::$annIntervals;
$annThreshold = Tracker\Config::$annIntervalsThreshold;

$annInterval = $annIntervals[0];
if ((TIMENOW - $torrent['added']) >= $annThreshold[1]) {
  $realAnnInterval = $annIntervals[2];
} else if ((TIMENOW - $torrent['added']) < $annThreshold[0]) {
  $realAnnInterval = $annIntervals[0];
} else {
  $realAnnInterval = $annIntervals[1];
}


// get peer info

$peerFields = join(',', [
  'seeder',
  'peer_id',
  'ip',
  'ipv6',
  'port',
  'uploaded',
  'downloaded',
  'UNIX_TIMESTAMP(last_action) AS last_action',
  'UNIX_TIMESTAMP(prev_action) AS prev_action',
]);

if ($info['left'] == 0) {
  $numPeerMax = $torrent['leechers'];
} else {
  $numPeerMax = $torrent['leechers'] + $torrent['seeders'];
}

$self = null;
$res = $sqlLink->query("SELECT $peerFields FROM tracker_peers WHERE torrent = '$torrent[id]' AND userid = '$user[id]' AND peer_id = '$peerid' LIMIT 0,1")
  or Notice('Error: 0x0005');
$self = $res ? $res->fetch_assoc() : null;

// validate interval time

if ($self && $self['prev_action'] > (TIMENOW - $annInterval + $delay)) {
  $errHandle = $errHandle ?: function () use ($annInterval) { Notice("There is a minimum announce time of $annInterval seconds.(服务器已收到请求，请等待)"); };
}

// validate leech and seed limit
$res = $sqlLink->query("SELECT COUNT(*) FROM tracker_peers WHERE torrent = '$torrent[id]' AND userid = '$user[id]' AND peer_id != '$peerid'")
  or Notice('Error: 0x0006');
$num = $res ? $res->fetch_row()[0] : 0;
if ($num > 0 && !$seeder)
  Notice('Waiting for cleaning redundant peers.(请手动清除冗余做种)');
if ($num > 5 && $seeder)
  Notice('Please seed the same torrent from less than 4 locations.(请手动清除冗余做种)');

// basic info

$body = [
  benc_str('interval') .'i' .$realAnnInterval .'e',
  benc_str('min interval') .'i' .$annInterval .'e',
  benc_str('complete') .'i' .$torrent['seeders'] .'e',
  benc_str('incomplete') .'i' .$torrent['leechers'] .'e',
];

// get peer list

if (!$errHandle) {
  // check compact flag
  if (!ip2long($info['ip'])) $info['compact'] = 0; // disable for IPv6

  $res = $sqlLink->query("SELECT $peerFields FROM tracker_peers WHERE torrent = '$torrent[id]' "
    .($seeder ? "AND seeder = 0 " : '')
    ."ORDER BY RAND() LIMIT 0, $info[numwant]")
    or Notice('Error: 0x0008');

  $peerList = [];
  while ($res && $row = $res->fetch_assoc()) {
    $row['peer_id'] = hashPad($row['peer_id']);
    if ($row['peer_id'] === $info['peerid']) {
      continue;
    }

    // TODO: network address transform
    // TODO: ip or ipv6 from 'ip' field
    if ($info['compact'] == 1) {
      $l = ip2long($row['ip']);
      // skip IPv6 address
      if ($l)
        $peerList[] = pack('Nn', sprintf('%d', $l), $row['port']);
    } elseif ($info['noPeerId'] == 1) {
      if ($row['ip'])
        $peerList[] = 'd'
          . implode('', array_map('benc_str', ['ip', $row['ip'], 'port']))
          . 'i' .$row['port'] . 'e'
          . 'e';
      if ($row['ipv6'] && $row['ip'] != $row['ipv6'])
        $peerList[] = 'd'
          . implode('', array_map('benc_str', ['ip', $row['ipv6'], 'port']))
          . 'i' . $row['port'] . 'e'
          . 'e';
    } else {
      if ($row['ip'])
        $peerList[] = 'd'
          . join('', array_map('benc_str', ['ip', $row['ip'], 'peer id', $row['peer_id'], 'port']))
          . 'i' . $row['port'] . 'e'
          . 'e';
      if ($row['ipv6'])
        $peerList[] = 'd'
          . join('', array_map('benc_str', ['ip', $row['ipv6'], 'peer id', $row['peer_id'], 'port']))
          . 'i' . $row['port'] . 'e'
          . 'e';
    }
  }

  $peerInfo = benc_str('peers');
  if ($info['compact'] == 1)
    $peerInfo .= benc_str(join('', $peerList));
  else
    $peerInfo .= 'l' .join('', $peerList) .'e';

  // TODO: check user level
  $body[] = $peerInfo;
} else {
  $body[] = '5:peers0:';
}

// update peer

$dt = esc(date('Y-m-d H:i:s', $info['ts']));
if ($self) {
  // TODO: ip info
  $updates = [
    "togo = '$info[left]'",
    "uploaded = '$info[uploaded]'",
    "downloaded = '$info[downloaded]'",
    "ip = '" .esc($info['ip']) ."'",
    "ipv6 = '" .esc($info['ipv6']) ."'",
    "last_action = '$dt'",
    "seeder = '$seeder'",
  ];

  if ($info['event'] == 'started')
    $updates[] = "startdat = '$dt'";

  if (!$errHandle)
    $updates[] = "prev_action = '$dt'";

  $where = "torrent = '$torrent[id]' AND userid = '$user[id]' AND peer_id = '$peerid'";

  if ($info['event'] == 'completed') { // complete: update seeder status
    $sqlLink->query("UPDATE tracker_peers SET " .join(',', $updates) ." WHERE $where")
      or Notice('Error: 0x1002');
  } elseif ($info['event'] == 'stopped') { // stop: delete peer
    // TODO: check if time is 0
    $sqlLink->query("DELETE FROM tracker_peers WHERE $where")
      or Notice('Error: 0x2001');
  } else { // start or continue: update peer status
    $sqlLink->query("UPDATE tracker_peers SET " .join(',', $updates) ." WHERE $where")
      or Notice('Error: 0x1003');
  }

  if ($sqlLink->affected_rows == 0) {
    // no peer exist
    Notice('Error: 0x1003');
  }
} else {
  // TODO: if not find 'started' event, log it
  $fields = [
    'torrent',
    'userid',
    'peer_id',
    'agent',
    'port',
    'ip',
    'ipv6',
    'uploaded',
    'downloaded',
    'uploadoffset',
    'downloadoffset',
    'togo',
    'seeder',
    'last_action',
    'prev_action',
  ];

  $values = [
    $torrent['id'],
    $user['id'],
    $peerid,
    esc($info['agent']),
    $info['port'],
    esc($info['ip']), // TODO: validate ip
    esc($info['ipv6']),
    $info['uploaded'],
    $info['downloaded'],
    $info['uploaded'],
    $info['downloaded'],
    $info['left'],
    $seeder,
    $dt,
    $dt,
  ];

  $sqlLink->query("INSERT INTO tracker_peers (" .join(',', $fields) .") VALUES ('" .join("','", $values) ."')")
    or Notice('Error: 0x000a');
}

// calculate upload and download traffic and time <-- report
// NOTE: if traffic lt 0, guess peer should be reset
// TODO: if traffic lt 0, check reset or log anormaly
if (!$self || $info['event'] == 'started') { // ignore old peer
  $upTraffic = $info['uploaded'];
  $downTraffic = $info['downloaded'];
  $timeTraffic = 0;
} else {
  $upTraffic = $info['uploaded'] - $self['uploaded'];
  $downTraffic = $info['downloaded'] - $self['downloaded'];
  $timeTraffic = max(TIMENOW - $self['last_action'], 0);
}

// record traffic
if ($downTraffic || $upTraffic) {
  $fields = [
    'torrent',
    'userid',
    'peer_id',
    'port',
    'during',
    'up',
    'dl',
    'rest_up',
    'rest_dl',
    'seeder',
  ];

  $values = [
    $torrent['id'],
    $user['id'],
    $peerid,
    $info['port'],
    $timeTraffic,
    $upTraffic,
    $downTraffic,
    $upTraffic,
    $downTraffic,
    $seeder,
  ];

  $sqlLink->query("INSERT INTO tracker_traffic (" .join(',', $fields) .") VALUES ('" .join("','", $values) ."')")
    or Notice('Error: 0x000b');
}

// record time
$fields = [
  'torrent',
  'userid',
  'during',
  'seeder',
];

$values = [
  $torrent['id'],
  $user['id'],
  $timeTraffic,
  $seeder,
];

$sqlLink->query("INSERT INTO tracker_traffic_null (" .join(',', $fields) .") VALUES ('" .join("','", $values) ."')")
  or Notice('Error: 0x000c');


// update snatch

$updates = [];

// check if timeTrafffic == 0
if ($timeTraffic > 0) {
  $updates[] = "uploaded = uploaded + " . max(0, $upTraffic); // ensure traffic gt 0
  $updates[] = "downloaded = downloaded + " . max(0, $downTraffic);
  $updates[] = ($seeder && !$downTraffic) ? "seedtime = seedtime + $timeTraffic" : "leechtime = leechtime + $timeTraffic";
}

if ($info['event'] == 'completed') {
  $updates[] = "finish_times = finish_times + 1";
  $updates[] = "finishdat = '$dt'";
}
if (count($updates) > 0) {
  $sqlLink->query("UPDATE tracker_snatch SET " .join(',', $updates) ." WHERE id=$snatch[id]")
    or Notice('Error: 0x1009');
}


// update torrent
if ($seeder) {
  $updates = [
    "visible = 'yes'",
    "last_action = '$dt'",
  ];

  if ($info['event'] == 'completed') {
    $updates[] = 'times_completed = times_completed + 1';
  }

  $sqlLink->query("UPDATE torrents SET " .join(',', $updates) ." WHERE id = '$torrent[id]'")
    or Notice('Error: 0x1005');

  // generator tag
  // TODO: use torrent 'tag' field
  if ($torrent['diff_action_day'] > 100)
    $sqlLink->query("INSERT INTO sitelog (added, txt, security_level) VALUES('$dt', 'Torrent $torrent[id] added BUMP tag. The seeder (User $user[id]) exists after $torrent[diff_action_day] days.', 'mod')")
      or Notice('Error: 0x000d');
}


// update user
// NOTE: not update user seed/leech time and query sum from `snatch` table.
if ($client_familyid != 0 && $client_familyid != $user['clientselect'])
  $userUpdateSet[] = "clientselect = '$client_familyid'";

if ($seeder)
  $userUpdateSet[] = "last_access = '$dt'";

if (isset($userUpdateSet) && count($userUpdateSet) > 0) {
  $sqlLink->query("UPDATE users SET " .join(',', $userUpdateSet) ." WHERE id = '$user[id]'")
    or Notice('Error: 0x1006');
}

// output info

if ($errHandle) $errHandle();

benc_resp_raw('d' .join('', $body) .'e');
exit();
/* END */
