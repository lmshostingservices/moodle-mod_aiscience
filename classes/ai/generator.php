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

namespace mod_aiscience\ai;

use mod_aiscience\local\checks;

/**
 * Talks to LMS Labs AI and runs the plan, build, check and repair pipeline.
 *
 * @package    mod_aiscience
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class generator {
    /** @var int Repair rounds after failed checks. */
    const MAXREPAIRS = 2;

    /**
     * Where AI runs: always LMS Labs. Automated tests can switch to the built-in test replies by setting
     * $CFG->mod_aiscience_allowmock in config.php and the provider to "mock"; real sites cannot.
     *
     * @return string lmslabs or mock
     */
    public static function provider(): string {
        global $CFG;
        if (!empty($CFG->mod_aiscience_allowmock) && get_config('mod_aiscience', 'provider') === 'mock') {
            return 'mock';
        }
        return 'lmslabs';
    }

    /**
     * Whether AI can be used: the site is connected to LMS Labs.
     *
     * @param \context|null $context Activity context (kept for callers; AI is a site-level connection).
     * @return bool
     */
    public static function available(?\context $context = null): bool {
        if (self::provider() === 'mock') {
            return true;
        }
        return !empty(lmslabs::status()['connected']);
    }

    /**
     * Send one prompt through LMS Labs and return the text answer.
     *
     * @param string $prompt
     * @param int $contextid
     * @param int $userid
     * @param int $maxtokens
     * @param string $task plan | build | refine | repair | mark | suggest
     * @param string $jobid groups a build with its repairs
     * @param array $items answer keys, for marking
     * @return string
     */
    public static function complete(
        string $prompt,
        int $contextid,
        int $userid,
        int $maxtokens = 0,
        string $task = 'build',
        string $jobid = '',
        array $items = []
    ): string {
        $maxtokens = $maxtokens ?: 32000;
        if (self::provider() === 'mock') {
            $text = self::mock($prompt);
            $prices = ['plan' => 1, 'build' => 10, 'refine' => 2, 'repair' => 0, 'suggest' => 1];
            $charged = $task === 'mark' ? array_fill_keys($items, 1) : [$task => $prices[$task] ?? 1];
            if ($task === 'mark') {
                $marks = self::parse_json($text) ?: [];
                $charged = array_intersect_key($charged, $marks);
            }
            lmslabs::$last = ['credits' => array_sum($charged), 'balance' => -1, 'charged' => $charged];
            return $text;
        }
        return lmslabs::run($task, $prompt, $jobid !== '' ? $jobid : "ctx$contextid-u$userid-" . time(), $maxtokens, $items);
    }

    /**
     * Log what the last AI call charged, so teachers see credits used per activity and per answer.
     *
     * @param \stdClass $instance
     * @param int $attemptid
     * @param int $userid teacher
     * @param string $fallbackkey key for a charge that is not per answer (plan, build...)
     */
    public static function log_charge(\stdClass $instance, int $attemptid, int $userid, string $fallbackkey): void {
        global $DB;
        $last = lmslabs::$last;
        if (!$last) {
            return;
        }
        $rows = $last['charged'] ?: ($last['credits'] ? [$fallbackkey => $last['credits']] : []);
        foreach ($rows as $k => $n) {
            if ($n > 0) {
                $DB->insert_record('aiscience_credit', (object)['courseid' => $instance->course,
                    'aiscienceid' => $instance->id, 'attemptid' => $attemptid, 'questionkey' => substr((string)$k, 0, 64),
                    'userid' => $userid, 'credits' => (int)$n, 'timecreated' => time()]);
            }
        }
    }

    /**
     * Test provider: returns a plan or the bundled Angle of Light experiment.
     *
     * @param string $prompt
     * @return string
     */
    protected static function mock(string $prompt): string {
        // Tests can queue exact replies (JSON list) to simulate a misbehaving model.
        $queue = json_decode((string)get_config('mod_aiscience', 'mockreplies'), true);
        if (is_array($queue) && $queue) {
            $next = array_shift($queue);
            set_config('mockreplies', json_encode($queue), 'mod_aiscience');
            if ($next === '__THROW__') {
                throw new \moodle_exception('generalexceptionmessage', 'error', '', 'Mock provider error');
            }
            return (string)$next;
        }
        $lib = \mod_aiscience\local\library::all();
        if (strpos($prompt, 'Mark these answers') !== false) {
            preg_match_all('~^\\[([A-Za-z0-9_-]+)\\] Question~m', $prompt, $m);
            $out = [];
            foreach ($m[1] as $k) {
                $out[$k] = ['mark' => 3, 'why' => 'Mostly right: names the key idea but does not fully explain why.'];
            }
            return json_encode($out);
        }
        if (strpos($prompt, 'Mark this one answer') !== false) {
            return '{"mark":3,"why":"Mostly right: names the key idea but does not say why."}';
        }
        if (strpos($prompt, 'Reply with ONLY one JSON object') !== false) {
            return json_encode($lib['angle-of-light']['plan'] ?? ['title' => 'Test', 'stages' => []]);
        }
        if (strpos($prompt, 'Claim-Evidence-Reasoning rubric') !== false) {
            return '{"claim":3,"evidence":3,"reasoning":2,"reason":"Reasoning does not explain why tilting spreads the light.",'
                . '"feedback":"Your claim is clear and your numbers back it up. Next time, explain why the reading drops."}';
        }
        return \mod_aiscience\local\library::html('angle-of-light');
    }

    /**
     * Pull the first JSON object or array out of a reply.
     *
     * @param string $text
     * @return array|null
     */
    public static function parse_json(string $text): ?array {
        $text = trim($text);
        $text = preg_replace('~^\x60{3}(?:json)?\s*|\s*\x60{3}$~', '', $text);
        $data = json_decode($text, true);
        if (is_array($data)) {
            return $data;
        }
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false) {
            return null;
        }
        $data = json_decode(substr($text, $start, $end - $start + 1), true);
        return is_array($data) ? $data : null;
    }

    /**
     * Strip fences and commentary from an HTML reply.
     *
     * @param string $text
     * @return string
     */
    public static function clean_html(string $text): string {
        $text = trim($text);
        if (preg_match('~\x60{3}(?:html)?\s*(.*?)\x60{3}~is', $text, $m)) {
            $text = trim($m[1]);
        }
        $start = stripos($text, '<!doctype');
        if ($start === false) {
            $start = stripos($text, '<html');
        }
        if ($start !== false) {
            $text = substr($text, $start);
        }
        $end = strripos($text, '</html>');
        if ($end !== false) {
            $text = substr($text, 0, $end + 7);
        }
        return $text;
    }

    /**
     * Run planning for a version.
     *
     * @param int $versionid
     */
    public static function run_plan(int $versionid): void {
        global $DB;
        $version = $DB->get_record('aiscience_version', ['id' => $versionid]);
        if (!$version) {
            return; // The version was deleted before the task ran.
        }
        $instance = $DB->get_record('aiscience', ['id' => $version->aiscienceid], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('aiscience', $instance->id, $instance->course, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        try {
            $reply = self::complete(prompts::plan($instance), $context->id, $version->createdby, 8000, 'plan', "v$versionid");
            self::log_charge($instance, 0, (int)$version->createdby, 'plan');
            $plan = self::parse_json($reply);
            if (!$plan || empty($plan['title']) || empty($plan['stages'])) {
                throw new \moodle_exception(
                    'generalexceptionmessage',
                    'error',
                    '',
                    'The plan came back in the wrong shape. Press Plan experiment again.'
                );
            }
            self::update($versionid, ['status' => 'planned', 'plan' => json_encode($plan), 'error' => '']);
        } catch (\Throwable $e) {
            self::update($versionid, ['status' => 'failed', 'error' => $e->getMessage()]);
        }
    }

    /**
     * Run build, checks and repairs for a version.
     *
     * @param int $versionid
     */
    public static function run_build(int $versionid): void {
        global $DB;
        $version = $DB->get_record('aiscience_version', ['id' => $versionid]);
        if (!$version) {
            return; // The version was deleted before the task ran.
        }
        $instance = $DB->get_record('aiscience', ['id' => $version->aiscienceid], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('aiscience', $instance->id, $instance->course, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        $plan = json_decode((string)$version->plan, true) ?: [];
        try {
            $previous = '';
            $base = (int)($version->basedon ?? 0) ?: (int)$instance->currentversion;
            if (!empty($version->instruction) && $base) {
                $previous = (string)$DB->get_field('aiscience_version', 'html', ['id' => $base, 'aiscienceid' => $instance->id]);
            }
            $task = !empty($version->instruction) ? 'refine' : 'build';
            $html = self::clean_html(self::complete(
                prompts::build($instance, $plan, (string)$version->instruction, $previous),
                $context->id,
                $version->createdby,
                32000,
                $task,
                "v$versionid"
            ));
            self::log_charge($instance, 0, (int)$version->createdby, $task);
            self::update($versionid, ['status' => 'checking', 'html' => $html]);
            $results = checks::run($html);
            $repairs = 0;
            while (!checks::all_pass($results) && $repairs < self::MAXREPAIRS) {
                $repairs++;
                $html = self::clean_html(self::complete(
                    prompts::repair($html, checks::failures($results)),
                    $context->id,
                    $version->createdby,
                    32000,
                    'repair',
                    "v$versionid"
                ));
                self::log_charge($instance, 0, (int)$version->createdby, 'repair');
                $results = checks::run($html);
            }
            $status = checks::all_pass($results) ? 'ready' : 'failed';
            $error = $status === 'failed' ? 'Some checks still fail after ' . $repairs . ' repair rounds.' : '';
            self::update($versionid, ['status' => $status, 'html' => $html, 'checks' => json_encode($results),
                'repairs' => $repairs, 'error' => $error,
                'model' => self::provider() === 'mock' ? 'mock' : 'lms-labs']);
        } catch (\Throwable $e) {
            self::update($versionid, ['status' => 'failed', 'error' => $e->getMessage()]);
        }
    }

    /**
     * Suggest CER rubric scores for a report.
     *
     * @param \stdClass $instance
     * @param string $report
     * @param int $contextid
     * @param int $userid
     * @param int $attemptid the attempt, so the credit is logged against it (0 when none)
     * @return array
     */
    public static function suggest_rubric(
        \stdClass $instance,
        string $report,
        int $contextid,
        int $userid,
        int $attemptid = 0
    ): array {
        $reply = self::complete(
            prompts::rubric((string)$instance->question, $report, (string)$instance->yearlevel),
            $contextid,
            $userid,
            600,
            'suggest',
            "a$attemptid-" . time()
        );
        self::log_charge($instance, $attemptid, $userid, 'suggest');
        $data = self::parse_json($reply) ?: [];
        $clamp = fn($v) => max(0, min(4, (int)$v));
        return ['claim' => $clamp($data['claim'] ?? 0), 'evidence' => $clamp($data['evidence'] ?? 0),
            'reasoning' => $clamp($data['reasoning'] ?? 0), 'reason' => (string)($data['reason'] ?? ''),
            'feedback' => (string)($data['feedback'] ?? '')];
    }

    /**
     * Update fields on a version.
     *
     * @param int $versionid
     * @param array $fields
     */
    protected static function update(int $versionid, array $fields): void {
        global $DB;
        $fields['id'] = $versionid;
        $fields['timemodified'] = time();
        $DB->update_record('aiscience_version', (object)$fields);
    }
}
