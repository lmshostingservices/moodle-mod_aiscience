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

namespace mod_aiscience;

/**
 * Tests for the mod_aiscience data generator.
 *
 * @package    mod_aiscience
 * @category   test
 * @copyright  2026 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
final class generator_test extends \advanced_testcase {
    /**
     * The generator creates a working activity that Moodle can list, cache, back up and delete.
     */
    public function test_create_instance(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $this->assertEquals(0, $DB->count_records('aiscience', ['course' => $course->id]));

        $instance = $this->getDataGenerator()->create_module('aiscience', ['course' => $course->id, 'name' => 'Generated']);
        $this->assertEquals(1, $DB->count_records('aiscience', ['course' => $course->id]));
        $this->assertEquals('Generated', $DB->get_field('aiscience', 'name', ['id' => $instance->id]));

        $cm = get_fast_modinfo($course)->get_cm($instance->cmid);
        $this->assertEquals('aiscience', $cm->modname);
        $this->assertEquals($instance->id, $cm->instance);
        $this->assertEquals('Generated', $cm->name);
        $this->assertTrue($cm->uservisible);

        // A second activity with completion tracking on.
        $second = $this->getDataGenerator()->create_module(
            'aiscience',
            ['course' => $course->id],
            ['completion' => COMPLETION_TRACKING_MANUAL]
        );
        $this->assertEquals(2, $DB->count_records('aiscience', ['course' => $course->id]));
        $this->assertEquals(COMPLETION_TRACKING_MANUAL, get_fast_modinfo($course)->get_cm($second->cmid)->completion);

        // Deleting through the course API removes the instance.
        if (
            class_exists(\core_courseformat\local\cmactions::class)
                && method_exists(\core_courseformat\local\cmactions::class, 'delete')
        ) {
            \core_courseformat\formatactions::cm($course)->delete($instance->cmid);
        } else {
            course_delete_module($instance->cmid);
        }
        $this->assertFalse($DB->record_exists('aiscience', ['id' => $instance->id]));
    }

    /**
     * A generated activity survives a Moodle backup and restore into a new course.
     */
    public function test_backup_restore(): void {
        global $CFG, $DB, $USER;
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $CFG->enableavailability = true;

        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_module('aiscience', ['course' => $course->id, 'name' => 'Copied']);

        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $course->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_IMPORT,
            $USER->id
        );
        $backupid = $bc->get_backupid();
        $bc->execute_plan();
        $bc->destroy();

        $target = $this->getDataGenerator()->create_course();
        $rc = new \restore_controller(
            $backupid,
            $target->id,
            \backup::INTERACTIVE_NO,
            \backup::MODE_IMPORT,
            $USER->id,
            \backup::TARGET_CURRENT_ADDING
        );
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();

        $this->assertEquals('Copied', $DB->get_field('aiscience', 'name', ['course' => $target->id]));
    }
}
