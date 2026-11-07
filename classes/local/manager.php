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

namespace mod_aiscience\local;

/**
 * Versions, attempts and grading.
 *
 * @package    mod_aiscience
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manager {
    /**
     * Load cm, context and instance from a cmid, with login checks.
     *
     * @param int $cmid
     * @return array [$cm, $context, $instance, $course]
     */
    public static function load(int $cmid): array {
        global $DB;
        [$course, $cm] = get_course_and_cm_from_cmid($cmid, 'aiscience');
        $context = \context_module::instance($cm->id);
        $instance = $DB->get_record('aiscience', ['id' => $cm->instance], '*', MUST_EXIST);
        return [$cm, $context, $instance, $course];
    }

    /**
     * Start a new AI version at the planning step.
     *
     * @param \stdClass $instance
     * @param int $userid
     * @param array $fields extra fields
     * @param string $task plan or build
     * @return int version id
     */
    public static function new_version(\stdClass $instance, int $userid, array $fields, string $task): int {
        global $DB;
        $now = time();
        $num = (int)$DB->get_field_sql(
            'SELECT MAX(versionnum) FROM {aiscience_version} WHERE aiscienceid = ?',
            [$instance->id]
        ) + 1;
        $record = (object)array_merge(['aiscienceid' => $instance->id, 'versionnum' => $num, 'status' => 'planning',
            'source' => 'ai', 'createdby' => $userid, 'timecreated' => $now, 'timemodified' => $now], $fields);
        $id = $DB->insert_record('aiscience_version', $record);
        self::queue($id, $task, $userid);
        return $id;
    }

    /**
     * Queue the plan or build task.
     *
     * @param int $versionid
     * @param string $task
     * @param int $userid
     */
    public static function queue(int $versionid, string $task, int $userid): void {
        $class = $task === 'plan' ? \mod_aiscience\task\plan_experiment::class : \mod_aiscience\task\build_experiment::class;
        $t = new $class();
        $t->set_custom_data(['versionid' => $versionid]);
        $t->set_userid($userid);
        \core\task\manager::queue_adhoc_task($t);
    }

    /**
     * Publish a version.
     *
     * @param \stdClass $instance
     * @param int $versionid
     * @param \context_module $context
     */
    public static function publish(\stdClass $instance, int $versionid, \context_module $context): void {
        global $DB;
        $version = $DB->get_record('aiscience_version', ['id' => $versionid, 'aiscienceid' => $instance->id], '*', MUST_EXIST);
        if (!in_array($version->status, ['ready', 'retired', 'published'])) {
            throw new \moodle_exception('generalexceptionmessage', 'error', '', 'This version is not ready to publish.');
        }
        $DB->set_field('aiscience_version', 'status', 'retired', ['aiscienceid' => $instance->id, 'status' => 'published']);
        $DB->update_record('aiscience_version', (object)['id' => $versionid, 'status' => 'published', 'timemodified' => time()]);
        $DB->set_field('aiscience', 'currentversion', $versionid, ['id' => $instance->id]);
        \mod_aiscience\event\experiment_published::create(['objectid' => $versionid, 'context' => $context])->trigger();
    }

    /**
     * The version a student's message belongs to: one students have been given (published now or
     * before), otherwise the published one. Drafts and other activities' versions are never used.
     *
     * @param \stdClass $instance
     * @param int $versionid
     * @return int
     */
    public static function student_version(\stdClass $instance, int $versionid): int {
        global $DB;
        if (
            $versionid && $versionid != $instance->currentversion && !$DB->record_exists_select(
                'aiscience_version',
                "id = ? AND aiscienceid = ? AND status IN ('published', 'retired')",
                [$versionid, $instance->id]
            )
        ) {
            $versionid = 0;
        }
        return $versionid ?: (int)$instance->currentversion;
    }

    /**
     * Run $fn while holding this student's lock for this activity, so messages that arrive at the
     * same moment (typing while autoplay saves) cannot overwrite each other or create two attempts.
     *
     * @param \stdClass $instance
     * @param int $userid
     * @param callable $fn
     * @return mixed
     */
    public static function locked(\stdClass $instance, int $userid, callable $fn) {
        $factory = \core\lock\lock_config::get_lock_factory('mod_aiscience');
        $lock = $factory->get_lock('attempt_' . $instance->id . '_' . $userid, 15);
        if (!$lock) {
            throw new \moodle_exception('generalexceptionmessage', 'error', '', 'Still saving your last change. Try again.');
        }
        try {
            return $fn();
        } finally {
            $lock->release();
        }
    }

    /**
     * The student's current attempt, created on first use.
     *
     * @param \stdClass $instance
     * @param int $userid
     * @param int $versionid
     * @return \stdClass
     */
    public static function attempt(\stdClass $instance, int $userid, int $versionid): \stdClass {
        global $DB;
        $attempts = $DB->get_records(
            'aiscience_attempt',
            ['aiscienceid' => $instance->id, 'userid' => $userid],
            'timecreated DESC',
            '*',
            0,
            1
        );
        if ($attempt = reset($attempts)) {
            if ($attempt->status === 'inprogress') {
                return $attempt;
            }
            if ($versionid == $attempt->versionid) {
                return $attempt;
            }
        }
        $now = time();
        $attempt = (object)['aiscienceid' => $instance->id, 'versionid' => $versionid, 'userid' => $userid,
            'status' => 'inprogress', 'stage' => 1, 'readings' => 0, 'answers' => '{}', 'timecreated' => $now,
            'timemodified' => $now];
        $attempt->id = $DB->insert_record('aiscience_attempt', $attempt);
        return $attempt;
    }

    /**
     * Save a grade from CER rubric scores.
     *
     * @param \stdClass $instance
     * @param \stdClass $attempt
     * @param array $rubric claim, evidence, reasoning 0-4
     * @param string $feedback
     * @param int $graderid
     * @return float|null grade, null when the activity is not graded (feedback only)
     */
    public static function grade(\stdClass $instance, \stdClass $attempt, array $rubric, string $feedback, int $graderid): ?float {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/mod/aiscience/lib.php');
        $rubric = array_map(fn($v) => max(0, min(4, (int)$v)), $rubric);
        $total = array_sum($rubric);
        $grade = null;
        if ($instance->grade > 0) {
            $grade = round($total / 12 * (int)$instance->grade, 2);
        } else if ($instance->grade < 0) {
            // Scale: spread the 0-12 rubric total across the scale items, 1 = first item.
            $scale = $DB->get_field('scale', 'scale', ['id' => -$instance->grade]);
            $items = $scale ? count(explode(',', $scale)) : 1;
            $grade = (float)max(1, min($items, 1 + (int)round($total / 12 * ($items - 1))));
        }
        $DB->update_record('aiscience_attempt', (object)['id' => $attempt->id, 'status' => 'graded', 'grade' => $grade,
            'rubric' => json_encode($rubric), 'feedback' => $feedback, 'grader' => $graderid, 'timegraded' => time(),
            'timemodified' => time()]);
        aiscience_update_grades($instance, $attempt->userid);
        return $grade;
    }
}
