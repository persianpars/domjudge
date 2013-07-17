<?php

/**
 * DomJudge public REST API
 *
 * Part of the DOMjudge Programming Contest Jury System and licenced
 * under the GNU GPL. See README and COPYING for details.
 */

if ( ! defined('LIBWWWDIR') )
	require_once('../configure.php');

if ( ! defined('IS_JURY') ) define('IS_JURY', false);

// TODO: use IS_JURY constant in code below for access rights

require_once(LIBDIR . '/init.php');

setup_database_connection();

require_once(LIBWWWDIR . '/common.php');
require_once(LIBWWWDIR . '/print.php');
require(LIBWWWDIR . '/scoreboard.php');
require(LIBWWWDIR . '/restapi.php');

$cdata = getCurContest(TRUE);
$cid = (int)$cdata['cid'];


function infreeze($time) {
  if ( ( ! empty($cdata['freezetime']) &&
       difftime($time, $cdata['freezetime'])>0 ) &&
    !( ! empty($cdata['unfreezetime']) &&
       difftime($time, $cdata['unfreezetime'])<=0 ) ) return TRUE;
  return FALSE;
}

$api = new RestApi();

/**
 * API information
 */
function info() {
  return array('api_version' => 1,
               'domjudge_version' => DOMJUDGE_VERSION);
}
$doc = "Get general API information.";
$api->provideFunction('GET', 'info', 'info', $doc);


/**
 * Contest information
 */
function contest() {
  global $cid, $cdata;

  return array('id'      => $cid,
               'name'    => $cdata['contestname'],
               'start'   => $cdata['starttime'],
               'end'     => $cdata['endtime']);
}
$doc = "Get information about the current contest: id, name, start and end.";
$api->provideFunction('GET', 'contest', 'contest', $doc);

/**
 * Problems information
 */
