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
 * CLI script to manually update the meeting recordings.
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

// CLI options.
list($options, $unrecognized) = cli_get_params(
    [
        'help' => false,
        'meeting_id' => false,
    ],
    [
        'h' => 'help',
        'm' => 'meeting_id',
    ]
);

if ($unrecognized) {
    cli_error(get_string('cliunknowoption', 'admin', implode("\n  ", $unrecognized)));
}

if ($options['help']) {
    $help = "CLI script to manually update the recordings for all the missing recordings events or for a particular meeting_id .

            Options:
            -h, --help          Print out this help
            -m, --meeting_id     Zoom meeting ID
            
            Example:
            \$sudo -u www-data /usr/bin/php mod/zoom/cli/update_meeting_recordings.php --meeting_id=1234
    ";

    cli_error($help);
}

$trace = new text_progress_trace();
$events = [];

if (!empty($options['meeting_id'])) {
    $sql = "SELECT e.*, mz.meeting_id, mz.auto_recording, mz.webinar 
              FROM mdl_event as e
              JOIN mdl_zoom mz on e.instance = mz.id
              WHERE e.modulename = 'zoom'
                AND mz.deleted_at IS NULL 
                AND mz.meeting_id = ?
              AND e.endtime < UNIX_TIMESTAMP(NOW())";
    try {
        $events = $DB->get_records_sql($sql, [$options['meeting_id']]);
    } catch (Exception $e) {
        $trace->output('Exception: ' . $e->getMessage(), 1);
    }
} else {
    $sql = "SELECT e.*, mz.meeting_id, mz.auto_recording, mz.webinar 
              FROM mdl_event as e
              JOIN mdl_zoom mz on e.instance = mz.id
              WHERE e.modulename = 'zoom'
                AND e.recording_created = 0
                AND mz.deleted_at IS NULL 
             AND e.endtime < UNIX_TIMESTAMP(NOW())";
    try {
        $events = $DB->get_records_sql($sql);
    } catch (Exception $e) {
        $trace->output('Exception: ' . $e);
    }
}

if (empty($events)) {
    cli_error('No meetings found to update.');
}

$service = new mod_zoom_webservice();

/**
 * ---------------------------------------------------------
 * Allowed recording file types (admin setting)
 * ---------------------------------------------------------
 */

$allowedtypes = ['mp4'];
$additionalTypes = get_config('mod_zoom', 'recording_file_types');
if (!empty($additionalTypes)) {
    $additionalTypes = json_decode($additionalTypes, true);
    if (is_array($additionalTypes)) {
        $allowedtypes = array_merge($allowedtypes, array_map('strtolower', array_keys($additionalTypes)));
    }
}

$trace->output('Allowed recording file types: ' . implode(', ', $allowedtypes));

foreach (keyByMeetingId($events) as $meeting_id => $events) {

    $trace->output(sprintf('Processing details of meeting_id: %d', $meeting_id));

    try {
        $completed_meetings = $service->get_past_meeting_instances($meeting_id, $events->webinar);
    } catch (Exception $e) {
        mtrace('Error while fetching past meetings for meeting id: ' . $meeting_id);
        $trace->output('Exception: ' . $e);
        continue;
    }

    //Disable viewer download for all the recordings of the meeting
    $service->update_recording_settings($meeting_id, ['viewer_download' => false]);

    foreach ($events as $event) {

        $trace->output(sprintf('Processing details of event_id: %d', $event->id));

        $uuids = fetchAllEventUUIDs($completed_meetings, $event);

        if (empty($uuids)) {
            $trace->output(sprintf('UUID not found for event_id: %d', $event->id));
            $trace->output(sprintf('---------------------------------------------'));
            continue;
        }

        foreach ($uuids as $uuid) {

            try {
                if ($DB->get_record('zoom_recordings', [
                    'meeting_id' => $meeting_id,
                    'uuid' => $uuid
                ])) {
                    $DB->update_record('event', (object)[
                        'id' => $event->id,
                        'recording_created' => 1
                    ]);
                    $trace->output(sprintf('Skipping recording update as it already exists for event_id: %d', $event->id));
                    $trace->output(sprintf('---------------------------------------------'));
                    continue;
                }

                $recordings = $service->get_meeting_recording($uuid);

                if (!empty($recordings) && !empty($recordings->recording_files)) {

                    $all_inserted = true;

                    foreach ($recordings->recording_files as $rec) {

                        $filetype = strtolower($rec->file_type ?? '');

                        if (!in_array($filetype, $allowedtypes, true)) {
                            $trace->output(
                                "Skipping {$filetype} recording for uuid: {$uuid}",
                                1
                            );
                            continue;
                        }

                        $record = new stdClass();
                        $record->meeting_id = $recordings->id;
                        $record->uuid = $recordings->uuid;
                        $record->play_url = $rec->play_url ?? null;
                        $record->download_url =
                            $rec->download_url . '?access_token=' . $recordings->download_access_token;
                        $record->start_time = $rec->recording_start ?? null;
                        $record->end_time = $rec->recording_end ?? null;
                        $record->status = $rec->status ?? null;
                        $record->file_type = $filetype;

                        $inserted = $DB->insert_record('zoom_recordings', $record);

                        if (!is_int($inserted)) {
                            mtrace("Failed to insert {$filetype} recording for uuid: {$uuid}");
                            $all_inserted = false;
                        }
                    }

                    if ($all_inserted) {
                        $DB->update_record('event', (object)[
                            'id' => $event->id,
                            'recording_created' => 1
                        ]);
                        mtrace(
                            "Recordings updated for event_id: {$event->id} and uuid: {$uuid}"
                        );
                    } else {
                        mtrace(
                            "Some recordings could not be inserted for event_id: {$event->id} and uuid: {$uuid}"
                        );
                    }

                } else {
                    mtrace(
                        "No recordings found for meeting_id: {$meeting_id} and uuid: {$uuid}"
                    );
                }

            } catch (moodle_exception $error) {
                mtrace(
                    "Recording skipped for uuid: {$uuid} and meeting_id: {$meeting_id} " .
                    "because of error: {$error->getMessage()}"
                );
            }
        }

        $trace->output('---------------------------------------------');
    }
}

/**
 * @param array $events
 * @return array
 */
function keyByMeetingId(array $events)
{
    $data = [];
    foreach ($events as $event) {
        $data[$event->meeting_id][] = $event;
    }

    return $data;
}

/**
 * @param $completed_events
 * @param $event
 * @return mixed
 */
function fetchAllEventUUIDs($completed_events, $event)
{
    $uuids = [];

    foreach ($completed_events->meetings as $completed_event) {
        if (date('Y-m-d', strtotime($completed_event->start_time)) == date('Y-m-d', $event->timestart)) {
            $uuids[] = $completed_event->uuid;
        }
    }

    return $uuids;
}
