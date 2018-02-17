<?php

namespace Logit\Http\Controllers;

use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Carbon;
use DB;
use Logit\User;
use Logit\Workout;
use Logit\Settings;
use Logit\NewMessage;
use Logit\WorkoutJunction;
use Logit\RoutineJunction;
use Logit\Exception\Handler;
use Logit\Classes\LogitFunctions;

class DashboardController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('timezone');
    }

    /**
     * Show the application dashboard.
     *
     * @return \Illuminate\Http\Response
     */
    public function dashboard()
    {
        
        $brukerinfo = Auth::user();
        $firstTime = false;
        $newMessage = NewMessage::where([
                ['user_id', $brukerinfo->id],
                ['is_new', 1]
            ])->first();

        /* Checks if this is the users first time visiting */
        if ($brukerinfo->first_time === 1) {
            /* Setting some standard settings */
            Settings::create([
                'user_id'        => Auth::id(),
                'timezone'       => 'UTC',
                'unit'           => 'Metric',
                'recap'          => 1,
                'share_workouts' => 0,
                'accept_friends' => 0,
            ]);

            /* Letting the user know about some stuff */
            $firstTime = true;
            $brukerinfo->update(['first_time'=> 0]);
        }

        $settings = Settings::where('user_id', $brukerinfo->id)->first();

        if ($settings->count_warmup_in_stats == 1) {
            $exercises = RoutineJunction::where('user_id', $brukerinfo->id)
                ->orderBy('exercise_name', 'ASC')
                ->get()
                ->unique('exercise_name');
        } else {
            $exercises = RoutineJunction::where([
                    'user_id' => $brukerinfo->id,
                    'is_warmup' => 0,
                ])
                ->orderBy('exercise_name', 'ASC')
                ->get()
                ->unique('exercise_name');
        }

        $topNav = [
            0 => [
                'url'  => '/',
                'name' => 'Dashboard'
            ]
        ];
            
        return view('dashboard', [
            'topNav'          => $topNav,
            'brukerinfo'      => $brukerinfo,
            'exercises'       => $exercises,
            'firstTime'       => $firstTime,
            'newMessage'      => $newMessage,
        ]);
    }

    /**
     * Gets the workout activity for the linechart
     *
     * @param  string $type specifies year or month
     * @param  int $year specifies the year
     * @param  int $month specifies the mont
     * @return \Illuminate\Http\Response
     */
    public function getTotalWorkouts ($type, $year, $month)
    {

        if ($type === "year") {
            $data = Workout::where('user_id', Auth::id())
                ->where( DB::raw('YEAR(created_at)'), '=', date($year) )
                ->get();

            $getMonth = LogitFunctions::parseDate($type, $year, $month);

            $result = array(
                'labels' => [
                    'Jan',
                    'Feb',
                    'Mar',
                    'Apr',
                    'May',
                    'Jun',
                    'Jul',
                    'Aug',
                    'Sep',
                    'Oct',
                    'Nov',
                    'Dec'
                ],
                'series' => [],
                'max' => 0,
                'stepSize' => 5,
            );

            # Populates the series index with months in the year
            for ($i=0; $i < 12; $i++) { 
                array_push($result['series'], 0);
            }

            # Iterates over our results and pushes the data into our array
            for ($i=0; $i < count($data); $i++) {
                // Gets the month of the current results and formats in the format specified in our getMonth array so we can match the results
                $month = $data[$i]->created_at->format('M');
                // Populates the series array. Using getMonth to get the correct index for the month
                $result['series'][$getMonth[$month]] = $result['series'][$getMonth[$month]] + 1;
            }

            # Finds the max value and appends 1 (for cosmetic reason)
            $result['max'] = max($result['series']) + 1;
        } 
        elseif ($type == "months") {
            # Makes sure the month we get from out request is formated correctly
            $selectedMonth = ucfirst($month);

            # Sets up the expected dataformat
            $monthData = LogitFunctions::parseDate($type, $year, $month);

            # Initialized our output
            $result = array(
                'labels' => [],
                'series' => [],
                'meta' => [],
                'max' => 0,
                'stepSize' => 1,
            );

            # Populates the outputArray with data specific for the specific month
            for ($i=1; $i <= $monthData[$selectedMonth]['days']; $i++) { 
                array_push($result['labels'], $i);
                array_push($result['series'], 0);
                array_push($result['meta'], "");
            }

            # Grabs the data relevant
            $data = Workout::where('workouts.user_id', Auth::id())
                ->where(DB::raw('MONTH(workouts.created_at)'), '=', date($monthData[$selectedMonth]['int']))
                ->where(DB::raw('YEAR(workouts.created_at)'), '=', date($year))
                ->join('routines', 'workouts.routine_id', '=', 'routines.id')
                ->orderBy('workouts.created_at', 'ASC')
                ->select('workouts.created_at', 'routines.routine_name')
                ->get();
            # Iterates over the result
            foreach ($data as $value) {
                $day = $value->created_at->format('d');
                
                # Removes a zero in front of the int. 04 becomes 4 and so on. This is so we can corretctly match indexes in our result array
                if ($day > 0 && $day < 10) {
                    $day = ltrim($day, 0);
                }

                # Subtracts 1 on the index for day, as this is naturally offset by this amount. Index starts at 0, day starts at 1
                $result['series'][(int)$day - 1] = $result['series'][(int)$day - 1] + 1;

                $string = $value->routine_name;
                if ($result['meta'][(int)$day - 1] != "") {
                    $comma = ", ";
                    $string = $result['meta'][(int)$day - 1] .= $comma .= $string;
                }

                $result['meta'][(int)$day - 1] = $string;
            }

            # Finds the max value and appends 1 (for cosmetic reason)
            $max = 0;
            foreach ($result['series'] as $value) {
                if ($value > $max - 1) {
                    $max = $value + 0.1;
                }  
            }

            $result['max'] = $max;
        }

        # Returns the result as an json array
        return $result;
    }

    /**
     * Gets the average workout time
     *
     * @param  string $type specifies year or month
     * @param  int $year specifies the year
     * @param  int $month specifies the mont
     * @return \Illuminate\Http\Response
     */
    public function getAvgGymTime ($type, $year, $month)
    {   

        if ($type == "year") {
            # Grabs the data for the specified year
            $data = Workout::where('user_id', Auth::id())
                ->where(DB::raw('YEAR(created_at)'), '=', date($year))
                ->where('duration_minutes', '>', 10)
                ->avg('duration_minutes');

            # Finds hours by dividing my 60 and flooring the results
            $hours = floor($data / 60);
            if ($hours < 10) {
                # Formats the results. Any hours below 10 will have a zero appended in front. So 03, not 3. Looks better
                $hours = sprintf("%02d", $hours);
            }

            # Gets minutes
            $minutes = ($data % 60);
            if ($minutes < 10) {
                # Formats the results. Any hours below 10 will have a zero appended in front. So 03, not 3. Looks better
                $minutes = sprintf("%02d", $minutes);
            }
        } 
        elseif ($type == "months") {
            $selectedMonth = ucfirst($month);
            $isLeapYear = false;

            $monthData = LogitFunctions::parseDate($type, $year, $month);
            
            $data = Workout::where('user_id', Auth::id())
                ->where(DB::raw('MONTH(created_at)'), '=', date($monthData[$selectedMonth]['int']))
                ->where(DB::raw('YEAR(created_at)'), '=', date($year))
                ->where('duration_minutes', '>', 10)
                ->avg('duration_minutes');

            $hours = floor($data / 60);
            if ($hours < 10) {
                $hours = sprintf("%02d", $hours);
            }

            $minutes = ($data % 60);
            if ($minutes < 10) {
                $minutes = sprintf("%02d", $minutes);
            }
        }

        return response()->json(
            array(
                'avg_hr' => $hours,
                'avg_min' => $minutes
                )
            );
    }

    /**
     * Gets the percentage of musclegroups worked out in a specific timeframe
     *
     * @param  string $type specifies year or month
     * @param  int $year specifies the year
     * @param  int $month specifies the mont
     * @return \Illuminate\Http\Response
     */
    public function getMusclegroups ($type, $year, $month)
    {
        $settings = Settings::where('user_id', Auth::id())->first();

        $musclegroups = [
            'back' => 0, 
            'biceps' => 0, 
            'triceps' => 0, 
            'forearms' => 0,
            'abs' => 0, 
            'shoulders' => 0, 
            'legs' => 0, 
            'chest' => 0
        ];

        $result = array(
            'labels' => [
                'back',
                'biceps',
                'triceps',
                'forearms',
                'abs',
                'shoulders',
                'legs',
                'chest',
            ],
            'series' => [
                0,  // back
                0,  // biceps
                0,  // triceps
                0,  // forearms
                0,  // abs
                0,  // shoulders
                0,  // legs
                0   // chest
            ],
        );

        if ($type == "year") {
            if ($settings->count_warmup_in_stats == 1) {

                $data = WorkoutJunction::where([
                        ['workout_junctions.user_id', Auth::id()],
                        [DB::raw('YEAR(workout_junctions.created_at)'), '=', date($year)],
                        ['workout_junctions.set_nr', '=', 1], // Only count the first set of the exercise!
                    ])
                    ->leftJoin('routine_junctions', 'workout_junctions.exercise_name', '=', 'routine_junctions.exercise_name')
                    ->select('workout_junctions.id', 'workout_junctions.routine_id', 'workout_junctions.workout_id', 'muscle_group')
                    ->get();

            }
            else {
                $data = WorkoutJunction::where([
                        ['workout_junctions.user_id', Auth::id()],
                        [DB::raw('YEAR(workout_junctions.created_at)'), '=', date($year)],
                        ['workout_junctions.set_nr', '=', 1], // Only count the first set of the exercise!
                        ['routine_junctions.is_warmup', '=', 0] // user does not want to count warmup sets.
                    ])
                    ->leftJoin('routine_junctions', 'workout_junctions.exercise_name', '=', 'routine_junctions.exercise_name')
                    ->select('workout_junctions.id', 'workout_junctions.routine_id', 'workout_junctions.workout_id', 'muscle_group')
                    ->get();
            }
            $data = $data->unique();

            $total = $data->count();
            foreach ($data as $mg) {
                /*
                 * Iterates throught results and pushes each musclegroup to the array
                 * Takes previous data and appends 1
                 */
                $musclegroups[$mg->muscle_group] = $musclegroups[$mg->muscle_group] + 1;
            }

            /* Iterates throught the total resunts and gets the actual percentage based on $total */
            foreach ($musclegroups as $key => $val) {
                if ($val > 0) {
                    $index = array_search($key,array_keys($musclegroups));
                    $percent = $val / $total * 100;

                    $result['series'][$index] = (int)$percent;
                }
            }
        } 
        elseif ($type == "months") {
            $selectedMonth = ucfirst($month);
            $isLeapYear = false;
            $monthData = LogitFunctions::parseDate($type, $year, $month);

            if ($settings->count_warmup_in_stats == 1) {

                $data = WorkoutJunction::where([
                        ['workout_junctions.user_id', Auth::id()],
                        [DB::raw('MONTH(workout_junctions.created_at)'), '=', date($monthData[$selectedMonth]['int'])],
                        [DB::raw('YEAR(workout_junctions.created_at)'), '=', date($year)],
                        ['workout_junctions.set_nr', '=', 1], // Only count the first set of the exercise!
                    ])
                    ->leftJoin('routine_junctions', 'workout_junctions.exercise_name', '=', 'routine_junctions.exercise_name')
                    ->select('workout_junctions.id', 'workout_junctions.routine_id', 'workout_junctions.workout_id', 'muscle_group')
                    ->get();

            }
            else {
                $data = WorkoutJunction::where([
                        ['workout_junctions.user_id', Auth::id()],
                        [DB::raw('MONTH(workout_junctions.created_at)'), '=', date($monthData[$selectedMonth]['int'])],
                        [DB::raw('YEAR(workout_junctions.created_at)'), '=', date($year)],
                        ['workout_junctions.set_nr', '=', 1], // Only count the first set of the exercise!
                        ['routine_junctions.is_warmup', '=', 0] // user does not want to count warmup sets.
                    ])
                    ->leftJoin('routine_junctions', 'workout_junctions.exercise_name', '=', 'routine_junctions.exercise_name')
                    ->select('workout_junctions.id', 'workout_junctions.routine_id', 'workout_junctions.workout_id', 'muscle_group')
                    ->get();
            }
            $data = $data->unique();

            $total = $data->count();
            foreach ($data as $mg) {
                /*
                 * Iterates throught results and pushes each musclegroup to the array
                 * Takes previous data and appends 1
                 */
                $musclegroups[$mg->muscle_group] = $musclegroups[$mg->muscle_group] + 1;
            }

            /* Iterates throught the total resunts and gets the actual percentage based on $total */
            foreach ($musclegroups as $key => $val) {
                if ($val > 0) {
                    $index = array_search($key,array_keys($musclegroups));
                    $percent = $val / $total * 100;

                    $result['series'][$index] = (int)$percent;
                }
            }
        }

        return $result;
    }

    /**
     * Gets the top ten exercises completed in a specific timeframe
     *
     * @param  string $type specifies year or month
     * @param  int $year specifies the year
     * @param  int $month specifies the mont
     * @return \Illuminate\Http\Response
     */
    public function getTopExercises ($type, $year, $month, Request $request)
    {
        $limit = 10;
        $show_active_exercises = ['routines.active', '>=', 0];
        $settings = Settings::where('user_id', Auth::id())->first();
        $brukerinfo = Auth::user();

        if ($request->limit) {
            $limit = $request->limit;
        }

        if ($request->show_active_exercises == "true") {
            $show_active_exercises = ['routines.active', 1];
        }

        if ($type == "year") {
            if ($settings->count_warmup_in_stats == 1) {
                $where = [
                    ['workout_junctions.user_id', $brukerinfo->id],
                    [DB::raw('YEAR(workout_junctions.created_at)'), '=', date($year)],
                ];
            } 
            else {
                $where = [
                    ['workout_junctions.user_id', $brukerinfo->id],
                    ['is_warmup', 0],
                    [DB::raw('YEAR(workout_junctions.created_at)'), '=', date($year)],
                ];
            }
        }
        else {
            $selectedMonth = ucfirst($month);
            $isLeapYear = false;
            $monthData = LogitFunctions::parseDate($type, $year, $month);

            if ($settings->count_warmup_in_stats == 1) {
                $where = [
                    ['workout_junctions.user_id', $brukerinfo->id],
                    [DB::raw('MONTH(workout_junctions.created_at)'), '=', date($monthData[$selectedMonth]['int'])],
                    [DB::raw('YEAR(workout_junctions.created_at)'), '=', date($year)],
                ];
            }
            else {
                $where = [
                    ['workout_junctions.user_id', $brukerinfo->id],
                    ['is_warmup', 0],
                    [DB::raw('MONTH(workout_junctions.created_at)'), '=', date($monthData[$selectedMonth]['int'])],
                    [DB::raw('YEAR(workout_junctions.created_at)'), '=', date($year)],
                ];
            }
        }

        $topExercises = WorkoutJunction::select(DB::raw('workout_junctions.id, workout_junctions.exercise_name, count(*) as count'))
            ->join('routines', 'workout_junctions.routine_id', '=', 'routines.id')
            ->where($where)
            ->where([$show_active_exercises])
            ->groupBy('exercise_name')
            ->having('count', '>', 1)
            ->orderBy('count', 'DESC')
            ->limit($limit)
            ->get();

        return $topExercises;
    }

    /**
     * User choses one exercise and will be able to see the progress in specified timeframe
     *
     * @param  string $type specifies year or month
     * @param  int $year specifies the year
     * @param  int $month specifies the mont
     * @param  string $exercise name of exercise to compare
     * @return \Illuminate\Http\Response
     */
    public function getExerciseProgress ($type, $year, $month, $exercise)
    {
        return LogitFunctions::fetchExerciseData($type, $month, $year, $exercise, Auth::id());
    }

    /**
     * Gets the total completion ratio for finishes sessions in the specified timeframe
     *
     * @param  string $type specifies year or month
     * @param  int $year specifies the year
     * @param  int $month specifies the mont
     * @return \Illuminate\Http\Response
     */
    public function getCompletionRatio ($type, $year, $month)
    {
        if ($type === "year") {
            $workouts = Workout::where('user_id', Auth::id())
                ->where( DB::raw('YEAR(created_at)'), '=', date($year) )
                ->select('id', 'routine_id')
                ->get();
        }
        else {
            # Makes sure the month we get from out request is formated correctly
            $selectedMonth = ucfirst($month);

            # Sets up the expected dataformat
            $monthData = LogitFunctions::parseDate($type, $year, $month);

            # Gets all sessions completed in timeframe
            $workouts = Workout::where('workouts.user_id', Auth::id())
                ->where(DB::raw('MONTH(workouts.created_at)'), '=', date($monthData[$selectedMonth]['int']))
                ->where(DB::raw('YEAR(workouts.created_at)'), '=', date($year))
                ->select('id', 'routine_id')
                ->get();
        }

        $target = 0;
        $actual = 0;

        foreach ($workouts as $workout) {
            $target += RoutineJunction::where('routine_id', $workout->routine_id)->count();
            $actual += WorkoutJunction::where([
                ['workout_id', $workout->id],
                ['set_nr', 1]
            ])->count();
        }

        if ($target > 0 && $actual > 0) {
            $ratio = floor(($actual / $target) * 100);

            $result = [
                "success" => true,
                "target"  => $target,
                "actual"  => $actual,
                "ratio"   => $ratio,
            ];
        } else {
            $result = [
                "success" => false,
                "msg"     => "No data for this period",
            ];
        }

        

        return $result;
    }
}