<?php
	
	// database connection
	$db = mysqli_connect('localhost','root','admin','premier');

	// POST data
	$target_date = '09-13';
	$target_home_pos = 7;
	$target_away_pos = 4;
	
	// fuzz factor
	$date_fuzz = 5;
	$position_fuzz = 1;
	
	// years to look at
	$years = array('2004','2005','2006','2007','2008','2009','2010','2011','2012','2013');
	
	// final results init
	$relevant_results = array();
	$home_wins = 0;
	$away_wins = 0;
	$draws = 0;
	
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

	
	// loop through each year
	foreach ($years as $current_year){
		// TODO if target date is in new year, current year has to be adjusted
		
		// get year and day x days before, x days after, where x = date_fuzz
		$start_range = $current_year . '-' . date('m-d',strtotime('-' . $date_fuzz . ' days',strtotime($current_year . '-' . $target_date)));
		$end_range = $current_year . '-' . date('m-d',strtotime('+' . $date_fuzz . ' days',strtotime($current_year . '-' . $target_date)));
		
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
			echo 'no games for ' . $current_year . '<br>';
		}
	}
	printf('home wins : %s<br>away wins : %s<br>draws : %s',$home_wins,$away_wins,$draws);
?>
