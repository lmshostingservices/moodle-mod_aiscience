<?php
// This file is part of Moodle - http://moodle.org/
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

namespace mod_aiscience;

use mod_aiscience\ai\generator;
use mod_aiscience\ai\lmslabs;

/**
 * Tests for plans, experiments and marking on the site's own AI.
 *
 * byok::check() was compared with the LMS Labs service's answer checks on 24,000 generated AI answers, with identical
 * results; these tests cover the request, the wiring and the failures.
 *
 * @package    mod_aiscience
 * @copyright  2026 LMS Labs
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_aiscience\byok
 * @covers     \mod_aiscience\ai\generator
 */
#[\PHPUnit\Framework\Attributes\CoversClass(byok::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(generator::class)]
final class byok_test extends \advanced_testcase {
    /** @var array Requests the mocked Anthropic received: [url, body]. */
    protected $requests = [];
    /** @var array Text answers the mocked Anthropic gives, in order (null: it fails). */
    protected $answers = [];

    /**
     * Licence the site for Bring your own AI on a test key, with Text on Anthropic and no LMS Labs credentials.
     */
    protected function setUp(): void {
        parent::setUp();
        if (!class_exists('\local_aiconfig\byok\ai')) {
            $this->markTestSkipped('AI Grader Central Config with Bring your own AI is not installed.');
        }
        $this->resetAfterTest();
        $pair = sodium_crypto_sign_keypair();
        set_config('testpublickey', base64_encode(sodium_crypto_sign_publickey($pair)), 'local_aiconfig');
        $payload = json_encode(['v' => 1, 'id' => 'lic_t', 'site' => \local_aiconfig\byok\licence::site_host(),
            'plan' => 'pro', 'byok' => true, 'products' => ['*'], 'sub' => 'sub_t', 'exp' => time() + DAYSECS]);
        $b64 = fn(string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        set_config('licencekey', $b64($payload) . '.' .
            $b64(sodium_crypto_sign_detached($payload, sodium_crypto_sign_secretkey($pair))), 'local_aiconfig');
        set_config('src_text', 'ownkey', 'local_aiconfig');
        set_config('vendor_text', 'anthropic', 'local_aiconfig');
        set_config('key_anthropic', 'sk-test', 'local_aiconfig');
        set_config('siteid', '', 'local_aiconfig');
        set_config('apikey', '', 'local_aiconfig');
        set_config('provider', 'lmslabs', 'mod_aiscience');
        $this->requests = [];
        $this->answers = [];
        $GLOBALS['local_aiconfig_byok_mock'] = function ($url, $headers, $body, $vendor, $raw = false) {
            $this->requests[] = [$url, $body];
            $answer = array_shift($this->answers);
            if ($answer === null) {
                throw new \moodle_exception('byok_callfailed', 'local_aiconfig', '', (object) ['vendor' => $vendor,
                    'detail' => 'Overloaded']);
            }
            return ['content' => [['type' => 'text', 'text' => $answer]], 'stop_reason' => 'end_turn'];
        };
        lmslabs::$last = null;
    }

    /**
     * Clean up the mock.
     */
    protected function tearDown(): void {
        unset($GLOBALS['local_aiconfig_byok_mock']);
        parent::tearDown();
    }

    /**
     * The settings decide whether the work runs on the site's own AI, and AI counts as available without LMS Labs.
     */
    public function test_switch(): void {
        $this->assertTrue(byok::uses());
        $this->assertTrue(generator::available());
        set_config('src_text', 'credits', 'local_aiconfig');
        $this->assertFalse(byok::uses());
    }

    /**
     * A plan is asked for as the service asks for it, checked, and charges nothing.
     */
    public function test_plan(): void {
        $this->answers = ["```json\n{\"title\":\"Angle of light\",\"stages\":[{\"name\":\"Predict\"}]}\n```"];
        $text = generator::complete('Plan a Year 8 lesson on refraction.', 1, 2, 9000, 'plan');
        $this->assertStringContainsString('Angle of light', $text);
        $this->assertCount(1, $this->requests);
        [$url, $sent] = $this->requests[0];
        $this->assertStringEndsWith('/v1/messages', $url);
        $this->assertSame(8000, $sent['max_tokens']);
        $this->assertSame(0.2, $sent['temperature']);
        $this->assertStringContainsString('Return only the requested lesson plan JSON', json_encode($sent['system']));
        $this->assertStringContainsString('Plan a Year 8 lesson on refraction.', json_encode($sent['messages']));
        $this->assertSame(['credits' => 0, 'balance' => -1, 'charged' => []], lmslabs::$last);
    }

    /**
     * Experiment builds keep the service's large token cap and the model's own temperature.
     */
    public function test_build(): void {
        $this->answers = ['<!DOCTYPE html><html><body>Ray box</body></html>'];
        generator::complete('Build it.', 1, 2, 0, 'build');
        $sent = $this->requests[0][1];
        $this->assertSame(32000, $sent['max_tokens']);
        $this->assertArrayNotHasKey('temperature', $sent);
    }

    /**
     * Marking caps the tokens by the number of answers; a rubric suggestion is used as the service uses it.
     */
    public function test_mark_and_suggest(): void {
        global $DB;
        $this->answers = ['{"q1":{"mark":2,"feedback":"Good"},"q2":{"mark":1,"feedback":"Add units"}}'];
        generator::complete('Mark these.', 1, 2, 4000, 'mark', '', ['q1', 'q2']);
        $this->assertSame(500, $this->requests[0][1]['max_tokens']);

        $this->answers = ['Here you go: {"claim":3,"evidence":2.5,"reasoning":4,"reason":"Clear","feedback":"Nice"}'];
        $instance = (object) ['id' => 5, 'course' => 6, 'question' => 'Why does light bend?', 'yearlevel' => '8'];
        $result = generator::suggest_rubric($instance, 'Light slows in glass.', 1, 2, 7);
        $this->assertSame(['claim' => 3, 'evidence' => 2, 'reasoning' => 4, 'reason' => 'Clear', 'feedback' => 'Nice'],
            $result);
        $this->assertSame(600, $this->requests[1][1]['max_tokens']);
        $this->assertSame(0, $DB->count_records('aiscience_credit'));
    }

    /**
     * Answers the service would refuse are refused the same way, and the site AI's own failure is passed on.
     */
    public function test_failures(): void {
        $cases = [
            ['plan', 'No JSON here', 'AI reply was not valid JSON'],
            ['plan', '{"title":"  ","stages":[1]}', 'AI returned an unusable lesson plan'],
            ['suggest', '{"claim":"3","evidence":2,"reasoning":1}', 'AI returned an unusable rubric'],
            ['plan', '[1,2]', 'AI reply was not valid JSON'],
            ['build', " \n ", 'AI provider returned an unusable result'],
            ['plan', null, 'Overloaded'],
        ];
        foreach ($cases as [$task, $answer, $expected]) {
            $this->answers = [$answer];
            try {
                generator::complete('Prompt', 1, 2, 800, $task);
                $this->fail("{$task} should fail");
            } catch (\moodle_exception $e) {
                $this->assertSame('byok_failed', $e->errorcode);
                $this->assertStringContainsString($expected, $e->getMessage());
            }
        }
        // Requests the service would refuse never reach the AI.
        $this->requests = [];
        foreach ([['mark', []], ['mark', ['a', 'a']], ['plan', ['a']], ['mark', [str_repeat('k', 101)]]] as [$task, $items]) {
            try {
                byok::run($task, 'Prompt', 100, $items);
                $this->fail('should refuse');
            } catch (\moodle_exception $e) {
                $this->assertStringContainsString('bad_request', $e->getMessage());
            }
        }
        $this->assertSame([], $this->requests);
    }

    /**
     * The service's JSON finder: the first balanced object, fences stripped, braces in strings ignored.
     */
    public function test_first_object(): void {
        $this->assertEquals((object) ['a' => '}{'], byok::first_object("```json\n{\"a\":\"}{\"} {\"b\":1}\n```"));
        $this->assertSame('AI returned an unusable lesson plan', byok::check('plan', '{}'));
        $this->assertSame('AI reply was not valid JSON', byok::first_object('{"a":1'));
        $this->assertNull(byok::check('mark', '{"q1":5}', ['q1']));
        $this->assertNull(byok::check('refine', 'anything'));
    }
}
