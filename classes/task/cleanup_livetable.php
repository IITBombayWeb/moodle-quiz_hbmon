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
 * Code for hbmon cron.
 *
 * @package   quiz_hbmon
 * @copyright 2017 IIT Bombay
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quiz_hbmon\task;

defined('MOODLE_INTERNAL') || die();


/**
 * This class holds all the code for automatically updating hbmon livetable data.
 *
 * @copyright 2017 IIT Bombay
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


/**
 * An example of a scheduled task.
 */
class cleanup_livetable extends \core\task\scheduled_task {

    /**
     * Return the task's name as shown in admin screens.
     *
     * @return string
     */
    public function get_name() {
        return get_string('hbmon_cron', 'quiz_hbmon');
    }

    /**
     * Execute the task.
     */
    public function execute() {
        global $DB;
        $sql = 'SELECT * FROM {quizaccess_hbmon_livetable}';  // Todo: Select data for a particular quiz and not entire table. Insert quizid col in livetable for this.
        $arr = array();
        $roomid = null;

        $result = $DB->get_records_sql($sql);
        $count = 0;

        if (!empty($result)){
            // Output data of each row.
            foreach ($result as $record) {
                $roomid    = $record->roomid;
                $roomdata  = explode("_", $roomid);
                $attemptid = array_splice($roomdata, -1)[0];
                $quiza     = $DB->get_record('quiz_attempts', array('id'=>$attemptid));

                if(!$quiza || $quiza->state == 'finished') {
                    $hblivetable = 'quizaccess_hbmon_livetable';
                    $select = 'roomid = ?'; // Is put into the where clause.
                    $params = array($roomid);
                    $delete = $DB->delete_records_select($hblivetable, $select, $params);
                    continue;
                }
            }
        }
    }
}