function problems() {
  global $cid, $DB;

  $q = $DB->q('SELECT probid, name, color FROM problem
                 WHERE cid = %i AND allow_submit = 1 ORDER BY probid', $cid);
  return $q->gettable();
}
$doc = "Get a list of problems in the contest, with for each problem: probid, name and color.";
$api->provideFunction('GET', 'problems', 'problems', $doc);

/**
 * Judgings information
 */
function judgings($args) {
  global $cid, $DB;

  $query = 'SELECT submitid, judgingid, eventtime FROM event WHERE cid = %i';
  if ( !IS_JURY ) {
	$query .= ' AND description = "problem judged"';
  }
  
  $hasFromid = array_key_exists('fromid', $args);
  $query .= ($hasFromid ? ' AND judgingid >= %i' : ' AND TRUE %_');
  $fromId = ($hasFromid ? $args['fromid'] : 0);

  $hasJudgingid = array_key_exists('judgingid', $args);
  $query .= ($hasJudgingid ? ' AND judgingid = %i' : ' AND TRUE %_');
  $judgingid = ($hasJudgingid ? $args['judgingid'] : 0);
  
  $query .= ' ORDER BY eventid';
  
  $hasLimit = array_key_exists('limit', $args);
  $query .= ($hasLimit ? ' LIMIT %i' : ' %_');
  $limit = ($hasLimit ? $args['limit'] : -1);
  // TODO: validate limit
  
  $q = $DB->q($query, $cid, $fromId, $judgingid, $limit);
  $res = array();
  while($row = $q->next()) {
    $data = $DB->q('MAYBETUPLE SELECT s.submittime, j.result FROM judging j
                    LEFT JOIN submission s ON (s.submitid = j.submitid)
                    WHERE j.judgingid = %i', $row['judgingid']);
    if ($data == NULL) continue;

    if ( !IS_JURY && infreeze($data['submittime']) ) continue;
    if ( array_key_exists('result', $args) && $args['result'] != $data['result']) continue; // This should be encoded directly in the query

    $res[] = array('judgingid' => $row['judgingid'], 'submitid' => $row['submitid'], 'result' => $data['result'], 'time' => $row['eventtime']);
  }
  return $res;
}
$doc = 'Get all judgings. Jury only? Or provide some limited list for the public?';
$args = array('result' => 'Search only for judgings with a certain result.',
              'fromid' => 'Search from a certain ID',
              'judgingid' => 'Search only for a certain ID',
              'limit' => 'Get only the first N judgings');
$exArgs = array(array('result' => 'correct'), array('fromid' => 800, 'limit' => 10));
$api->provideFunction('GET', 'judgings', 'judgings', $doc, $args, $exArgs);
function judgings_POST($args) {
  global $DB, $api, $cid;

  // FIXME; get cid from problem instead

  if ( !isset($args['submitid']) ) {
	  $api->createError("submitid is mandatory");
  }
  if ( !isset($args['judgehost']) ) {
	  $api->createError("judgehost is mandatory");
  }

  $query = 'RETURNID INSERT INTO judging (submitid,cid,starttime,judgehost) VALUES(%i,%i,%s,%s)';
  $q = $DB->q($query, $args['submitid'], $cid, now(), $args['judgehost']);

  return array('judgingid' => $q);
}
$doc = 'Add a new judging to the list of judgings.';
$args = array('submitid' => 'Judging corresponds to this specific submitid.',
	'judgehost' => 'Judging is to be judged by this specific judgehost.');
$exArgs = array();
if ( IS_JURY ) {
	$api->provideFunction('POST', 'judgings', 'judgings_POST', $doc, $args, $exArgs);
}

/**
 * Submissions information
 */
function submissions($args) {
  global $cid, $DB;

  $query = 'SELECT submitid, teamid, probid, langid, submittime, valid FROM submission WHERE cid = %i';

  $hasLanguage = array_key_exists('language', $args);
  $query .= ($hasLanguage ? ' AND langid = %s' : ' AND TRUE %_');
  $language = ($hasLanguage ? $args['language'] : 0);
  
  $hasFromid = array_key_exists('fromid', $args);
  $query .= ($hasFromid ? ' AND submitid >= %i' : ' AND TRUE %_');
  $fromId = ($hasFromid ? $args['fromid'] : 0);

  $hasSubmitid = array_key_exists('submitid', $args);
  $query .= ($hasSubmitid ? ' AND submitid = %i' : ' AND TRUE %_');
  $submitid = ($hasSubmitid ? $args['submitid'] : 0);
  
  $query .= ' ORDER BY submitid';
  
  $hasLimit = array_key_exists('limit', $args);
  $query .= ($hasLimit ? ' LIMIT %i' : ' %_');
  $limit = ($hasLimit ? $args['limit'] : -1);
  // TODO: validate limit
  
  $q = $DB->q($query, $cid, $language, $fromId, $submitid, $limit);
  $res = array();
  while($row = $q->next()) {
    $res[] = array('submitid' => $row['submitid'],
                   'teamid' => $row['teamid'],
                   'probid' => $row['probid'],
                   'langid' => $row['langid'],
                   'submittime' => $row['submittime'],
                   'valid' => (bool)$row['valid']);
  }
  return $res;
}
$args = array('language' => 'Search only for submissions in a certain language.',
              'submitid' => 'Search only a certain ID',
              'fromid' => 'Search from a certain ID',
	      'limit' => 'Get only the first N submissions');
$doc = 'Get a list of all submissions. Should we give away all info about submissions? Or is there something we would like to hide, for example language?';
$exArgs = array(array('fromid' => 100, 'limit' => 10), array('language' => 'cpp'));
$api->provideFunction('GET', 'submissions', 'submissions', $doc, $args, $exArgs);
function submissions_PUT($args) {
  global $DB;

  if ( !isset($args['__primary_key']) ) {
	  $api->createError("submitid is mandatory");
  }
  $submitid = $args['__primary_key'];

  $query = 'RETURNAFFECTED UPDATE submission SET ';

  $sep = '';
  $hasJudgehost = array_key_exists('judgehost', $args);
  $query .= $sep . ($hasJudgehost ? ' judgehost = %s' : ' %_');
  $judgehost = ($hasJudgehost ? $args['judgehost'] : null);
  if ( $hasJudgehost ) $sep = ', ';

  $hasJudgemark = array_key_exists('judgemark', $args);
  $query .= $sep . ($hasJudgemark ? ' judgemark = %s' : ' %_');
  $judgemark = ($hasJudgemark ? $args['judgemark'] : null);
  if ( $hasJudgemark ) $sep = ', ';

  $query .= ' WHERE submitid = %i';
  if ( $hasJudgemark ) {
	// update exactly one submission with our random string
	// Note: this might still return 0 if another judgehost beat
	// us to it
  	$query .= ' AND judgemark IS NULL';
  }
  
  $q = $DB->q($query, $judgehost, $judgemark, $submitid);
  if ( $q == 0 ) return array();

  return submissions(array('submitid' => $submitid));
}
$args = array('judgehost' => 'Try to set judgehost.',
              'judgemark' => 'Try to set judgemark if NULL');
$doc = 'Update a single submission.';
$exArgs = array();
if ( IS_JURY ) {
	$api->provideFunction('PUT', 'submissions', 'submissions_PUT', $doc, $args, $exArgs);
}

/**
 * Judging Queue
 */
function queue($args) {
  global $DB;

  // TODO: make this configurable
  $cdata = getCurContest(TRUE);
  $cid = $cdata['cid'];

  // we have to check for the judgability of problems/languages this way,
  // because we use an UPDATE below where joining is not possible.
  $probs = $DB->q('COLUMN SELECT probid FROM problem WHERE allow_judge = 1');
  if( count($probs) == 0 ) {
	  return '';
  }
  $judgable_prob = array_unique(array_values($probs));
  $langs = $DB->q('COLUMN SELECT langid FROM language WHERE allow_judge = 1');
  if( count($langs) == 0 ) {
	  return '';
  }
  $judgable_lang = array_unique(array_values($langs));

  // First, use a select to see whether there are any judgeable
  // submissions. This query is query-cacheable, and doing a select
  // first prevents a write-lock on the submission table if nothing is
  // to be judged, and also prevents throwing away the query cache every
  // single time
  $numopen = $DB->q('VALUE SELECT COUNT(*) FROM submission
                     WHERE judgemark IS NULL AND cid = %i AND langid IN (%As)
                     AND probid IN (%As) AND submittime < %s AND valid = 1',
                    $cid, $judgable_lang, $judgable_prob, $cdata['endtime']);

  if ( $numopen == 0 ) {
	  return '';
  }

  $hasLimit = array_key_exists('limit', $args);
  // TODO: validate limit

  $submitids = $DB->q('SELECT submitid
                                    FROM submission s
                                    LEFT JOIN team t ON (s.teamid = t.login)
                                    WHERE judgemark IS NULL AND cid = %i
                                    AND langid IN (%As) AND probid IN (%As)
                                    AND submittime < %s AND valid = 1
                                    ORDER BY judging_last_started ASC, submittime ASC, submitid ASC'
                                    . ($hasLimit ? ' LIMIT %i' : ' %_'),
                                   $cid, $judgable_lang, $judgable_prob,
                                   $cdata['endtime'],
				   ($hasLimit ? $args['limit'] : -1));

  return $submitids->getTable();
}
$args = array('limit' => 'Get only the first N queued submissions');
$doc = 'Get a list of all queued submission ids.';
$exArgs = array(array('limit' => 10));
if ( IS_JURY ) {
	$api->provideFunction('GET', 'queue', 'queue', $doc, $args, $exArgs);
}

/**
 * Affiliation information
 */
function affiliations($args) {
  global $DB;

  // Construct query
  $query = 'SELECT affilid, name, country FROM team_affiliation WHERE';

  $byCountry = array_key_exists('country', $args);
  $query .= ($byCountry ? ' country = %s' : ' TRUE %_');
  $country = ($byCountry ? $args['country'] : '');

  $query .= ' ORDER BY name';

  // Run query and return result
  $q = $DB->q($query, $country);
  return $q->gettable();
}
$doc = 'Get a list of affiliations, with for each affiliation: affilid, name and country.';
$optArgs = array('country' => 'ISO 3166-1 alpha-3 country code to search for.');
$exArgs = array(array('country' => 'NLD'));
$api->provideFunction('GET', 'affiliations', 'affiliations', $doc, $optArgs, $exArgs);

/**
 * Team information
 */
function teams($args) {
  global $DB;

  // Construct query
  $query = 'SELECT login, name, categoryid as category, affilid as affiliation FROM team WHERE';

  $byCategory = array_key_exists('category', $args);
  $query .= ($byCategory ? ' categoryid = %i' : ' TRUE %_');
  $category = ($byCategory ? $args['category'] : 0);

  $query .= ' AND';

  $byAffil = array_key_exists('affiliation', $args);
  $query .= ($byAffil ? ' affilid = %s' : ' TRUE %_');
  $affiliation = ($byAffil ? $args['affiliation'] : 0);

  // Run query and return result
  $q = $DB->q($query, $category, $affiliation);
  return $q->gettable();
}
$args = array('category' => 'ID of a single category to search for.',
              'affiliation' => 'ID of an affiliation to search for.');
$doc = 'Get a list of teams containing login, name, category and affiliation.';
$exArgs = array(array('category' => 1, 'affiliation' => 'UU'));
$api->provideFunction('GET', 'teams', 'teams',  $doc, $args, $exArgs);


/**
 * Category information
 */
function categories() {
  global $DB;

  $q = $DB->q('SELECT categoryid, name, color, visible FROM team_category ORDER BY sortorder');
  $res = array();
  while($row = $q->next()) {
    $res[] = array('categoryid' => $row['categoryid'],
                   'name' => $row['name'],
                   'color' => $row['color'],
                   'visible' => (bool)$row['visible']);
  }
  return $res;
}
$doc = 'Get a list of all categories.';
$api->provideFunction('GET', 'categories', 'categories', $doc);

/**
 * Language information
 */
function languages() {
  global $DB;

  $q = $DB->q('SELECT langid, name, allow_submit, allow_judge, time_factor FROM language');
  $res = array();
  while($row = $q->next()) {
    $res[] = array('langid' => $row['langid'],
                   'name' => $row['name'],
                   'allow_judge' => (bool)$row['allow_judge'],
                   'allow_submit' => (bool)$row['allow_submit'],
                   'time_factor' => (float)$row['time_factor']);
  }
  return $res;
}
$doc = 'Get a list of all suported programming languages.';
$api->provideFunction('GET', 'languages', 'languages', $doc);

/**
 * Clarification information
 */
function clarifications($args) {
  global $cid, $DB;

  // Find public clarifications, maybe later also provide more info for jury
  $query = 'SELECT clarid, submittime, probid, body FROM clarification WHERE cid = %i AND sender IS NULL AND recipient IS NULL';
  
  $byProblem = array_key_exists('problem', $args);
  $query .= ($byProblem ? ' AND probid = %s' : ' AND TRUE %_');
  $problem = ($byProblem ? $args['problem'] : null);
  
  $q = $DB->q($query, $cid, $problem);
  return $q->getTable();
}
$doc = 'Get a list of all public clarifications.';
$args = array('problem' => 'Search for clarifications about a specific problem.');
$exArgs = array(array('problem' => 'H'));
$api->provideFunction('GET', 'clarifications', 'clarifications', $doc, $args, $exArgs);

/**
 * Judgehosts
 */
function judgehosts($args) {
  global $DB;

  $query = 'SELECT hostname, active, polltime FROM judgehost';

  $byHostname = array_key_exists('hostname', $args);
  $query .= ($byHostname ? ' WHERE hostname = %s' : '%_');
  $hostname = ($byHostname ? $args['hostname'] : null);

  $q = $DB->q($query, $hostname);
  return $q->getTable();
}
$doc = 'Get a list of judgehosts.';
$args = array('hostname' => 'Search only for judgehosts with given hostname.');
$exArgs = array(array('hostname' => 'sparehost'));
if ( IS_JURY ) {
	$api->provideFunction('GET', 'judgehosts', 'judgehosts', $doc, $args, $exArgs);
}
function judgehosts_POST($args) {
  global $DB, $api;

  if ( !isset($args['hostname']) ) {
	  $api->createError("hostname is mandatory");
  }

  $query = 'INSERT IGNORE INTO judgehost (hostname) VALUES(%s)';
  $q = $DB->q($query, $args['hostname']);

  // If there are any unfinished judgings in the queue in my name,
  // they will not be finished. Give them back.
  $res = $DB->q('SELECT judgingid, submitid, cid FROM judging WHERE
                 judgehost = %s AND endtime IS NULL AND valid = 1', $args['hostname']);
  $ret = $res->getTable();
  $res = $DB->q('SELECT judgingid, submitid, cid FROM judging WHERE
                 judgehost = %s AND endtime IS NULL AND valid = 1', $args['hostname']);
  while ( $jud = $res->next() ) {
          $DB->q('UPDATE judging SET valid = 0 WHERE judgingid = %i',
                 $jud['judgingid']);
          $DB->q('UPDATE submission SET judgehost = NULL, judgemark = NULL
                  WHERE submitid = %i', $jud['submitid']);
  }

  return $ret;
}
$doc = 'Add a new judgehost to the list of judgehosts. Also restarts (and returns) unfinished judgings.';
$args = array('hostname' => 'Add this specific judgehost and activate it.');
$exArgs = array(array('hostname' => 'judge007'));
if ( IS_JURY ) {
	$api->provideFunction('POST', 'judgehosts', 'judgehosts_POST', $doc, $args, $exArgs);
}
function judgehosts_PUT($args)  {
  global $DB, $api;

  if ( !isset($args['__primary_key']) ) {
	  $api->createError("hostname is mandatory");
  }
  $hostname = $args['__primary_key'];
  if ( !isset($args['active']) ) {
	  $api->createError("active is mandatory");
  }
  $active = $args['active'];

  $DB->q('UPDATE judgehost SET active=%i WHERE hostname=%s', $active, $hostname);

  return judgehosts(array('hostname' => $hostname));
}
$doc = 'Update the configuration of a judgehost.';
$args = array('active' => 'Activate judgehost?');
$exArgs = array();
if ( IS_JURY ) {
	$api->provideFunction('PUT', 'judgehosts', 'judgehosts_PUT', $doc, $args, $exArgs);
}


/**
 * Judgeinfo
 */
function judgeinfo($args) {
  global $DB;

  // get maximum runtime and other parameters
  $query = 'TUPLE SELECT CEILING(time_factor*timelimit) AS maxruntime,
  	       s.submitid, s.langid, s.teamid, s.probid,
  	       p.special_run, p.special_compare
  	       FROM submission s, problem p, language l
  	       WHERE s.probid = p.probid AND s.langid = l.langid';

  $byJudgehost = array_key_exists('judgehost', $args);
  $query .= ($byJudgehost ? ' AND judgehost = %s' : '%_');
  $judgehost = ($byJudgehost ? $args['judgehost'] : null);

  $byJudgemark = array_key_exists('judgemark', $args);
  $query .= ($byJudgemark ? ' AND judgemark = %s' : '%_');
  $judgemark = ($byJudgemark ? $args['judgemark'] : null);

  // only return the first submission
  $query .= ' LIMIT 1';

  $q = $DB->q($query, $judgehost, $judgemark);
  return $q;
}
$doc = 'Get most relevant info for a *single* submission/judging.';
$args = array('judgehost' => 'Search only for submissions that are judged by a given hostname.',
		'judgemark' => 'Search only for submissions with a given judgemark.');
$exArgs = array(array('judgehost' => 'sparehost'));
if ( IS_JURY ) {
	$api->provideFunction('GET', 'judgeinfo', 'judgeinfo', $doc, $args, $exArgs);
}

/**
 * Scoreboard (not finished yet)
 */
function scoreboard($args) {
  global $cdata;
  $filter = array();
  if(array_key_exists('category', $args))
    $filter['categoryid'] = array($args['category']);
  if(array_key_exists('country', $args))
    $filter['country'] = array($args['country']);
  if(array_key_exists('affiliation', $args))
    $filter['affilid'] = array($args['affiliation']);
  // TODO: refine this output, maybe add separate function to get summary
  $scores = genScoreBoard($cdata, FALSE, $filter);
  return $scores['matrix'];
}

$args = array('category' => 'ID of a single category to search for.',
              'affiliation' => 'ID of an affiliation to search for.',
              'country' => 'ISO 3166-1 alpha-3 country code to search for.');
$doc = 'Get the scoreboard. Should give the same information as public/jury scoreboards, i.e. after freeze the public one is not updated.';
$exArgs = array(array('category' => 1, 'affiliation' => 'UU'), array('country' => 'NLD'));
$api->provideFunction('GET', 'scoreboard', 'scoreboard', $doc, $args, $exArgs);

// Now provide the api, which will handle the request
$api->provideApi();