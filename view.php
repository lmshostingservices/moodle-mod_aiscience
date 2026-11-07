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
 * Activity page: teacher studio or student experiment.
 *
 * @package    mod_aiscience
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$id = required_param('id', PARAM_INT);
[$course, $cm] = get_course_and_cm_from_cmid($id, 'aiscience');
$instance = $DB->get_record('aiscience', ['id' => $cm->instance], '*', MUST_EXIST);
require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/aiscience:view', $context);

aiscience_view($instance, $course, $cm, $context);

$PAGE->set_url('/mod/aiscience/view.php', ['id' => $cm->id]);
$PAGE->set_title(format_string($instance->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);
$PAGE->add_body_class('mod-aiscience-page');

$isteacher = has_capability('mod/aiscience:generate', $context) || has_capability('mod/aiscience:viewreports', $context);
$config = [
    'cmid' => (int)$cm->id,
    'role' => $isteacher ? 'teacher' : 'student',
    'cansubmit' => has_capability('mod/aiscience:submit', $context),
    'cangenerate' => has_capability('mod/aiscience:generate', $context),
    'canpublish' => has_capability('mod/aiscience:publish', $context),
    'canreports' => has_capability('mod/aiscience:viewreports', $context),
    'cangrade' => has_capability('mod/aiscience:grade', $context),
    'name' => format_string($instance->name),
    'question' => (string)$instance->question,
    'year' => (string)$instance->yearlevel,
    'coursename' => format_string($course->shortname),
    'editurl' => (new moodle_url('/course/modedit.php', ['update' => $cm->id, 'return' => 1]))->out(false),
    'uploadurl' => (new moodle_url('/mod/aiscience/note_upload.php'))->out(false),
    'sesskey' => sesskey(),
    'voice' => (string)get_config('mod_aiscience', 'voice'),
    'voicerate' => (float)(get_config('mod_aiscience', 'voicerate') ?: 0.95),
    'published' => (int)$instance->currentversion,
];
$PAGE->requires->js_call_amd('mod_aiscience/app', 'init', [$config]);

echo $OUTPUT->header();
echo html_writer::div(html_writer::div('', 'aisx-loading', ['aria-hidden' => 'true']), 'aisx', [
    'id' => 'aisx-root', 'data-role' => $config['role'],
]);
echo $OUTPUT->footer();
