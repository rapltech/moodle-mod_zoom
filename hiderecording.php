<?php
global $DB;

require_once(dirname(dirname(dirname(__FILE__))).'/config.php');
require_once(dirname(__FILE__).'/lib.php');
require_once(dirname(__FILE__).'/locallib.php');
require_once(dirname(__FILE__).'/../../lib/moodlelib.php');

$config = get_config('mod_zoom');

list($course, $cm, $zoom) = zoom_get_instance_setup();

$meeting_id = $_REQUEST['meeting_id'];
$play_url = $_REQUEST['play_url'] ?? null;
$download_url = $_REQUEST['download_url'] ?? null;

if (is_null($play_url)) {
    $hideMeeting = "UPDATE mdl_zoom_recordings SET hide_recording = 1 WHERE meeting_id = $meeting_id AND download_url = '$download_url'";
    $DB->execute($hideMeeting);
} else {
    $hideMeeting = "UPDATE mdl_zoom_recordings SET hide_recording = 1 WHERE meeting_id = $meeting_id AND play_url = '$play_url'";
    $DB->execute($hideMeeting);
}
?>

<script>
    location.href = "../zoom/view.php?id=" + <?php echo $cm->id ?>
</script>
