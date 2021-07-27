<?php
global $DB, $CFG;
require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->libdir . '/moodlelib.php');
require_once(dirname(__FILE__) . '/locallib.php');
require_once($CFG->dirroot . '/mod/zoom/classes/webservice.php');

function registerUser($enrolledUser, $meeting_id) {
    global $DB;
    $service = new \mod_zoom_webservice();
    foreach ($enrolledUser as $user) {
        try {
            $response = $service->add_meeting_registrants($meeting_id, $user->firstname, $user->lastname, $user->email);

            if (!empty($response)) {
                $queryToInsertRegistrant = "INSERT INTO `mdl_zoom_meeting_registrant` (meeting_id, email, first_name, last_name, registrant_id, start_time, topic, status, created_at)
                                        VALUES ($meeting_id, '$user->email', '$user->firstname', '$user->lastname', '$response->registrant_id', '$response->start_time', '$response->topic', 'PENDING', now())";
                $DB->execute($queryToInsertRegistrant);

                $getRegistrantDetails = "SELECT registrant_id AS 'id', email FROM `mdl_zoom_meeting_registrant` WHERE meeting_id = $meeting_id AND status = 'PENDING'";
                $registrantDetails = $DB->get_records_sql($getRegistrantDetails);

                $registrants = [];
                foreach ($registrantDetails as $rData) {
                    $temp = [
                        "id" => "$rData->id",
                        "email" => "$rData->email"
                    ];
                    array_push($registrants, $temp);
                }
            } else {
                 mtrace('API call to add meeting registrant status returned an empty response');
            }
        } catch (\moodle_exception $error) {
            mtrace('Add meeting registrant status failed: ' . $error);
        }
    }
    if(!empty($response)) {
        updateMeetingRegistrants($service, $DB, $registrants, $meeting_id);
    }
}


function updateMeetingRegistrants($service, $DB, $registrants, $meeting_id) {
    if(!empty($registrants)) {
        try {
            $requestPayload = [];
            $requestPayload["action"] = "approve";
            $requestPayload["registrants"] = $registrants;
            $requestPayload = json_encode($requestPayload);
           
            $updateMeetingRegistrantStatus = $service->update_registrants_status($requestPayload, $meeting_id);

            if ($updateMeetingRegistrantStatus == 204) {
                syncRegistrantDetails($service, $DB, $meeting_id);
            } else {
                mtrace('update_registrants_status API returned status code as: ' . $updateMeetingRegistrantStatus);
            }
            
        } catch (\moodle_exception $error) {
            mtrace('Update registrant status failed: ' . $error);
        }
    } else {
        mtrace('updateMeetingRegistrant received empty registrant status');
    }
}

function syncRegistrantDetails($service, $DB, $meeting_id) {
    try {
        $meetingRegistrantList = $service->get_meeting_registrants($meeting_id);
        foreach ($meetingRegistrantList->registrants as $data) {
            if ($data->status == "approved") {
                $join_url = urlencode($data->join_url);
                $updateStatusAndJoinUrl = "UPDATE `mdl_zoom_meeting_registrant`
                SET join_url = '$join_url', status = '$data->status'
                WHERE email = '$data->email' AND meeting_id = $meeting_id";
                $DB->execute($updateStatusAndJoinUrl);
            } else {
                mtrace("Status returned in get_meeting_registrants api call id: " . $data->status);
            }
        }
    } catch (\moodle_exception $error) {
        mtrace('Failed to get meeting registrants: ' . $error);
    }
}
