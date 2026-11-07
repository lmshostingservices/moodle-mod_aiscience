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
 * Library of functions for AI Science Experiment.
 *
 * @package    mod_aiscience
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Features this module supports.
 *
 * @param string $feature FEATURE_xx constant
 * @return mixed
 */
function aiscience_supports($feature) {
    switch ($feature) {
        case FEATURE_MOD_INTRO:
        case FEATURE_SHOW_DESCRIPTION:
        case FEATURE_BACKUP_MOODLE2:
        case FEATURE_GRADE_HAS_GRADE:
        case FEATURE_COMPLETION_TRACKS_VIEWS:
        case FEATURE_COMPLETION_HAS_RULES:
        case FEATURE_GROUPS:
            return true;
        case FEATURE_MOD_PURPOSE:
            return MOD_PURPOSE_INTERACTIVECONTENT;
        default:
            return null;
    }
}

/**
 * Normalise form data into a DB record.
 *
 * @param stdClass $data
 * @return stdClass
 */
function aiscience_prepare_record(stdClass $data): stdClass {
    $record = clone $data;
    foreach (['datasources', 'supports'] as $multi) {
        if (isset($data->{$multi}) && is_array($data->{$multi})) {
            $record->{$multi} = implode(',', array_keys(array_filter($data->{$multi})));
        }
    }
    $record->completionsubmit = empty($data->completionsubmit) ? 0 : 1;
    $record->completiondata = empty($data->completiondata) ? 0 : 1;
    $record->pooldata = empty($data->pooldata) ? 0 : 1;
    $record->aisuggest = empty($data->aisuggest) ? 0 : 1;
    $record->timemodified = time();
    return $record;
}

/**
 * Add a new instance.
 *
 * @param stdClass $data
 * @param mod_aiscience_mod_form|null $mform
 * @return int new instance id
 */
function aiscience_add_instance($data, $mform = null) {
    global $DB, $USER;
    $record = aiscience_prepare_record($data);
    $record->timecreated = time();
    $record->currentversion = 0;
    if (!isset($record->grade)) {
        $record->grade = 12;
    }
    $record->id = $DB->insert_record('aiscience', $record);

    $context = context_module::instance($data->coursemodule);
    aiscience_save_curriculum_file($data, $context);

    // Start from a library experiment when one was picked.
    if (!empty($data->librarykey)) {
        \mod_aiscience\local\library::install($record->id, $data->librarykey, $USER->id);
    }

    aiscience_grade_item_update($record);
    return $record->id;
}

/**
 * Update an instance.
 *
 * @param stdClass $data
 * @param mod_aiscience_mod_form|null $mform
 * @return bool
 */
function aiscience_update_instance($data, $mform = null) {
    global $DB, $USER;
    $record = aiscience_prepare_record($data);
    $record->id = $data->instance;
    $old = $DB->get_record('aiscience', ['id' => $record->id], '*', MUST_EXIST);
    unset($record->currentversion);
    $DB->update_record('aiscience', $record);

    $context = context_module::instance($data->coursemodule);
    aiscience_save_curriculum_file($data, $context);

    if (!empty($data->librarykey) && $data->librarykey !== $old->librarykey) {
        \mod_aiscience\local\library::install($record->id, $data->librarykey, $USER->id);
    }

    $record = $DB->get_record('aiscience', ['id' => $record->id]);
    aiscience_grade_item_update($record);
    aiscience_update_grades($record);
    return true;
}

/**
 * Save the uploaded curriculum file and pull text out of plain-text uploads.
 *
 * @param stdClass $data
 * @param context_module $context
 */
function aiscience_save_curriculum_file(stdClass $data, context_module $context): void {
    global $DB;
    if (!isset($data->curriculumfile)) {
        return;
    }
    file_save_draft_area_files(
        $data->curriculumfile,
        $context->id,
        'mod_aiscience',
        'curriculum',
        0,
        ['subdirs' => 0, 'maxfiles' => 3, 'accepted_types' => ['.pdf', '.doc', '.docx', '.txt', '.csv']]
    );
    $instanceid = $data->instance ?? $DB->get_field('course_modules', 'instance', ['id' => $data->coursemodule]);
    $text = \mod_aiscience\local\curriculum::extract_text($context->id);
    if ($text !== '' && trim((string)($data->curriculum ?? '')) === '') {
        $DB->set_field('aiscience', 'curriculum', $text, ['id' => $instanceid]);
    }
}

