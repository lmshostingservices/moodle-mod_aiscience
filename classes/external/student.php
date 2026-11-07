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

namespace mod_aiscience\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use mod_aiscience\local\manager;
use mod_aiscience\local\editor;

/**
 * Student web services: load the experiment, record progress, submit, notebook.
 *
 * @package    mod_aiscience
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class student extends external_api {
    /** Most answer boxes kept per attempt. */
    const MAXANSWERS = 200;

    // Experiment.

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function experiment_parameters() {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'versionid' => new external_value(PARAM_INT, 'Version (teachers only), 0 for published', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * The sandboxed experiment document.
     *
     * @param int $cmid
     * @param int $versionid
     * @return array
     */
    public static function experiment($cmid, $versionid = 0) {
        global $DB;
        $p = self::validate_parameters(self::experiment_parameters(), compact('cmid', 'versionid'));
        [, $context, $instance] = manager::load($p['cmid']);
        self::validate_context($context);
        require_capability('mod/aiscience:view', $context);
        $id = (int)$instance->currentversion;
        if ($p['versionid'] && $p['versionid'] != $id) {
            require_capability('mod/aiscience:generate', $context);
            $id = $p['versionid'];
        }
        $version = $id ? $DB->get_record('aiscience_version', ['id' => $id, 'aiscienceid' => $instance->id]) : null;
        if (!$version || trim((string)$version->html) === '') {
            return ['versionid' => 0, 'html' => '', 'title' => ''];
        }
        $plan = json_decode((string)$version->plan, true) ?: [];
        return ['versionid' => (int)$version->id, 'html' => \mod_aiscience\local\player::wrap($version->html),
            'title' => (string)($plan['title'] ?? format_string($instance->name))];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function experiment_returns() {
        return new external_single_structure([
            'versionid' => new external_value(PARAM_INT, 'Version'),
            'html' => new external_value(
                PARAM_RAW, // Pipeline-ignore: PARAM_RAW — Output only, escaped on display.
                'Sandboxed document',
            ),
            'title' => new external_value(PARAM_RAW, 'Title'), // Pipeline-ignore: PARAM_RAW — Output only, escaped on display.
        ]);
    }

    // Record.

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function record_parameters() {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'versionid' => new external_value(PARAM_INT, 'Version the student is using'),
            'type' => new external_value(PARAM_ALPHA, 'progress, response or submit'),
            'payload' => new external_value(
                PARAM_RAW, // Pipeline-ignore: PARAM_RAW — JSON, decoded and validated.
                'JSON payload from LabKit',
            ),
        ]);
    }

    /**
     * Record a LabKit message for the current student.
     *
     * @param int $cmid
     * @param int $versionid
     * @param string $type
     * @param string $payload
     * @return array
     */
    public static function record($cmid, $versionid, $type, $payload) {
        global $DB, $USER;
        $p = self::validate_parameters(self::record_parameters(), compact('cmid', 'versionid', 'type', 'payload'));
        [$cm, $context, $instance, $course] = manager::load($p['cmid']);
        self::validate_context($context);
        require_capability('mod/aiscience:submit', $context);
        $data = json_decode($p['payload'], true);
        if (!is_array($data) || strlen($p['payload']) > 500000) {
            throw new \invalid_parameter_exception('Bad payload');
        }
        $attempt = manager::locked($instance, (int)$USER->id, function () use ($instance, $p, $data) {
            return self::apply_record($instance, $p, $data);
        });
        if ($p['type'] === 'progress' && (int)$attempt->readings > 0) {
            // Readings arrived during the lesson: "Record data" completion can tick now, not only at submit.
            $completion = new \completion_info($course);
            if ($completion->is_enabled($cm) && !empty($instance->completiondata)) {
                $completion->update_state($cm, COMPLETION_COMPLETE, $USER->id);
            }
        }
        if ($p['type'] === 'submit') {
            \mod_aiscience\event\report_submitted::create(['objectid' => $attempt->id, 'context' => $context,
                'relateduserid' => $USER->id])->trigger();
            $completion = new \completion_info($course);
            if ($completion->is_enabled($cm)) {
                $completion->update_state($cm, COMPLETION_COMPLETE, $USER->id);
            }
        }
        return ['attemptid' => (int)$attempt->id];
    }

    /**
     * Apply one LabKit message to the student's attempt. Call only while holding the student's lock.
     *
     * @param \stdClass $instance
     * @param array $p validated parameters
     * @param array $data decoded payload
     * @return \stdClass the attempt
     */
    protected static function apply_record(\stdClass $instance, array $p, array $data): \stdClass {
        global $DB, $USER;
        $attempt = manager::attempt($instance, $USER->id, manager::student_version($instance, (int)$p['versionid']));
        $update = (object)['id' => $attempt->id, 'timemodified' => time()];
        switch ($p['type']) {
            case 'progress':
                $update->stage = max((int)$attempt->stage, min(6, (int)($data['stage'] ?? 1)));
                $update->stagename = editor::plain((string)($data['name'] ?? ''), 100);
                if (isset($data['readings']) && is_numeric($data['readings'])) {
                    $update->readings = max((int)$attempt->readings, max(0, min(100000, (int)$data['readings'])));
                }
                break;
            case 'response':
                $answers = json_decode((string)$attempt->answers, true) ?: [];
                $key = editor::plain(is_scalar($data['k'] ?? null) ? (string)$data['k'] : '', 64);
                if ($key !== '' && (isset($answers[$key]) || count($answers) < self::MAXANSWERS)) {
                    $answers[$key] = editor::plain(is_scalar($data['v'] ?? null) ? (string)$data['v'] : '', 5000);
                    $update->answers = json_encode($answers);
                }
                break;
            case 'submit':
                $answers = json_decode((string)$attempt->answers, true) ?: [];
                foreach ((array)($data['answers'] ?? []) as $k => $v) {
                    $k = editor::plain((string)$k, 64);
                    if (is_scalar($v) && $k !== '' && (isset($answers[$k]) || count($answers) < self::MAXANSWERS)) {
                        $answers[$k] = editor::plain((string)$v, 5000);
                    }
                }
                if (!empty($data['findings']) && is_array($data['findings'])) {
                    $answers['_findings'] = json_encode(array_slice($data['findings'], 0, 50));
                }
                $update->answers = json_encode($answers);
                $update->prediction = editor::plain(is_scalar($data['prediction'] ?? null) ? (string)$data['prediction'] : '', 255);
                $update->phases = json_encode(array_map(
                    fn($v) => is_numeric($v) ? round((float)$v, 3) : null,
                    array_slice((array)($data['phases'] ?? []), 0, 50)
                ));
                $update->readings = max(0, min(100000, (int)($data['readings'] ?? 0)));
                $update->datasource = \core_text::substr(clean_param($data['source'] ?? '', PARAM_ALPHANUMEXT), 0, 30);
                $update->report = editor::plain(is_scalar($data['report'] ?? null) ? (string)$data['report'] : '', 20000);
                $update->status = $attempt->status === 'graded' ? 'graded' : 'submitted';
                $update->stage = 6;
                $update->timesubmitted = time();
                break;
            default:
                throw new \invalid_parameter_exception('Unknown type');
        }
        $DB->update_record('aiscience_attempt', $update);
        foreach ((array)$update as $field => $value) {
            $attempt->{$field} = $value;
        }
        return $attempt;
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function record_returns() {
        return new external_single_structure(['attemptid' => new external_value(PARAM_INT, 'Attempt')]);
    }

    // Notes.

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function addnote_parameters() {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'versionid' => new external_value(PARAM_INT, 'Version'),
            'content' => new external_value(
                PARAM_RAW, // Pipeline-ignore: PARAM_RAW — Text with < or >, cleaned on save.
                'Note text',
            ),
            'stagelabel' => new external_value(
                PARAM_RAW, // Pipeline-ignore: PARAM_RAW — Text with < or >, cleaned on save.
                'Stage',
                VALUE_DEFAULT,
                '',
            ),
            'exptime' => new external_value(
                PARAM_RAW, // Pipeline-ignore: PARAM_RAW — Text with < or >, cleaned on save.
                'Experiment time',
                VALUE_DEFAULT,
                '',
            ),
        ]);
    }

    /**
     * Add a typed note.
     *
     * @param int $cmid
     * @param int $versionid
     * @param string $content
     * @param string $stagelabel
     * @param string $exptime
     * @return array
     */
    public static function addnote($cmid, $versionid, $content, $stagelabel = '', $exptime = '') {
        global $DB, $USER;
        $p = self::validate_parameters(
            self::addnote_parameters(),
            compact('cmid', 'versionid', 'content', 'stagelabel', 'exptime')
        );
        [, $context, $instance] = manager::load($p['cmid']);
        self::validate_context($context);
        require_capability('mod/aiscience:submit', $context);
        $content = editor::plain($p['content'], 5000);
        if ($content === '') {
            throw editor::problem('The note is empty.');
        }
        $attempt = manager::locked($instance, (int)$USER->id, fn() => manager::attempt(
            $instance,
            $USER->id,
            manager::student_version($instance, (int)$p['versionid'])
        ));
        $id = $DB->insert_record('aiscience_note', (object)['attemptid' => $attempt->id, 'userid' => $USER->id,
            'kind' => 'text', 'content' => $content,
            'stagelabel' => editor::plain($p['stagelabel'], 100), 'exptime' => editor::plain($p['exptime'], 30),
            'timecreated' => time()]);
        return ['noteid' => $id];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function addnote_returns() {
        return new external_single_structure(['noteid' => new external_value(PARAM_INT, 'Note')]);
    }

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function deletenote_parameters() {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'noteid' => new external_value(PARAM_INT, 'Note id'),
        ]);
    }

    /**
     * Delete one of the student's own notebook items, with its photo or recording. Not allowed once the work is graded.
     *
     * @param int $cmid
     * @param int $noteid
     * @return array
     */
    public static function deletenote($cmid, $noteid) {
        global $DB, $USER;
        $p = self::validate_parameters(self::deletenote_parameters(), compact('cmid', 'noteid'));
        [, $context, $instance] = manager::load($p['cmid']);
        self::validate_context($context);
        require_capability('mod/aiscience:submit', $context);
        $note = $DB->get_record_sql(
            "SELECT n.id, a.status FROM {aiscience_note} n JOIN {aiscience_attempt} a ON a.id = n.attemptid
              WHERE n.id = ? AND n.userid = ? AND a.aiscienceid = ? AND a.userid = n.userid",
            [$p['noteid'], $USER->id, $instance->id]
        );
        if (!$note) {
            throw editor::problem(get_string('notenotfound', 'mod_aiscience'));
        }
        if ($note->status === 'graded') {
            throw editor::problem(get_string('notegraded', 'mod_aiscience'));
        }
        get_file_storage()->delete_area_files($context->id, 'mod_aiscience', 'note', $note->id);
        $DB->delete_records('aiscience_note', ['id' => $note->id]);
        return ['deleted' => true];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function deletenote_returns() {
        return new external_single_structure(['deleted' => new external_value(PARAM_BOOL, 'Deleted')]);
    }

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function notes_parameters() {
        return new external_function_parameters(['cmid' => new external_value(PARAM_INT, 'Course module id')]);
    }

    /**
     * The current student's notebook.
     *
     * @param int $cmid
     * @return array
     */
    public static function notes($cmid) {
        global $DB, $USER;
        ['cmid' => $cmid] = self::validate_parameters(self::notes_parameters(), ['cmid' => $cmid]);
        [, $context, $instance] = manager::load($cmid);
        self::validate_context($context);
        require_capability('mod/aiscience:view', $context);
        $fs = get_file_storage();
        $out = [];
        $sql = "SELECT n.* FROM {aiscience_note} n JOIN {aiscience_attempt} a ON a.id = n.attemptid
                 WHERE a.aiscienceid = ? AND n.userid = ? ORDER BY n.timecreated DESC";
        foreach ($DB->get_records_sql($sql, [$instance->id, $USER->id], 0, 200) as $n) {
            $url = '';
            if ($n->kind !== 'text') {
                $files = $fs->get_area_files($context->id, 'mod_aiscience', 'note', $n->id, 'id', false);
                if ($f = reset($files)) {
                    $url = \moodle_url::make_pluginfile_url(
                        $context->id,
                        'mod_aiscience',
                        'note',
                        $n->id,
                        '/',
                        $f->get_filename()
                    )->out(false);
                }
            }
            $out[] = ['id' => (int)$n->id, 'kind' => $n->kind, 'content' => (string)$n->content,
                'stagelabel' => (string)$n->stagelabel, 'exptime' => (string)$n->exptime, 'url' => $url,
                'timecreated' => (int)$n->timecreated];
        }
        return ['notes' => $out];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function notes_returns() {
        return new external_single_structure(['notes' => new external_multiple_structure(new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Id'),
            'kind' => new external_value(PARAM_ALPHA, 'Kind'),
            'content' => new external_value(PARAM_RAW, 'Text'), // Pipeline-ignore: PARAM_RAW — Output only, escaped on display.
            'stagelabel' => new external_value(PARAM_RAW, 'Stage'), // Pipeline-ignore: PARAM_RAW — Output only, escaped on display.
            'exptime' => new external_value(
                PARAM_RAW, // Pipeline-ignore: PARAM_RAW — Output only, escaped on display.
                'Experiment time',
            ),
            'url' => new external_value(PARAM_URL, 'Media'),
            'timecreated' => new external_value(PARAM_INT, 'Time'),
        ]))]);
    }
}
