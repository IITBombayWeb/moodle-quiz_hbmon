<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.
/**
 * This file defines the quiz heartbeatmonitor report class.
 *
 * @package   quiz_hbmon
 * @copyright 2019 IIT Bombay
 * @author	  Kashmira Nagwekar
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot . '/mod/quiz/report/attemptsreport.php');
require_once($CFG->dirroot . '/mod/quiz/report/hbmon/startnode_form.php');
require_once($CFG->dirroot . '/mod/quiz/report/hbmon/stopnode_form.php');

// require_once($CFG->dirroot . '/mod/quiz/report/hbmon/hbmonconfig.php');
require_once($CFG->dirroot . '/mod/quiz/accessrule/heartbeatmonitor/hbmonconfig.php');
// require_once($CFG->dirroot . '/mod/quiz/accessrule/heartbeatmonitor/exec_output.text');
// require_once($CFG->dirroot . '/mod/quiz/accessrule/heartbeatmonitor/exec_pid.text');
// require_once($CFG->dirroot . '/mod/quiz/accessrule/heartbeatmonitor/server.js');

/**
 * Quiz report subclass for the heartbeat monitoring report.
 *
 * This report allows you to ...
 *
 * @copyright 1999 onwards Martin Dougiamas and others {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quiz_hbmon_report extends quiz_attempts_report {

    public function display($quiz, $cm, $course) {
        global $OUTPUT, $DB, $PAGE;
        $PAGE->requires->jquery();
        $output = $PAGE->get_renderer('mod_quiz');

        $this->quiz = $quiz;
        $this->cm = $cm;
        $this->course = $course;
        $context = context_module::instance($cm->id);
        $this->context = $context;
        $this->mode = 'hbmon';

        // Start output.
        $this->print_header_and_tabs($cm, $course, $quiz, 'hbmon');
        $quizobj = quiz::create($quiz->id);
        if (empty($quizobj->get_quiz()->hbmonrequired)) {
            echo $OUTPUT->notification('Heartbeat monitoring is not on for this quiz.<br>Please enable the corresponding quiz setting.<br>', 'danger');
            return false;
        }
        $this->display_index($quiz, $cm, $course);
        $baseurl = $this->get_base_url();

        $result = "<script>
                    function autorefreshpage() {
                        $(document).ready(function() {
                            var interval = setInterval(function() {
                                window.location = window.location.href;
//                                 window.location = $baseurl;
                            }, 120000);
                        });
                    }
                </script>";
        $result .= "<script type='text/javascript'>autorefreshpage();</script>";
        echo "<br>baseurl : " . $baseurl;

        $messages[0] = $result;
        $output1 = '';
        $output1 .= html_writer::tag('p', $messages[0]);
        echo $output1;

        return true;
    }

    protected function display_index($quiz, $cm, $course) {
        global $OUTPUT, $PAGE, $DB, $CFG, $HBCFG;
        $context = context_module::instance($cm->id);

        // Check the user has the required capabilities to access this plugin.
        require_capability('mod/quiz:manage', $context);

        $quizid = $quiz->id;
        $courseid = $course->id;
        $cmid = $cm->id;

        // Display live users.
        // Fetch records from database.
        $sql = 'SELECT * FROM {quizaccess_hbmon_livetable} ORDER BY status, roomid';  // Todo: Select data for a particular quiz and not entire table. Insert quizid col in livetable for this.
        $arr = array();
        $roomid = null;

        $table = new html_table();
        $table->id = get_string('liveusers', 'quizaccess_heartbeatmonitor');
        $table->caption = get_string('usersattemptingquiz', 'quizaccess_heartbeatmonitor');
        $table->head = array('', get_string('user', 'quizaccess_heartbeatmonitor'), 'Id number',
                            get_string('socketroomid', 'quizaccess_heartbeatmonitor'),
                            get_string('currentstatus', 'quizaccess_heartbeatmonitor'),
                            get_string('statusupdate', 'quizaccess_heartbeatmonitor'),
                            get_string('timeutilized', 'quizaccess_heartbeatmonitor'),
                            get_string('timelost', 'quizaccess_heartbeatmonitor'),
                            'Total extra time granted');
        $result = $DB->get_records_sql($sql);
        $count = 0;

        if (!empty($result)){
            // Output data of each row.
            foreach ($result as $record) {
                $roomid    = $record->roomid;
                $roomdata  = explode("_", $roomid);
                $attemptid = array_splice($roomdata, -1)[0];
                $quiza     = $DB->get_record('quiz_attempts', array('id'=>$attemptid));

                /*
                // Cron task created.
                if(!$quiza || $quiza->state == 'finished') {
                    $hblivetable = 'quizaccess_hbmon_livetable';
                    $select = 'roomid = ?'; // Is put into the where clause.
                    $params = array($roomid);
                    $delete = $DB->delete_records_select($hblivetable, $select, $params);
                    continue;
                }
                */

                $roomquizid = array_splice($roomdata, -1)[0];
                $username   = implode("_", $roomdata);
                $user       = $DB->get_record('user', array('username'=>$username));

                if($user) {
                    $userid = $user->id;
                }

                if($roomquizid == $quizid) {
                    $status          = $record->status;
                    $timetoconsider  = $record->timetoconsider;
                    $livetime        = $record->livetime;
                    $deadtime        = $record->deadtime;
                    $extratime       = $record->extratime;

                    $currenttimestamp = intval(microtime(true));

                    if ($status == 'Live') {
                        $livetime = ($currenttimestamp - $timetoconsider) + $livetime;
                        $statustodisplay = get_string('online', 'quizaccess_heartbeatmonitor');
                    } else {
                        $deadtime = ($currenttimestamp - $timetoconsider) + $deadtime;
                        $statustodisplay = get_string('offline', 'quizaccess_heartbeatmonitor');
                    }

                    $humanisedlivetime = format_time($livetime);
                    $humaniseddeadtime = format_time($deadtime);
                    $humanisedextratime = format_time($extratime);
                    if($humanisedlivetime == 'now') $humanisedlivetime = '-';
                    if($humaniseddeadtime == 'now') $humaniseddeadtime = '-';
                    if($humanisedextratime == 'now') $humanisedextratime = '-';

                    $table->rowclasses['roomid'] = $roomid;
                    $row = new html_table_row();
                    $row->id = $roomid;
                    $row->attributes['class'] = $roomid;

                    $value = $roomid . '_' . $deadtime;

                    $count++;
                    $cell01 = new html_table_cell($count);
                    $cell01->id = 'srno';

                    $cell02 = new html_table_cell($user->idnumber);
                    $cell02->id = 'idno';

                    $cell0 = new html_table_cell($user->firstname .  ' ' . $user->lastname);
                    $cell0->id = 'user';

                    //             $cell1 = new html_table_cell($roomid);
                    $cell1 = new html_table_cell($user->lastip);
                    $cell1->id = 'roomid';

                    $cell2 = new html_table_cell($statustodisplay);
                    $cell2->id = 'status';

                    $cell3 = new html_table_cell(date('g:i a, M j', intval($timetoconsider)));
                    $cell3->id = 'timetoconsider';

                    $cell4 = new html_table_cell($humanisedlivetime);
                    $cell4->id = 'livetime';
                    $cell4->attributes['value'] = $livetime;

                    $cell5 = new html_table_cell($humaniseddeadtime);
                    $cell5->id = 'deadtime';
                    $cell5->attributes['value'] = $deadtime;

                    $cell6 = new html_table_cell($humanisedextratime);
                    $cell6->id = 'extratime';
                    $cell6->attributes['value'] = $extratime;

                    $row->cells[] = $cell01;

                    $row->cells[] = $cell0;
                    $row->cells[] = $cell02;
                    $row->cells[] = $cell1;
                    $row->cells[] = $cell2;
                    $row->cells[] = $cell3;
                    $row->cells[] = $cell4;
                    $row->cells[] = $cell5;
                    $row->cells[] = $cell6;

                    $table->data[] = $row;
                }
            }
        }

        $url = new moodle_url('/mod/quiz/report.php', array('id'=>$cmid, 'mode'=>'hbmon'));
        $startnode_form = new startnode_form($url, $quiz, $course, $cm);
        $stopnode_form = new stopnode_form($url, $quiz, $course, $cm);
        static $node_up = 0;
        $outputfile = $CFG->dirroot . "/mod/quiz/accessrule/heartbeatmonitor/exec_output.text";
        $outputfile_temp = $CFG->dirroot . "/mod/quiz/accessrule/heartbeatmonitor/exec_output_temp.text";
        $pidfile = $CFG->dirroot . "/mod/quiz/accessrule/heartbeatmonitor/exec_pid.text";

        // Manage node server.
        if($formdata = $startnode_form->get_data()) {
//             print_r($formdata);
            if($formdata->submitbutton == 'Start') {
                // Start node.
                $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
                if ($socket === false) {
//                     echo "socket_create() failed: reason: " . socket_strerror(socket_last_error()) . "\n";
                } else {
//                     echo "Socket create OK.<br/>";
                }

//                 echo "Checking for node sockets to connect at '$HBCFG->host' on port '$HBCFG->port'... <br/>";
                $phpws_result = @socket_connect($socket, $HBCFG->host, $HBCFG->port);
                //$phpws_result = socket_bind($socket, $HBCFG->host, $HBCFG->port) ;

                if($phpws_result === false) {
                    $cmd = "node " . $CFG->dirroot . "/mod/quiz/accessrule/heartbeatmonitor/server.js";
                    file_put_contents($outputfile, file_get_contents($outputfile_temp), FILE_APPEND | LOCK_EX);
                    file_put_contents($outputfile, ' ----- \n' . date('l jS \of F Y h:i:s A'), FILE_APPEND | LOCK_EX);
                    file_put_contents($outputfile_temp, '');
                    file_put_contents($outputfile_temp, date('l jS \of F Y h:i:s A'));
                    file_put_contents($pidfile, '');
//                     echo "Starting node server ... <br/>";
                    exec(sprintf("%s > %s 2>&1 & echo $! >> %s", $cmd, $outputfile_temp, $pidfile));

                    while(trim(file_get_contents($outputfile_temp)) == false) {
//                         echo "Reattempting to connect to '$HBCFG->host' on port '$HBCFG->port'...";
                        $phpws_result = @socket_connect($socket, $HBCFG->host, $HBCFG->port);
                        if ($phpws_result === false) {
//                             echo "socket_connect() failed. Reason: ($phpws_result) " . socket_strerror(socket_last_error($socket)) . "\n";
                            sleep(1);
                        } else {
//                             echo "Connect OK.\n";
                            $node_up = 1;
                            break;
                        }
                    }
                }
                if(!$node_up) {} // Throw errors captured in exec_output.text file here.
                // Display the console output captured in exec_output.text file.
                if (!$phpws_result && trim(file_get_contents($outputfile_temp)) != false) {
                    $node_err = nl2br(file_get_contents($outputfile_temp));
                    echo $OUTPUT->notification($node_err);
                }
            }
        } else if ($stopnode_form->get_data()) {
            // Stop node.
            $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            $phpws_result = @socket_connect($socket, $HBCFG->host, $HBCFG->port);

//             echo '<br><br><br> phpws result in else -- ';
//             print_object($phpws_result);

            if($phpws_result) {
                $cmd = "kill " . file_get_contents($pidfile);
                exec($cmd);
            }
            sleep(5);
        }

        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $phpws_result = @socket_connect($socket, $HBCFG->host, $HBCFG->port);

