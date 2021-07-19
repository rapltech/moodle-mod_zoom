<?php
// This file is part of the Zoom plugin for Moodle - http://moodle.org/
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
 * Load zoom meeting and assign grade to the user join the meeting.
 *
 * You can have a rather longer description of the file as well,
 * if you like, and it can span multiple lines.
 *
 * @package    mod_zoom
 * @copyright  2015 UC Regents
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
global $CFG, $DB, $PAGE;
require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->libdir . '/moodlelib.php');
require_once(dirname(__FILE__) . '/locallib.php');
require_once($CFG->dirroot . '/mod/zoom/classes/webservice.php');

// Course_module ID.
$id = required_param('id', PARAM_INT);
if ($id) {
    $cm = get_coursemodule_from_id('zoom', $id, 0, false, MUST_EXIST);
    $course = get_course($cm->course);
    $zoom = $DB->get_record('zoom', array('id' => $cm->instance), '*', MUST_EXIST);
} else {
    print_error('You must specify a course_module ID');
}
$userishost = (zoom_get_user_id(false) == $zoom->host_id);

require_login($course, true, $cm);

$context = context_module::instance($cm->id);
$PAGE->set_context($context);

require_capability('mod/zoom:view', $context);
$service = new \mod_zoom_webservice();
if ($userishost) {
    $nexturl = new moodle_url($zoom->start_url);
} else {
    // Check whether user had a grade. If no, then assign full credits to him or her.
    $gradelist = grade_get_grades($course->id, 'mod', 'zoom', $cm->instance, $USER->id);

    // Assign full credits for user who has no grade yet, if this meeting is gradable (i.e. the grade type is not "None").
    if (!empty($gradelist->items) && empty($gradelist->items[0]->grades[$USER->id]->grade)) {
        $grademax = $gradelist->items[0]->grademax;
        $grades = array('rawgrade' => $grademax,
            'userid' => $USER->id,
            'usermodified' => $USER->id,
            'dategraded' => '',
            'feedbackformat' => '',
            'feedback' => '');

        zoom_grade_item_update($zoom, $grades);
    }
    if ($zoom->enable_registration == 1) {
        $queryToFindJoinUrl = "SELECT join_url FROM `mdl_zoom_meeting_registrant`
        WHERE email = (SELECT email FROM `mdl_user` WHERE id = $USER->id)
        AND meeting_id = $zoom->meeting_id";
        $userJoinUrl = $DB->get_record_sql($queryToFindJoinUrl);

        $nexturl = new moodle_url($zoom->join_url, array('uname' => fullname($USER)));
        if (!empty($userJoinUrl)) {
            $joinUrl = urldecode($userJoinUrl->join_url);
            $nexturl = new moodle_url($joinUrl);
        } else {
            $queryToGetMeetingAndStudentDetails = "SELECT distinct (u.id), c.id AS 'program_id',
            mz.meeting_id,
            u.firstname, u.lastname, u.email
     FROM mdl_user u
              JOIN mdl_user_enrolments ue ON ue.userid = u.id
              JOIN mdl_enrol e ON e.id = ue.enrolid
              JOIN mdl_role_assignments ra ON ra.userid = u.id
              JOIN mdl_context ct ON ct.id = ra.contextid AND ct.contextlevel = 50
              JOIN mdl_course c ON c.id = ct.instanceid AND e.courseid = c.id
              JOIN mdl_role r ON r.id = ra.roleid AND r.shortname = 'student'
              JOIN mdl_zoom mz ON mz.course = c.id
              JOIN mdl_zoom_meeting_registrant zmr ON zmr.meeting_id = mz.meeting_id AND zmr.email != u.email
     WHERE e.status = 0 AND u.suspended = 0 AND u.deleted = 0
       AND (ue.timeend = 0 OR ue.timeend > UNIX_TIMESTAMP(NOW())) AND ue.status = 0
       AND c.id = $course->id
       AND mz.enable_registration = 1
       AND NOT EXISTS(SELECT 1 FROM mdl_user u2
                                        JOIN mdl_user_enrolments ue2 ON ue2.userid = u2.id
                                        JOIN mdl_enrol e2 ON e2.id = ue2.enrolid
                                        JOIN mdl_role_assignments ra2 ON ra2.userid = u2.id
                                        JOIN mdl_context ct2 ON ct2.id = ra2.contextid
                                        JOIN mdl_course c2 ON c2.id = ct2.instanceid and e2.courseid = c2.id
                                        JOIN mdl_role r2 ON r2.id = ra2.roleid AND r2.shortname = 'student'
                                        JOIN mdl_zoom mz2 ON mz2.course = c2.id
                                         JOIN mdl_zoom_meeting_registrant zmr ON zmr.meeting_id = mz2.meeting_id AND zmr.email = u2.email
                      WHERE u2.id = u.id
                        AND e2.status = 0 AND u2.suspended = 0 AND u2.deleted = 0
                        AND (ue2.timeend = 0 OR ue2.timeend > UNIX_TIMESTAMP(NOW()))
                        AND ct2.contextlevel = 50
                        AND ue2.status = 0
                        AND c2.id =c.id
                        AND c2.enddate = c.enddate)
                        AND mz2.enable_registration = 1";
            $meetingEnrolledUser = $DB->get_records_sql($queryToGetMeetingAndStudentDetails);
            foreach ($meetingEnrolledUser as $user) {
                try {
                    $response = $service->add_meeting_registrants($user->meeting_id, $user->firstname, $user->lastname, $user->email);

                    if (!empty($response)) {
                        $queryToInsertRegistrant = "INSERT INTO `mdl_zoom_meeting_registrant` (meeting_id, email, first_name, last_name, registrant_id, start_time, topic, status, created_at)
                                                VALUES ($user->meeting_id, '$user->email', '$user->firstname', '$user->lastname', '$response->registrant_id', '$response->start_time', '$response->topic', 'PENDING', now())";
                        $DB->execute($queryToInsertRegistrant);

                        $getRegistrantDetails = "SELECT registrant_id AS 'id', email FROM `mdl_zoom_meeting_registrant` WHERE meeting_id = $user->meeting_id";
                        $registrantDetails = $DB->get_records_sql($getRegistrantDetails);

                        $requestPayload = [];
                        $requestPayload["action"] = "approve";
                        $requestPayload["registrants"] = [];
                        $registrants = [];
                        foreach ($registrantDetails as $rData) {
                            $temp = [
                                "id" => "$rData->id",
                                "email" => "$rData->email"
                            ];
                            array_push($registrants, $temp);
                        }
                    }
                } catch (\moodle_exception $error) {
                    mtrace('Add meeting registrant status failed: ' . $error);
                }

            }
            if (!empty($registrants)) {
                try {

                    $requestPayload["registrants"] = $registrants;
                    $requestPayload = json_encode($requestPayload);

                    $queryToGetPendingStatusMeeting = "SELECT DISTINCT(meeting_id) FROM `mdl_zoom_meeting_registrant` WHERE status = 'PENDING'";
                    $meetingIdList = $DB->get_records_sql($queryToGetPendingStatusMeeting);
                    foreach ($meetingIdList as $meeting) {
                        $updateMeetingRegistrantStatus = $service->update_registrants_status($requestPayload, $meeting->meeting_id);

                        if ($updateMeetingRegistrantStatus == 204) {
                            try {
                                $meetingRegistrantList = $service->get_meeting_registrants($meeting->meeting_id);
                                foreach ($meetingRegistrantList->registrants as $data) {
                                    if ($data->status == "approved") {
                                        $join_url = urlencode($data->join_url);
                                        $updateStatusAndJoinUrl = "UPDATE `mdl_zoom_meeting_registrant`
                                        SET join_url = '$join_url', status = '$data->status'
                                        WHERE email = '$data->email' AND meeting_id = $meeting->meeting_id";
                                        $DB->execute($updateStatusAndJoinUrl);
                                    }
                                }
                            } catch (\moodle_exception $error) {
                                mtrace('Failed to get meeting registrants: ' . $error);
                            }
                        }
                    }
                } catch (\moodle_exception $error) {
                    mtrace('Update registrant status failed: ' . $error);
                }

                $queryToFindJoinUrl = "SELECT join_url FROM `mdl_zoom_meeting_registrant` 
                WHERE email = (SELECT email FROM `mdl_user` WHERE id = $USER->id)
                AND meeting_id = $zoom->meeting_id";
                $userJoinUrl = $DB->get_record_sql($queryToFindJoinUrl);
                $joinUrl = urldecode($userJoinUrl->join_url);
                $nexturl = new moodle_url($joinUrl);
            }

        }
    } else {
        $nexturl = new moodle_url($zoom->join_url, array('uname' => fullname($USER)));
    }

}
// Record user's clicking join.
\mod_zoom\event\join_meeting_button_clicked::create(array('context' => $context, 'objectid' => $zoom->id, 'other' =>
        array('cmid' => $id, 'meetingid' => (int) $zoom->meeting_id, 'userishost' => $userishost)))->trigger();
redirect($nexturl);
