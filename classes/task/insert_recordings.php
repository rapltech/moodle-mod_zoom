<?php

namespace mod_zoom\task;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/zoom/locallib.php');
require_once($CFG->dirroot . '/lib/modinfolib.php');
require_once($CFG->dirroot . '/mod/zoom/lib.php');
require_once($CFG->dirroot . '/mod/zoom/classes/webservice.php');


/**
 * Scheduled task to sychronize meeting data.
 *
 * @package   mod_zoom
 */
class insert_recordings extends \core\task\scheduled_task
{

    /**
     * Returns name of task.
     *
     * @return string
     */
    public function get_name()
    {
        return get_string("update_recording", "zoom");
    }

    /**
     * Updates recordings that are not expired.
     *
     * @return boolean
     */
    public function execute()
    {
        global $CFG, $DB;
        $config = get_config('mod_zoom');

        $sql = "SELECT e.*, mz.meeting_id, mz.auto_recording, mz.webinar
              FROM mdl_event as e
              JOIN mdl_zoom mz on e.instance = mz.id
              WHERE e.modulename = 'zoom'
                AND mz.deleted_at IS NULL 
                AND FROM_UNIXTIME(e.endtime) BETWEEN NOW() - interval 2 day and NOW()";

        $zoom_events = $DB->get_records_sql($sql);
        $service = new \mod_zoom_webservice();

        foreach ($zoom_events as $value) {
            $zoom_recordings = null;
            try {
                $this->disable_download_in_stream($value->meeting_id);

                // This will return past meeting instances only when those meetings have more than 1 participant
                $past_meeting = $service->get_past_meeting_instances($value->meeting_id, $value->webinar);

                $uuids = $this->fetchEventUUID($past_meeting);

                foreach ($uuids as $uuid) {
                    try {
                        $fetch_existing_recording = "SELECT * FROM mdl_zoom_recordings where uuid = '$uuid' AND meeting_id = $value->meeting_id";
                        $existing_recording = $DB->get_records_sql($fetch_existing_recording);

                        if (!$existing_recording) {
                            $recordings = $service->get_meeting_recording($uuid);

                            if (!empty($recordings) && !empty($recordings->recording_files)) {
                                $all_inserted = true;
                                foreach ($recordings->recording_files as $rec) {
                                    $record = new \stdClass();
                                    $record->meeting_id = $recordings->id;
                                    $record->uuid = $recordings->uuid;
                                    $record->play_url = $rec->play_url;
                                    $record->download_url = $rec->download_url . '?access_token=' . $recordings->download_access_token;
                                    $record->start_time = $rec->recording_start;
                                    $record->end_time = $rec->recording_end;
                                    $record->status = $rec->status;

                                    $inserted = $DB->insert_record('zoom_recordings', $record);
                                    if (!is_int($inserted)) {
                                        mtrace("Failed to insert recording for meeting UUID: {$recordings->uuid}");
                                        $all_inserted = false;
                                    }
                                }

                                if ($all_inserted) {
                                    $DB->update_record('event', (object)[
                                        'id' => $event->id,
                                        'recording_created' => 1
                                    ]);
                                    mtrace("Recordings updated for event ID: {$event->id} and UUID: {$recordings->uuid}");
                                } else {
                                    mtrace("Some recordings could not be inserted for event ID: {$event->id} and UUID: {$recordings->uuid}");
                                }
                            } else {
                                mtrace('Recording already exist for meeting: ' . $value->meeting_id . 'and uuid: ' . $uuid);
                            }
                        }

                    } catch (\moodle_exception $error) {
                        mtrace("Recording skipped for uuid: {$uuid} and meeting_id: {$value->meeting_id} because of the error {$error}");
                    }
                }
            } catch (\moodle_exception $error) {
                mtrace("Recordings could not be updated for meeting_id: {$value->meeting_id} because of the error {$error}");
            }
        }
    }

    /**
     * Disable download option in stream
     * @param int $meeting_id
     */
    private function disable_download_in_stream($meeting_id)
    {
        $service = new \mod_zoom_webservice();
        $service->update_recording_settings($meeting_id, ['viewer_download' => false]);
    }

     /**
      * @param $completed_events
      * @param $event
      * @return mixed
      */
     private function fetchEventUUID($completed_events)
     {
        $uuid = [];
         foreach ($completed_events->meetings as $completed_event) {
             $uuid[] = $completed_event->uuid;
         }

         return $uuid;
     }
}