/**
 * Delete an instance and everything in it.
 *
 * @param int $id
 * @return bool
 */
function aiscience_delete_instance($id) {
    global $DB;
    if (!$instance = $DB->get_record('aiscience', ['id' => $id])) {
        return false;
    }
    $attemptids = $DB->get_fieldset_select('aiscience_attempt', 'id', 'aiscienceid = ?', [$id]);
    if ($attemptids) {
        [$insql, $params] = $DB->get_in_or_equal($attemptids);
        $DB->delete_records_select('aiscience_note', "attemptid $insql", $params);
    }
    $DB->delete_records('aiscience_attempt', ['aiscienceid' => $id]);
    $DB->delete_records('aiscience_version', ['aiscienceid' => $id]);
    $DB->delete_records('aiscience', ['id' => $id]);
    grade_update('mod/aiscience', $instance->course, 'mod', 'aiscience', $id, 0, null, ['deleted' => 1]);
    return true;
}

/**
 * Create or update the grade item.
 *
 * @param stdClass $instance
 * @param mixed $grades
 * @return int
 */
function aiscience_grade_item_update($instance, $grades = null) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');
    $params = ['itemname' => $instance->name, 'idnumber' => $instance->cmidnumber ?? ''];
    if ($instance->grade > 0) {
        $params['gradetype'] = GRADE_TYPE_VALUE;
        $params['grademax'] = $instance->grade;
        $params['grademin'] = 0;
    } else if ($instance->grade < 0) {
        $params['gradetype'] = GRADE_TYPE_SCALE;
        $params['scaleid'] = -$instance->grade;
    } else {
        $params['gradetype'] = GRADE_TYPE_NONE;
    }
    if ($grades === 'reset') {
        $params['reset'] = true;
        $grades = null;
    }
    return grade_update('mod/aiscience', $instance->course, 'mod', 'aiscience', $instance->id, 0, $grades, $params);
}

/**
 * Get user grades for the gradebook.
 *
 * @param stdClass $instance
 * @param int $userid
 * @return array
 */
function aiscience_get_user_grades($instance, $userid = 0) {
    global $DB;
    $params = ['aiscienceid' => $instance->id, 'status' => 'graded'];
    if ($userid) {
        $params['userid'] = $userid;
    }
    $grades = [];
    foreach ($DB->get_records('aiscience_attempt', $params, 'timegraded ASC') as $a) {
        $grades[$a->userid] = (object)[
            'userid' => $a->userid,
            'rawgrade' => $a->grade,
            'feedback' => $a->feedback,
            'feedbackformat' => FORMAT_PLAIN,
            'usermodified' => $a->grader,
            'dategraded' => $a->timegraded,
        ];
    }
    return $grades;
}

/**
 * Push grades to the gradebook.
 *
 * @param stdClass $instance
 * @param int $userid
 * @param bool $nullifnone
 */
function aiscience_update_grades($instance, $userid = 0, $nullifnone = true) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');
    if ($grades = aiscience_get_user_grades($instance, $userid)) {
        aiscience_grade_item_update($instance, $grades);
    } else if ($userid && $nullifnone) {
        aiscience_grade_item_update($instance, [$userid => (object)['userid' => $userid, 'rawgrade' => null]]);
    } else {
        aiscience_grade_item_update($instance);
    }
}

/**
 * Course module info, including custom completion rules.
 *
 * @param stdClass $coursemodule
 * @return cached_cm_info|false
 */