//         echo '<br><br><br> phpws result -- ';
//         print_object($phpws_result);
//         echo '-- res - ' . empty($phpws_result);

        if(empty($phpws_result)) {
//             echo "<br> in if --";
            $startnode_form->display();
        } else {
//             echo "<br> in else --";
            $stopnode_form->display();
        }

        /*
        $mform = new createoverrides_form($url, $cm, $quiz, $context);
        if($fromform = $mform->get_data()) {
            if($fromform->users) {
                $users = '';
                $i = 1;
                echo get_string('youhaveselected', 'quizaccess_heartbeatmonitor');

                foreach ($fromform->users as $user) {
                    $arr        = explode("_", $user);
                    $attemptid  = array_splice($arr, -1)[0];
                    $roomquizid = array_splice($arr, -1)[0];
                    $username   = implode("_", $arr);

                    $userdata   = $DB->get_record('user', array('username'=>$username));
                    $roomuserid = $userdata->id;

                    echo $i . ' | ' . $userdata->firstname .  ' ' . $userdata->lastname . get_string('br', 'quizaccess_heartbeatmonitor');
                    $users .= $user . ' ';
                    $i++;
                }
                $mform1 = new timelimitoverride_form($processoverrideurl, $cm, $quiz, $context, $users, 0);
                $mform1->display();
            }
        } else {*/

            if(empty($table->data)) {
                echo html_writer::nonempty_tag('liveuserstblcaption', get_string('liveusers', 'quizaccess_heartbeatmonitor'));
                echo $OUTPUT->notification(get_string('nodatafound', 'quizaccess_heartbeatmonitor'), 'info');
            } else {
                echo html_writer::nonempty_tag('liveuserstblcaption', get_string('liveusers', 'quizaccess_heartbeatmonitor'));
                // Display table.
                echo html_writer::table($table);
            }
//         }
    }

    public function secondsToTime($seconds) {
        $dtF = new DateTime('@0');
        $dtT = new DateTime("@$seconds");
        return $dtF->diff($dtT)->format('%a d, %h h : %i m : %s s');
    }
}
