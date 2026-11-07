<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Upload a voice recording or photo to the lab notebook.
 *
 * @package    mod_aiscience
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);
require(__DIR__ . '/../../config.php');

$cmid = required_param('cmid', PARAM_INT);
$versionid = optional_param('versionid', 0, PARAM_INT);
$stagelabel = optional_param('stagelabel', '', PARAM_RAW); // Pipeline-ignore: PARAM_RAW — Text with < or >, cleaned on save.
$exptime = optional_param('exptime', '', PARAM_RAW); // Pipeline-ignore: PARAM_RAW — Text with < or >, cleaned on save.
$transcript = optional_param('transcript', '', PARAM_RAW); // Pipeline-ignore: PARAM_RAW — Text with < or >, cleaned on save.
[$cm, $context, $instance, $course] = \mod_aiscience\local\manager::load($cmid);
require_login($course, false, $cm);
require_sesskey();
require_capability('mod/aiscience:submit', $context);

if (empty($_FILES['media']) || $_FILES['media']['error'] !== UPLOAD_ERR_OK) {
    throw new moodle_exception('nofile');
}
$file = $_FILES['media'];
if ($file['size'] > 25 * 1024 * 1024) {
    throw new moodle_exception('maxbytesfile', 'error');
}
// Trust the file's real content, not the type the browser claims.
$real = (string)(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
$claimed = strtok((string)$file['type'], ';');
$audio = ['audio/webm', 'audio/ogg', 'audio/mp4', 'audio/mpeg', 'audio/wav', 'audio/x-wav', 'video/webm', 'video/mp4',
    'application/ogg'];
$image = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/heic', 'image/heif'];
$kind = in_array($real, $image) ? 'image' : (in_array($real, $audio) && strpos($claimed, 'audio/') === 0 ? 'audio' : '');
if ($kind === '') {
    throw new moodle_exception('invalidfiletype', 'error', '', s($real));
}
$editor = \mod_aiscience\local\editor::class;
$manager = \mod_aiscience\local\manager::class;
$noteid = $manager::locked($instance, (int)$USER->id, function () use (
    $DB,
    $USER,
    $instance,
    $versionid,
    $kind,
    $transcript,
    $stagelabel,
    $exptime,
    $editor,
    $manager
) {
    $attempt = $manager::attempt($instance, $USER->id, $manager::student_version($instance, (int)$versionid));
    if ($DB->count_records_select('aiscience_note', "attemptid = ? AND kind <> 'text'", [$attempt->id]) >= 60) {
        throw new moodle_exception('notebookfull', 'mod_aiscience');
    }
    return $DB->insert_record('aiscience_note', (object)['attemptid' => $attempt->id, 'userid' => $USER->id,
        'kind' => $kind, 'content' => $editor::plain($transcript, 5000), 'stagelabel' => $editor::plain($stagelabel, 100),
        'exptime' => $editor::plain($exptime, 30), 'timecreated' => time()]);
});
$ext = ['audio/webm' => 'webm', 'audio/ogg' => 'ogg', 'audio/mp4' => 'm4a', 'audio/mpeg' => 'mp3', 'audio/wav' => 'wav',
    'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'image/heic' => 'heic'];
$base = $kind === 'image' ? $real : $claimed;
$filename = $kind . '-' . $noteid . '.' . ($ext[$base] ?? ($kind === 'image' ? 'jpg' : 'webm'));
$fs = get_file_storage();
$stored = $fs->create_file_from_pathname(['contextid' => $context->id, 'component' => 'mod_aiscience', 'filearea' => 'note',
    'itemid' => $noteid, 'filepath' => '/', 'filename' => $filename, 'userid' => $USER->id], $file['tmp_name']);
$url = moodle_url::make_pluginfile_url($context->id, 'mod_aiscience', 'note', $noteid, '/', $filename)->out(false);
echo json_encode(['noteid' => $noteid, 'kind' => $kind, 'url' => $url]);