function aiscience_get_coursemodule_info($coursemodule) {
    global $DB;
    $fields = 'id, name, intro, introformat, completionsubmit, completiondata';
    if (!$instance = $DB->get_record('aiscience', ['id' => $coursemodule->instance], $fields)) {
        return false;
    }
    $result = new cached_cm_info();
    $result->name = $instance->name;
    if ($coursemodule->showdescription) {
        $result->content = format_module_intro('aiscience', $instance, $coursemodule->id, false);
    }
    if ($coursemodule->completion == COMPLETION_TRACKING_AUTOMATIC) {
        $result->customdata['customcompletionrules']['completionsubmit'] = $instance->completionsubmit;
        $result->customdata['customcompletionrules']['completiondata'] = $instance->completiondata;
    }
    return $result;
}

/**
 * Serve plugin files: notebook media and curriculum uploads.
 *
 * @param stdClass $course
 * @param stdClass $cm
 * @param context $context
 * @param string $filearea
 * @param array $args
 * @param bool $forcedownload
 * @param array $options
 * @return bool
 */
function aiscience_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    global $DB, $USER;
    if ($context->contextlevel != CONTEXT_MODULE) {
        return false;
    }
    require_course_login($course, true, $cm);
    if (!in_array($filearea, ['note', 'curriculum'])) {
        return false;
    }
    $itemid = (int)array_shift($args);
    if ($filearea === 'note') {
        $note = $DB->get_record('aiscience_note', ['id' => $itemid], '*', MUST_EXIST);
        if ($note->userid != $USER->id) {
            require_capability('mod/aiscience:viewreports', $context);
        }
    } else {
        require_capability('mod/aiscience:generate', $context);
    }
    $filename = array_pop($args);
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';
    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'mod_aiscience', $filearea, $itemid, $filepath, $filename);
    if (!$file || $file->is_directory()) {
        return false;
    }
    send_stored_file($file, 0, 0, $forcedownload, $options);
}

/**
 * Mark the activity viewed and trigger the event.
 *
 * @param stdClass $instance
 * @param stdClass $course
 * @param stdClass $cm
 * @param context_module $context
 */
function aiscience_view($instance, $course, $cm, $context) {
    $event = \mod_aiscience\event\course_module_viewed::create([
        'objectid' => $instance->id,
        'context' => $context,
    ]);
    $event->add_record_snapshot('course', $course);
    $event->add_record_snapshot('aiscience', $instance);
    $event->trigger();
    $completion = new completion_info($course);
    $completion->set_module_viewed($cm);
}

/**
 * Reset course user data.
 *
 * @param stdClass $data
 * @return array
 */
function aiscience_reset_userdata($data) {
    global $DB;
    $status = [];
    if (!empty($data->reset_aiscience_attempts)) {
        $ids = $DB->get_fieldset_select('aiscience', 'id', 'course = ?', [$data->courseid]);
        foreach ($ids as $id) {
            $attemptids = $DB->get_fieldset_select('aiscience_attempt', 'id', 'aiscienceid = ?', [$id]);
            if ($attemptids) {
                [$insql, $params] = $DB->get_in_or_equal($attemptids);
                $DB->delete_records_select('aiscience_note', "attemptid $insql", $params);
            }
            $DB->delete_records('aiscience_attempt', ['aiscienceid' => $id]);
            $instance = $DB->get_record('aiscience', ['id' => $id]);
            if ($cm = get_coursemodule_from_instance('aiscience', $id, $data->courseid)) {
                get_file_storage()->delete_area_files(context_module::instance($cm->id)->id, 'mod_aiscience', 'note');
            }
            aiscience_grade_item_update($instance, 'reset');
        }
        $status[] = ['component' => get_string('modulenameplural', 'aiscience'),
            'item' => get_string('resetattempts', 'aiscience'), 'error' => false];
    }
    return $status;
}

/**
 * Course reset form elements.
 *
 * @param MoodleQuickForm $mform
 */
function aiscience_reset_course_form_definition(&$mform) {
    $mform->addElement('header', 'aiscienceheader', get_string('modulenameplural', 'aiscience'));
    $mform->addElement('advcheckbox', 'reset_aiscience_attempts', get_string('resetattempts', 'aiscience'));
}

/**
 * Course reset defaults.
 *
 * @param stdClass $course
 * @return array
 */
function aiscience_reset_course_form_defaults($course) {
    return ['reset_aiscience_attempts' => 1];
}
