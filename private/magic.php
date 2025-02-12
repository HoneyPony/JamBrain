<?php
const CONFIG_PATH = "../src/shrub";
const SHRUB_PATH = "../src/shrub/src";

include_once __DIR__."/".CONFIG_PATH."/config.php";
require_once __DIR__."/".SHRUB_PATH."/cron.php";
require_once __DIR__."/".SHRUB_PATH."/node/node.php";
require_once __DIR__."/".SHRUB_PATH."/comment/comment.php";
require_once __DIR__."/".SHRUB_PATH."/grade/grade.php";

// This is a CRON job that regularly updates magic
const MAX_ITEMS_TO_ADD = 500;
const MAX_ITEMS_TO_CALC = 500;

// TODO: Adjust the maximum effectiveness as the weeks go by. Start with like 50 initially (more than enough), but let it go up after.
const FEEDBACK_PER_COMMENT = 2.0;

const COOL_MIN_POINTS = 1;		// In the future, set this to 3 or 4
const COOL_MIN_GRADES = 1;		// In the future, set this to 3 or 4
const COOL_MIN_FEEDBACK = 1;	// In the future, set this to 3 or 4
const COOL_MAX_GRADES = 15;//50;
const COOL_MAX_FEEDBACK = 50;

const CLASSIC_MAX_GRADES = 100;


function AddMagic( $name, $parent, $initial_value = 0 ) {
	global $node_ids, $db;
	$magic_ids = nodeMagic_GetNodeIdByParentName($parent, $name);

	$diff = array_diff($node_ids, $magic_ids);
	$new_nodes = array_slice($diff, 0, MAX_ITEMS_TO_ADD);

	if ( count($new_nodes) ) {
		$nodes = node_IdToIndex(node_GetById($new_nodes));

		$db->begin_transaction();
		foreach ( $new_nodes as $key => &$value ) {
			$node = &$nodes[$value];
			if ( $node ) {
				nodeMagic_Add(
					$node['id'],
					$node['parent'],
					$node['_superparent'],
					$node['author'],
					$name,
					$initial_value
				);
			}
		}
		$db->commit();
	}
}

// Get the root node
$root = nodeComplete_GetById(1);

// Get current featured event
$featured_id = null;
if ( isset($root['meta']['featured']) )
	$featured_id = $root['meta']['featured']|0;

