<?php
global $DB, $CFG;
require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->libdir . '/moodlelib.php');
require_once(dirname(__FILE__) . '/locallib.php');
require_once($CFG->dirroot . '/mod/zoom/classes/webservice.php');

function registerUser($enrolledUser, $meetingID, $isWebinar) {
    global $DB;
    $service = new \mod_zoom_webservice();

    try {
        $findMeetingOrWebinar = $service->get_meeting_webinar_info($meetingID, $isWebinar);
        if (!empty($findMeetingOrWebinar)) {
            foreach ($enrolledUser as $user) {
                $queryToFindIfUserAlreadyRegistered = "SELECT count(*) AS 'noofrecord' FROM `mdl_zoom_meeting_registrant` WHERE meeting_id = $meetingID
                                                       AND email = '$user->email'";
                $registeredUser = $DB->get_record_sql($queryToFindIfUserAlreadyRegistered);

                if ($registeredUser->noofrecord == 0) {
                    try{
                        $response = $service->add_meeting_registrants($meetingID, $user->firstname, $user->lastname, $user->email);
            
                        if (!empty($response)) {
    
                            $insertRegistrant['meeting_id'] = $meetingID;
                            $insertRegistrant['email'] = "$user->email";
                            $insertRegistrant['first_name'] = "$user->firstname";
                            $insertRegistrant['last_name'] = "$user->lastname";
                            $insertRegistrant['registrant_id'] = "$response->registrant_id";
                            $insertRegistrant['start_time'] = "$response->start_time";
                            $insertRegistrant['topic'] = "$response->topic";
                            $insertRegistrant['status'] = 'PENDING';
                            $insertRegistrant['created_at'] = date('Y-m-d H:i:s');
                            $DB->insert_record('zoom_meeting_registrant', $insertRegistrant);
            
                            $getRegistrantDetails = "SELECT registrant_id AS 'id', email FROM `mdl_zoom_meeting_registrant` WHERE meeting_id = $meetingID AND status = 'PENDING'";
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
                } else {
                    mtrace('User for the given meeting already registered');
                }
            }
            if(!empty($response)) {
                updateMeetingRegistrants($service, $DB, $registrants, $meetingID);
            }
        }
    }catch (\moodle_exception $error) {
        mtrace('Requested Meeting or webinar not found: ' . $error);
    }
}


function updateMeetingRegistrants($service, $DB, $registrants, $meetingID) {
    if(!empty($registrants)) {
        try {
            $requestPayload = [];
            $requestPayload["action"] = "approve";
            $requestPayload["registrants"] = $registrants;

            $service->update_registrants_status($requestPayload, $meetingID);
            syncRegistrantDetails($service, $DB, $meetingID);

        } catch (\moodle_exception $error) {
            mtrace('Update registrant status failed: ' . $error);
        }
    } else {
        mtrace('updateMeetingRegistrant received empty registrant status');
    }
}

function syncRegistrantDetails($service, $DB, $meetingID) {
    try {
        $meetingRegistrantList = $service->get_meeting_registrants($meetingID);
        foreach ($meetingRegistrantList->registrants as $data) {
            if ($data->status == "approved") {
                $join_url = urlencode($data->join_url);
                $updateStatusAndJoinUrl = "UPDATE `mdl_zoom_meeting_registrant`
                SET join_url = '$join_url', status = '$data->status'
                WHERE email = '$data->email' AND meeting_id = $meetingID";
                $DB->execute($updateStatusAndJoinUrl);
            } else {
                mtrace("Status returned in get_meeting_registrants api call id: " . $data->status);
            }
        }
    } catch (\moodle_exception $error) {
        mtrace('Failed to get meeting registrants: ' . $error);
    }
}
