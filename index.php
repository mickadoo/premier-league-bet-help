<form method = "GET">
	<label for "start">Start: (d.m.Y)</label>
	<input type = "date" name = "start">
	<label for "end">End: (d.m.Y)</label>
	<input type = "date" name = "end">
	<input type = "submit" value = "process">
</form>

<?php
	if (isset($_GET['start']) && isset($_GET['end'])){
		// range of fixutes to look at in format dd.mm.YYYY
		$start_date = date('d.m.Y', strtotime($_GET['start']));
		$end_date = date('d.m.Y', strtotime($_GET['end']));
	} else {
		exit;
	}
	
    include 'settings/settings.php';

	// returns just the month and day formatted for mysql query
	function getDateForMysql($date){
		return date("m-d",strtotime($date));
	}	
	
	// returns fixtures for data range
	function getFixturesForRange($start_date,$end_date){
		$request = file_get_contents('http://football-api.com/api/?Action=fixtures&&APIKey=' . API_KEY . '&comp_id=' . LEAGUE_ID . '&&from_date=' . $start_date . '&&to_date=' . $end_date);
		$result = json_decode($request,TRUE);
		$fixtures = array();
		if (isset($result['ERROR']) && $result['ERROR'] != "OK"){
			echo $result['ERROR'];
			exit;
		}
		foreach ($result['matches'] as $current_match){
			$key = $current_match['match_id'];
			$fixtures[$key]['home_team_id'] = $current_match['match_localteam_id'];
			$fixtures[$key]['home_team_name'] = $current_match['match_localteam_name'];
			$fixtures[$key]['away_team_id'] = $current_match['match_visitorteam_id'];
			$fixtures[$key]['away_team_name'] = $current_match['match_visitorteam_name'];
			$fixtures[$key]['date'] = $current_match['match_date'];
		}		
		return $fixtures;
	}
	
	// returns array of teams, position and form
	function getLeagueData(){
		$request = file_get_contents('http://football-api.com/api/?Action=standings&&APIKey=' . API_KEY . '&&comp_id=' . LEAGUE_ID);
		$result = json_decode($request,TRUE);
		if (isset($result['ERROR']) && $result['ERROR'] != "OK"){
			echo $result['ERROR'];
			exit;
		}
		$team_data = array();
		foreach($result['teams'] as $current_team){
			$key = $current_team['stand_team_id'];
			$team_data[$key]['name'] = $current_team['stand_team_name'];
			$team_data[$key]['position'] = $current_team['stand_position'];
			$team_data[$key]['form'] = $current_team['stand_recent_form'];
		}
		return $team_data;
	}
	
	// evaluate form and return number to represent form strength
	function getFormValue($home_team_form,$away_team_form){
		
		$result_string_to_points = array('W'=>3,'D'=>1,'L'=>0);
		
		$home_team_form = str_split($home_team_form);
		$away_team_form = str_split($away_team_form);
		$home_total= 0;
		$away_total = 0;
		foreach($home_team_form as $current){			
			$home_total += $result_string_to_points[$current];
		}
		foreach($away_team_form as $current){
			$away_total += $result_string_to_points[$current];
		}
		// return positive or minus form value as percent
		return (($home_total - $away_total) / 15) * 100;			
	}
		
	// returns position of key in array
	function getPositionInArray($array,$key){
		$i = 0;
		foreach ($array as $current_key => $current_val){
			if ($current_key == $key){
				return $i;
			} else {
				$i++;
			}
		}
		return 0;
	}
		
	// evaluate past similar results to product past home team strength
	function getPastValue($target_home_pos,$target_away_pos,$date,$date_fuzz = 7, $position_fuzz = 1){
		// final results init
		$years = range(2004,2013);
		$relevant_results = array();
		$home_wins = 0;
		$away_wins = 0;
		$draws = 0;
		$target_date = $date;
		// database connection
		$db = mysqli_connect('localhost','root','admin','premier');
		
		foreach ($years as $current_year){
			// if target date is in new year, current year has to be adjusted
			$target_month = intval(substr($date,0,2));
			$target_year = $current_year;
			if ($target_month < 6){
				$target_year = $current_year + 1;
			}

			// get year and day x days before, x days after, where x = date_fuzz
			$start_range = $target_year . '-' . date('m-d',strtotime('-' . $date_fuzz . ' days',strtotime($target_year . '-' . $target_date)));
			$end_range = $target_year . '-' . date('m-d',strtotime('+' . $date_fuzz . ' days',strtotime($target_year . '-' . $target_date)));
			
			// check if end before start (in case end of year)
			if (strtotime($start_range) > strtotime($end_range)){
				$target_year--;
				$start_range = $target_year . '-' . date('m-d',strtotime('-' . $date_fuzz . ' days',strtotime($target_year . '-' . $target_date)));
			}
			
			// sql for selecting dates with games between date range
			$select_games_in_range_sql = "SELECT DISTINCT `Date` FROM `results` WHERE Date BETWEEN '" . $start_range . "' AND '" . $end_range . "'";
			$res = $db->query($select_games_in_range_sql);
			$game_dates = array();
			while ($row = $res->fetch_array(MYSQLI_NUM)){
				$game_dates[] = $row[0];
			}
			
			// loop through each date if games exist
			if ($game_dates){
				
				foreach ($game_dates as $current_matchday){
							
					// calculate team position on that day
					
					// sql to get team names for a season
					$get_team_names_sql = "SELECT DISTINCT `HomeTeam` FROM `results` WHERE Date BETWEEN '" . $current_year . "-08-01' AND '" . ($current_year + 1) . "-06-31'";
					$res = $db->query($get_team_names_sql);		
					// make array of teams for season
					$team_names = array();
					while ($row = $res->fetch_array(MYSQLI_NUM)){
						$team_names[$row[0]] = 0;
					}				
					
					// get games up to that point
					$games_up_to_date_sql = "SELECT * FROM results WHERE Date BETWEEN '" . $current_year . "-08-01' AND '" .  $current_matchday . "'";
					$res = $db->query($games_up_to_date_sql);
					// loop through games to assign points
					while ($row = $res->fetch_array(MYSQLI_ASSOC)){
						// if FTR = H increment H teams points by 3
						if ($row['FTR'] == 'H'){
							$team_names[$row['HomeTeam']] += 3;
						}
						// if FTR = A increment A teams points by 3
						if ($row['FTR'] == 'A'){
							$team_names[$row['AwayTeam']] += 3;
						}
						// if FTR = D increment both teams points by 1
						if ($row['FTR'] == 'D'){
							$team_names[$row['HomeTeam']] += 1;
							$team_names[$row['AwayTeam']] += 1;
						}
					}
									
					// sort array by points
					asort($team_names);
					
					// select games on that day
					$games_on_target_day_sql = "SELECT * FROM results WHERE Date = '" . $current_matchday . "'";
					$res = $db->query($games_on_target_day_sql);
					// loop through games to check if any games match target game
					while ($row = $res->fetch_array(MYSQLI_ASSOC)){					
						$home_team_position = 20 - getPositionInArray($team_names,$row['HomeTeam']);					
						$away_team_position = 20 - getPositionInArray($team_names,$row['AwayTeam']);
						// if position of home team is plus or minus 1 of target home team
						if (in_array($target_home_pos,range($home_team_position - $position_fuzz, $home_team_position + $position_fuzz))){
							// AND position of away team is plus or minus 1 of target away team
							if (in_array($target_away_pos,range($away_team_position - $position_fuzz, $away_team_position + $position_fuzz))){
								// store result in array of relevant results
								$row['HomePosition'] = $home_team_position;
								$row['AwayPosition'] = $away_team_position;
								$relevant_results[] = $row;
								
								// print team info
								printf('%s (%s) vs. %s (%s) on %s<br>',$row['HomeTeam'],$home_team_position,$row['AwayTeam'],$away_team_position,$row['Date']);
								
								// print match result
								if ($row['FTR'] == 'H'){
									echo 'Home Win';
									$home_wins++;
								}
								if ($row['FTR'] == 'A'){
									echo 'Away Win';
									$away_wins++;
								}
								if ($row['FTR'] == 'D'){
									echo 'Draw';
									$draws++;
								}
								echo '<br><br>';
							}
							//echo 'Almost relevant';
						}
					}
				}
			} else {
				return 0;
			}
		}
		printf('home wins : %s<br>away wins : %s<br>draws : %s',$home_wins,$away_wins,$draws);
		
		// calculate score
		$num_games = $home_wins + $away_wins;

		$score = $home_wins - $away_wins;
		// possible division by zero
		@$score_percent = $score / max($home_wins,$away_wins) *100;
		
		if ($num_games < 5){
			$weaken_effect = $num_games / 5;
		} else {
			$weaken_effect = 1;
		}
		return $score_percent * $weaken_effect;
	}
	
	function getFormImportance($date){
		$month  = substr($date,3,2);
		$month = intval($month);
		
		$form_importance_array = array(
			9 => 75,
			10 => 70,
			11 => 60,
			12 => 55,
			1 => 50,
			2 => 50,
			3 => 40,
			4 => 30,
			5 => 30			
		);
			
		return $form_importance_array[$month];		
	}
	
	// get up-to-date team data
	$team_data = getLeagueData();
	
	// get fixture data
	$fixtures  = getFixturesForRange($start_date, $end_date);
		
	// loop through fixtures
	foreach ($fixtures as $current_match){
		
		// define to shorten the array references
		$h_id = $current_match['home_team_id'];
		$a_id = $current_match['away_team_id'];
		
		// define array for home team	
		$home_team = array(
			'id' => $h_id,
			'position' => $team_data[$h_id]['position'],
			'name' => $team_data[$h_id]['name'],
			'form_string' => $team_data[$h_id]['form'],
			'form' => 0,
			'past' => 0
		);
		
		// define array for away team
		$away_team = array(
			'id' => $a_id,
			'position' => $team_data[$a_id]['position'],
			'name' => $team_data[$a_id]['name'],
			'form_string' => $team_data[$a_id]['form'],
			'form' => 0,
			'past' => 0
		);
		
		printf('
			<h3>%s (pos:%s form:%s) vs. %s (pos:%s form:%s)</h3>'
			,$home_team['name'],$home_team['position'], $home_team['form_string'],$away_team['name'],$away_team['position'], $away_team['form_string']
		);
		
		// calculate form variable
		$form = getFormValue($home_team['form_string'],$away_team['form_string']);
		
		// get similar past matches		
		$past = getPastValue($home_team['position'],$away_team['position'],getDateForMysql($start_date));
		
		// calculate importance of form based on time
		$form_importance = getFormImportance($start_date);
		$past_imporatance = 100 - $form_importance;
		
		// get final values of form and past based on importance
		$final_form = $form * ($form_importance / 100);
		$final_past = $past * ($past_imporatance / 100);
				
		// final formula
		$home_team_win_percentage = 50 + (($final_form + $final_past) / 2);
		
		printf(
			'<h3>Past : %s<br>Form : %s</h3>
			<h2>%s%% chance of home win</h2>'			
			,$past,$form,$home_team_win_percentage
		);
		
		echo '<br>';
	}

?>
