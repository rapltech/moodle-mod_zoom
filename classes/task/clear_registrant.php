<?php

namespace mod_zoom\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Schedule task to add meeting registrant
 *
 * @package mod_zoom
 * @author Ashish Srivastav <ashish@linkstreet.in>
 */
class clear_registrant extends \core\task\scheduled_task
{
    public function get_name()
    {
        return ('Clear registrants records');
    }

    public function execute()
    {
        global $DB;

        $queryToGetMeeting = "SELECT GROUP_CONCAT(mdl_zoom_meeting_registrant.meeting_id) AS 'meetingid'
        FROM mdl_zoom_meeting_registrant
                 JOIN mdl_zoom on mdl_zoom_meeting_registrant.meeting_id = mdl_zoom.meeting_id
        WHERE mdl_zoom.enable_registration = 0";
        $meetingList = $DB->get_record_sql($queryToGetMeeting);

        if ($meetingList->meetingid != null) {
            $queryToDeleteRecords = "DELETE FROM `mdl_zoom_meeting_registrant` WHERE `mdl_zoom_meeting_registrant`.meeting_id IN ($meetingList->meetingid)";
            $DB->execute($queryToDeleteRecords);
        }
    }
}