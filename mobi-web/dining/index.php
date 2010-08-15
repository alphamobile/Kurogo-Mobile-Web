<?php

require_once LIBDIR . "/harvard_dining.php";
require_once LIBDIR . "/diningHrs.php";

function day_info($time, $offset=0) {
  $time += $offset * 24 * 60 * 60;
  return array(
    "pretty"            => date("D M j", $time),
    "arrows_format"     => date("D F j", $time),
    "time"              => strtotime(date("Y-m-d 12:00:00", $time)),
  );
}

function dayURL($day) {
  return "index.php?time={$day['time']}";
}

$time = isset($_REQUEST['time']) ? $_REQUEST['time'] : time();

$day = date('Y-m-d', $time);

$current = day_info($time);
$next = day_info($time, 1);
$prev = day_info($time, -1);


$food_items = array(
    "breakfast" => DINING_DATA::getDiningData($day, "BRK"),
    "lunch" => DINING_DATA::getDiningData($day, "LUN"),
    "dinner" => DINING_DATA::getDiningData($day, "DIN"),
);

foreach($food_items as $meal => $items) {
    $food_items[$meal] = collectFoodByCategory($items);
}

$hour = intval(date('G'));

if($hour < 12) {
    $current_meal = "breakfast";
} else if ($hour < 15) {
    $current_meal = "lunch";
} else {
    $current_meal = "dinner";
}

$hours = DINING_HOURS::getDiningHours();

require "$page->branch/index.html";

$page->output();


function collectFoodByCategory($items) {
    $food_categories = array();

    foreach($items as $item) {
        // this is a hack for now just to fill in some of the data
        $item["properties"] = array("vegetarian", "local");
        
        if(!array_key_exists($item[category], $food_categories)) {
            $food_categories[$item[category]] = array($item);
        } else {
            $food_categories[$item[category]][] = $item;
        }
    }

    return $food_categories;
}

class DINING_CONSTANTS {
    public static $MEALS = array(
        "breakfast" => array("days" => "Mon,Tue,Wed,Thu,Fri,Sat"),
        "brunch" => array("days" => "Sun"),
        "lunch" => array("days" => "Mon,Tue,Wed,Thu,Fri,Sat"),
        "dinner" => array(),
        "bb" => array("name" => "brain break", "days" => "Sun,Mon,Tue,Wed,Thu"),
    );
}

function todaysMealsHours($dining_hall, $day) {
    $meals_hours = array();
    foreach(DINING_CONSTANTS::$MEALS as $meal => $meal_data) {
        if(isMealToday($meal_data, $day)) {
            $meal_hours = $dining_hall->{$meal . "_hours"};
            if($meal_hours != "NA") {
                $meals_hours[$meal] = $meal_hours;
            }
        }
    }
    return $meals_hours;
}

function diningHallStatuses($dining_halls) {
    $statuses = array();
    $minute_of_the_day = minuteOfTheDay(time());
    $day = date("D");
    foreach ($dining_halls as $dining_hall) {

        // first search for currently open meal
        $open_meal = NULL;
        foreach(todaysMealsHours($dining_hall, $day) as $meal => $meal_hours) {
            if(isMinuteDuringHours($minute_of_the_day, $meal_hours)) {
                $open_meal = $meal;
                break;
            }
        }

        // first search for currently open meal
        $next_meal = NULL;
        foreach(todaysMealsHours($dining_hall, $day) as $meal => $meal_hours) {
            if(isMinuteBeforeHours($minute_of_the_day, $meal_hours)) {
                $next_meal = $meal;
                break;
            }
        }


        $status = array("name" => $dining_hall->name);
        if($open_meal) {
            $status['status'] = "open";
            $status['open_meal'] = $open_meal;
        } else {
            $status['status'] = "closed";
        }
        if($next_meal) {
            $status['next_meal'] = $next_meal;
        }
        
        $statuses[] = $status;
    }

    return $statuses;
}

function isMealToday($meal, $day) {
    if(isset($meal["days"])) {
        // check id $day is list in $meal['days']
        return strpos($meal["days"], $day) !== false;
    } else {
        // if we dont have a days field, that means
        // the meal is every day
        return true;
    }
}

function isMinuteDuringHours($minute, $meal_hours) {
    $limits = stringToStartEndLimits($meal_hours);

    if(isset($limits["end"])) {
        return ($minute >= $limits["start"]) && ($minute < $limits["end"]);
    } else {
        return ($minute >= $limits["start"]);
    }
}

function isMinuteBeforeHours($minute, $meal_hours) {
    $limits = stringToStartEndLimits($meal_hours);
    return ($minute < $limits["start"]);
}

function stringToStartEndLimits($meal_hours) {
    // look for $meal_hours formatted as "starting 10:00pm"
    if(strpos($meal_hours, "starting") === 0) {
        $parts = split(" ", $meal_hours);
        return array("start" => stringToMinutes($parts[1]));
    }

    // other possible formats "Noon-2:15pm", "11:30am-2:15pm", "7:30-10:00am"
    $parts = split("-", $meal_hours);
    $start = $parts[0];
    $end = $parts[1];

    // parse second part (because its format is more standard
    // and info from the second part is used to parse the first part)
    $end_total_minutes = stringToMinutes($end);
    $start_total_minutes = stringToMinutes($start, $end_total_minutes);
    return array("start" => $start_total_minutes, "end" => $end_total_minutes);

}

/*
 * if $time_string does not specify "am" or "pm",
 * use the fact that $time_string has to be before $before_minute.
 * specifically assume $time_string corresponds to the latest time
 * that is still before $before_minute
 */
function stringToMinutes($time_string, $before_minute=NULL) {
    if($time_string == "Noon") {
        return 12 * 60;
    }


    preg_match('/(\d+)\:(\d+)(am|pm)?/', $time_string, $matches);
    $hour = intval($matches[1]);
    $minute = intval($matches[2]);

    $total_minutes = $hour * 60 + $minute;

    // check if am or pm is in $time_string
    if(count($matches) > 3) {
        $am_or_pm = $matches[3];
        if($am_or_pm == "pm") {
            $total_minutes += 12*60;
        }
    } else {
        // am or pm not specified, so we first try pm
        // then try am constrained by $before_minute
        // we try pm first (because we are trying to
        // minimize the difference between $time_string and $before_minute
        if($total_minutes+12*60 < $before_minute) {
            $total_minutes += 12*60;
        }
    }
    return $total_minutes;
}


function minuteOfTheDay($time) {
    return intval(date("G", $time)) * 60 + intval(date("i", $time));
}
?>
