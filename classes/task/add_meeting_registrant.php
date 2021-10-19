<?php

namespace mod_zoom\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Schedule task to add meeting registrant
 *
 * @package mod_zoom
 * @author Ashish Srivastav <ashish@linkstreet.in>
 */
class add_meeting_registrant extends \core\task\scheduled_task
{
    /**
     * Return name of the task
     *
     * @return boolean
     */
    public function get_name()
    {
        return get_string("add_meeting_registrant", "zoom");
    }

    /**
     * Approve registrants to join the meeting
     *
     */

    public function execute()
    {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/zoom/locallib.php');
        require_once($CFG->dirroot . '/lib/modinfolib.php');
        require_once($CFG->dirroot . '/mod/zoom/lib.php');
        require_once($CFG->dirroot . '/mod/zoom/classes/webservice.php');
        require_once($CFG->dirroot . '/mod/zoom/helperfunctions.php');

        $service = new \mod_zoom_webservice();

        $queryToFindZoomMeetings = "SELECT mdl_zoom.meeting_id, mdl_zoom.webinar FROM mdl_zoom WHERE mdl_zoom.meeting_id NOT IN (SELECT meeting_id FROM mdl_zoom_meeting_registrant)";
        $meetingAndWebinarList = $DB->get_records_sql($queryToFindZoomMeetings);

        if (!empty($meetingAndWebinarList)) {
            foreach ($meetingAndWebinarList as $record) {
                try {
                    $findMeeting = $service->get_meeting_webinar_info($record->meeting_id, $record->webinar);
                    if (!empty($findMeeting)) {
                        $queryToGetMeetingAndStudentDetails = "SELECT u.id, c.id AS 'program_id', mz.meeting_id, u.firstname, u.lastname, u.email
                            FROM mdl_user u
                                    JOIN mdl_user_enrolments ue ON ue.userid = u.id
                                    JOIN mdl_enrol e ON e.id = ue.enrolid
                                    JOIN mdl_role_assignments ra ON ra.userid = u.id
                                    JOIN mdl_context ct ON ct.id = ra.contextid AND ct.contextlevel = 50
                                    JOIN mdl_course c ON c.id = ct.instanceid and e.courseid = c.id
                                    JOIN mdl_role r ON r.id = ra.roleid AND r.shortname = 'student'
                                    JOIN mdl_zoom mz ON mz.course = c.id
                            WHERE e.status = 0 AND u.suspended = 0 AND u.deleted = 0
                            AND (ue.timeend = 0 OR ue.timeend > UNIX_TIMESTAMP(NOW())) AND ue.status = 0
                            AND mz.enable_registration = 1
                            AND mz.meeting_id = $record->meeting_id
                            AND NOT EXISTS(SELECT 1 FROM
                            mdl_zoom_meeting_registrant zmr
                            WHERE zmr.meeting_id = $record->meeting_id AND zmr.email = u.email)
                            LIMIT 30";
                        $meetingEnrolledUser = $DB->get_records_sql($queryToGetMeetingAndStudentDetails);

                        foreach ($meetingEnrolledUser as $data) {
                            try {
                                $response = $service->add_meeting_registrants($data->meeting_id, $data->firstname, $data->lastname, $data->email);
                                if (!empty($response)) {

                                    $insertRegistrant['meeting_id'] = $data->meeting_id;
                                    $insertRegistrant['email'] = "$data->email";
                                    $insertRegistrant['first_name'] = "$data->firstname";
                                    $insertRegistrant['last_name'] = "$data->lastname";
                                    $insertRegistrant['registrant_id'] = "$response->registrant_id";
                                    $insertRegistrant['start_time'] = "$response->start_time";
                                    $insertRegistrant['topic'] = "$response->topic";
                                    $insertRegistrant['status'] = 'PENDING';
                                    $insertRegistrant['created_at'] = date('Y-m-d H:i:s');

                                    $DB->insert_record('zoom_meeting_registrant', $insertRegistrant);

                                    $getRegistrantDetails = "SELECT registrant_id AS 'id', email FROM `mdl_zoom_meeting_registrant` WHERE meeting_id = $data->meeting_id AND status = 'PENDING'";
                                    $registrantDetails = $DB->get_records_sql($getRegistrantDetails);

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
                                mtrace('Add meeting registrant failed: ' . $error);
                            }
                            if (!empty($registrants)) {
                                updateMeetingRegistrants($service, $DB, $registrants, $data->meeting_id);
                            } else {
                                mtrace("Update Meeting Registrant Failed");
                            }
                        }
                    }
                } catch (\moodle_exception $error) {
                    mtrace('Meeting was not found in zoom backend: ' . $error);
                }
            }
        }
    }
}
