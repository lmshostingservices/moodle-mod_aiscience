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
 * Lists all AI Science Experiments in a course.
 *
 * @package    mod_aiscience
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);
$course = $DB->get_record('course', ['id' => $id], '*', MUST_EXIST);
require_course_login($course);
$context = context_course::instance($course->id);
$event = \mod_aiscience\event\course_module_instance_list_viewed::create(['context' => $context]);
$event->add_record_snapshot('course', $course);
$event->trigger();

$PAGE->set_url('/mod/aiscience/index.php', ['id' => $id]);
$PAGE->set_title(get_string('modulenameplural', 'aiscience'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->navbar->add(get_string('modulenameplural', 'aiscience'));
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('modulenameplural', 'aiscience'));

$instances = get_all_instances_in_course('aiscience', $course);
if (!$instances) {
    notice(get_string('noinstances', 'aiscience'), new moodle_url('/course/view.php', ['id' => $course->id]));
}
$table = new html_table();
$table->head = [get_string('name'), get_string('yearlevel', 'aiscience'), get_string('question', 'aiscience')];
foreach ($instances as $i) {
    $link = html_writer::link(
        new moodle_url('/mod/aiscience/view.php', ['id' => $i->coursemodule]),
        format_string($i->name),
        $i->visible ? [] : ['class' => 'dimmed']
    );
    $table->data[] = [$link, s($i->yearlevel), s($i->question)];
}
echo html_writer::table($table);
echo $OUTPUT->footer();