// As long as an event is featured, do coolness calculation
if ( $featured_id ) {
	$featured = nodeComplete_GetById($featured_id);
	if ( $featured && isset($featured['id']) && $featured['id'] == $featured_id ) {
		$grades = [];
		$grades_out = [];	// optimization
		$event_start = null;
		foreach ( $featured['meta'] as $key => &$value ) {
			if ( strpos($key, 'grade-') === 0 ) {
				if ( count(explode('-', $key)) == 2 ) {
					$grades[] = $key;
					$grades_out[] = $key.'-out';
				}
			}
			else if ( $key == 'event-start' ) {
				$event_start = new DateTime($featured['meta'][$key]);
			}
		}

		// initialize to max grades
		$cool_max_grades = COOL_MAX_GRADES;

		// If start time is set
		if ( $event_start ) {
			// use the number of days since the start as the max grade
			$event_diff = $event_start->diff(new DateTime("now"));
			$cool_max_grades = intval($event_diff->format('%a'));

			// Clamp to max grade
			if ( $cool_max_grades > COOL_MAX_GRADES ) {
				$cool_max_grades = COOL_MAX_GRADES;
			}
		}

		//echo($event_start->format('Y-m-d H:i:s'));
		//echo("\n");
		//echo($event_diff->format('%a'));
		//echo("\n");
		//echo("dood:".$cool_max_grades."\n");

		// ** Find items that don't have magic **
		{
			// Get all published item ids
			$node_ids = node_GetIdByParentTypePublished($featured_id, 'item');

			AddMagic('grade', $featured_id);
			AddMagic('given', $featured_id);
			AddMagic('feedback', $featured_id);
			AddMagic('smart', $featured_id);
			AddMagic('cool', $featured_id);
		}


		// ** Find a bunch of the oldest games with cool magic **
		{
			$magics = nodeMagic_GetOldestByParentName($featured_id, 'cool', MAX_ITEMS_TO_CALC);

			$node_ids = array_map(function($value) { return $value['node']; }, $magics);
			// Bail if no nodes
			if ( !count($node_ids) )
				exit();
			$nodes = node_IdToIndex(nodeComplete_GetById($node_ids, F_NODE_NO_BODY | F_NODE_META | F_NODE_LINK|F_NODE_NO_LINKVALUE));

			$scores = [];

			// Calculate their scores
			foreach ( $magics as &$magic ) {
				// The old Formula
				//
				//	function compo2_calc_coolness( $votes, $total ) {
				//  	$votes = max(0, min(100, $votes));
				//		$total = max(0, min(100, $total-1));
				//		$v = sqrt($votes * 100 / max(1, $total)) * 100 / 10;
				//		return intval(round($v));
				//	}
				//
				// 0, 0 = 0
				// 0, 25 = 0
				// 0, 50 = 0
				// 0, 75 = 0
				// 0, 100 = 0
				//
				// 25, 0 = 500
				// 25, 25 = 100
				// 25, 50 = 70.71
				// 25, 75 = 57.73
				// 25, 100 = 50
				//
				// 50, 0 = 707.1
				// 50, 25 = 141.42
				// 50, 50 = 100
				// 50, 75 = 81.64
				// 50, 100 = 70.71
				//
				// 75, 0 = 866.02
				// 75, 25 = 173.20
				// 75, 50 = 122.47
				// 75, 75 = 100
				// 75, 100 = 86.6
				//
				// 100, 0 = 1000
				// 100, 25 = 200
				// 100, 50 = 141.41
				// 100, 75 = 115.47
				// 100, 100 = 100

				$smart = 0;
				$unbound = 0;
				$cool = 0;

				$team_grades = 0;
				$given_grades = 0;
				$team_feedback = 0;
				$given_feedback = 0;

				$raw_team_grades = 0;
				$raw_given_grades = 0;
				$raw_team_feedback = 0;
				$raw_given_feedback = 0;

				$node = &$nodes[$magic['node']];
				if ( $node ) {
					$authors = $node['meta']['author'];

					$node_grades_out = [];
					foreach ( $node['meta'] as $key => &$value ) {
						if ( strpos($key, 'grade-') === 0 && substr($key, strpos($key, '-out')) === '-out' ) {
							if ( count(explode('-', $key)) == 3 ) {
								$node_grades_out[] = $key;
							}
						}
					}
					$team_grade_value = count($grades);
					$given_grade_value = count(array_diff($grades_out, $node_grades_out));

					// ** Calculate Grades **************************************
					$raw_team_grades = grade_CountByNotNodeAuthor($node['id'], $authors, $featured_id);
					$raw_given_grades = grade_CountByNodeNotAuthor($node['id'], $authors);

					$team_grades = $raw_team_grades / $team_grade_value;
					$given_grades = $raw_given_grades / $given_grade_value;

					// Given grade is unbound, so inactive participants will still get seen and get a few grades
					$bound_grade = (sqrt(min($cool_max_grades, max(COOL_MIN_GRADES, $team_grades)) * 100.0 / max(1.0, $given_grades)) * 100.0 / 10.0) - 100.0;
					$unbound_grade = (sqrt(max(COOL_MIN_GRADES, $team_grades) * 100.0 / max(1.0, $given_grades)) * 100.0 / 10.0) - 100.0;

					// ** Classic Grade Algorithm **************************************
					// (TODO: Double check that it even needs '/ $team_grade_value')
					$classic_team_grades = max(0, min(CLASSIC_MAX_GRADES, $raw_team_grades / $team_grade_value));
					$classic_given_grades = max(0, min(CLASSIC_MAX_GRADES, ($raw_given_grades / $team_grade_value) - 1));	// Magic "-1"
					$classic_grade = sqrt($classic_team_grades * 100.0 / max(1.0, $classic_given_grades)) * 100.0 / 10.0;

					// ** Calculate Feedback Score **************************************
					$raw_team_feedback = commentLove_CountBySuperNotNodeAuthorKnownNotAuthor($node['parent'], $node['id'], $authors);
					$raw_given_feedback = commentLove_CountBySuperNodeNotAuthorKnownNotAuthor($node['parent'], $node['id'], $authors);

					$team_feedback = $raw_team_feedback / FEEDBACK_PER_COMMENT;
					$given_feedback = $raw_given_feedback / FEEDBACK_PER_COMMENT;

					// Unlke grade, given feedback is bound, so excessive feedback doesn't accidentially drown-out the grades
					$bound_feedback = (sqrt(min(COOL_MAX_FEEDBACK, max(COOL_MIN_FEEDBACK, $team_feedback)) * 100.0 / min(COOL_MAX_FEEDBACK, max(1.0, $given_feedback))) * 100.0 / 10.0) - 100.0;
					$unbound_feedback = (sqrt(max(COOL_MIN_FEEDBACK, $team_feedback) * 100.0 / max(1.0, $given_feedback)) * 100.0 / 10.0) - 100.0;


					// Final
					//$smart = $bound_grade + $bound_feedback;				// bound, so it will hit upper limits
					//$unbound = $unbound_grade + $unbound_feedback;			// unbound

//					$smart_for = max(COOL_MIN_POINTS, (min($cool_max_grades, $team_grades) + min(COOL_MAX_FEEDBACK, $team_feedback));
//					$smart_against = max(1.0, (min($cool_max_grades, $given_grades) + min(COOL_MAX_FEEDBACK, $given_feedback));

					// Bound everything except given grades, so a game can score below a game with no votes
					$smart_for = max(COOL_MIN_POINTS, (min($cool_max_grades, $team_grades) + min(COOL_MAX_FEEDBACK, $team_feedback)));
					
					// Only count received grades against your smart score, because the feedback-related score
					// is extremely random (it is possible to receive >30 points against you for a single comment
					// left on your game), and subject to anonymous abuse, while received grades are mostly
					// difficult to abuse (anonymously or not), and correlate much more consistently with
					// factors like "the number of plays"
					$smart_against = max(1.0, $given_grades);
					$smart = (sqrt($smart_for * 100.0 / $smart_against) * 100.0 / 10.0) - 100.0;

					$cool = $classic_grade;									// ratings only, old algorithm
				}

				// Prefer $magic['node'] to $node['id'] in case it fails to load
				$scores[] = [
					'node' => $magic['node'],
					'smart' => $smart,					// Smart Balance
					'cool' => $cool,					// Classic 'coolness' Balance
					'grade' => $given_grades,			// How many grades received (Rescue Rangers)
					'given' => $team_grades,			// How many grades the team has given
					'feedback' => $raw_team_feedback	// Karma on feedback team has given (i.e. people who are working hard)
				];
			}

			// Update scores
			$db->begin_transaction();
			foreach ( $scores as &$sc ) {
				nodeMagic_Update($sc['node'], 'smart', $sc['smart']);
				nodeMagic_Update($sc['node'], 'cool', $sc['cool']);
				nodeMagic_Update($sc['node'], 'grade', $sc['grade']);
				nodeMagic_Update($sc['node'], 'given', $sc['given']);
				nodeMagic_Update($sc['node'], 'feedback', $sc['feedback']);
			}
			$db->commit();
		}
	}

	// Invalidate cached object that selects random games based on smart ratings.
	nodeRandomGames_InvalidateCache();
}
