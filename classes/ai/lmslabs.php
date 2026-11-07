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

/**
 * The LMS Labs AI service. All AI in this plugin runs through it, and LMS Labs holds and charges the
 * site's credits. Credentials are the shared LMS Labs Site ID and API key (local_aiconfig), or this
 * plugin's own two settings.
 *
 * @package    mod_aiscience
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class lmslabs {
    /** LMS Labs API base. There is no admin setting for it; automated tests set $CFG->mod_aiscience_endpoint. */
    const BASE = 'https://lms-labs.com';

    /** Product id at LMS Labs. */
    const PLUGINID = 'aiscience';

    /** Balance value meaning the site has unlimited LMS Labs credits (-1 means not known). */
    const UNLIMITED = -2;

    /** @var array|null What the last AI call charged: {credits, balance, charged: {key: credits}}. */
    public static $last = null;

    /**
     * The API base URL.
     *
     * @return string
     */
    protected static function base(): string {
        global $CFG;
        $forced = (string)($CFG->mod_aiscience_endpoint ?? '');
        return $forced !== '' ? rtrim($forced, '/') : self::BASE;
    }

    /**
     * Shared LMS Labs credentials, or this plugin's own.
     *
     * @return array [siteid, apikey]
     */
    public static function credentials(): array {
        global $CFG;
        if (class_exists('\\local_aiconfig\\config')) {
            $siteid = trim((string)\local_aiconfig\config::get_site_id());
            $apikey = trim((string)\local_aiconfig\config::get_api_key());
            if ($apikey !== '') {
                return [$siteid !== '' ? $siteid : $CFG->wwwroot, $apikey];
            }
        }
        // LMS Labs identifies a site by its registered Moodle URL, so a blank Site ID means this site's URL.
        $siteid = trim((string)get_config('mod_aiscience', 'siteid'));
        return [$siteid !== '' ? $siteid : $CFG->wwwroot, trim((string)get_config('mod_aiscience', 'apikey'))];
    }

    /**
     * Whether a Site ID and API key are present.
     *
     * @return bool
     */
    public static function configured(): bool {
        [$siteid, $apikey] = self::credentials();
        return $siteid !== '' && $apikey !== '';
    }

    /**
     * POST JSON. The API key goes in the Authorization header only, never in a URL or a log.
     *
     * @param string $path
     * @param array $body
     * @param int $timeout seconds
     * @return array [http code (0 = no connection), decoded body or null]
     */
    protected static function post(string $path, array $body, int $timeout): array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        [$siteid, $apikey] = self::credentials();
        if ($siteid === '' || $apikey === '') {
            return [0, null];
        }
        $body = ['pluginId' => self::PLUGINID, 'siteId' => $siteid, 'siteUrl' => $CFG->wwwroot] + $body;
        $curl = new \curl(['ignoresecurity' => !empty($CFG->mod_aiscience_endpoint)]);
        $response = $curl->post(static::base() . $path, json_encode($body), [
            'CURLOPT_HTTPHEADER' => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $apikey,
            ],
            'CURLOPT_CONNECTTIMEOUT' => 10,
            'CURLOPT_TIMEOUT' => $timeout,
            'CURLOPT_FOLLOWLOCATION' => 0,
            'CURLOPT_SSL_VERIFYPEER' => 1,
            'CURLOPT_SSL_VERIFYHOST' => 2,
        ]);
        $info = $curl->get_info();
        $code = $curl->get_errno() ? 0 : (int)($info['http_code'] ?? 0);
        $data = is_string($response) ? json_decode($response, true) : null;
        return [$code, is_array($data) ? $data : null];
    }

    /**
     * Connection, balance and prices. Cached for a minute.
     *
     * @param bool $fresh skip the cache
     * @return array {connected: bool, balance: int (-1 = unknown, -2 = unlimited), prices: array, code: int}
     */
    public static function status(bool $fresh = false): array {
        $cache = \cache::make('mod_aiscience', 'lmslabs');
        $key = 'status_' . sha1(implode("\0", self::credentials()));
        if (!$fresh && ($hit = $cache->get($key)) && ($hit['time'] ?? 0) > time() - 60) {
            return $hit;
        }
        if (!self::configured()) {
            return ['connected' => false, 'balance' => -1, 'prices' => [], 'code' => 0, 'time' => time()];
        }
        [$code, $data] = static::post('/api/ai/status', ['feature' => 'science'], 15);
        $out = [
            'connected' => $code === 200 && !empty($data['connected']),
            'balance' => self::balance_of($data),
            'prices' => is_array($data['prices'] ?? null) ? array_map('intval', $data['prices']) : [],
            'code' => $code,
            'time' => time(),
        ];
        $cache->set($key, $out);
        return $out;
    }

    /**
     * The balance in a reply: a number, UNLIMITED, or -1 when it is not known.
     * LMS Labs sends balanceState "unlimited" (with balance -1) for sites without a credit limit.
     *
     * @param array|null $data
     * @return int
     */
    protected static function balance_of(?array $data): int {
        if (!is_array($data)) {
            return -1;
        }
        if (strtolower((string)($data['balanceState'] ?? '')) === 'unlimited') {
            return self::UNLIMITED;
        }
        return isset($data['balance']) && is_numeric($data['balance']) && (int)$data['balance'] >= 0 ? (int)$data['balance'] : -1;
    }

    /**
     * Forget the cached status, so the next read shows the new balance.
     */
    public static function forget(): void {
        \cache::make('mod_aiscience', 'lmslabs')->purge();
    }

    /**
     * Run one AI task. LMS Labs charges the site's credits only when the task succeeds.
     *
     * @param string $task plan | build | refine | repair | mark | suggest
     * @param string $prompt
     * @param string $jobid groups a build with its repairs, so repairs are not charged again
     * @param int $maxtokens
     * @param array $items for mark: the answer keys; LMS Labs charges 1 credit per key it returns a mark for
     * @return string the AI's text
     */
    public static function run(string $task, string $prompt, string $jobid, int $maxtokens, array $items = []): string {
        global $CFG;
        self::$last = null;
        if (!self::configured()) {
            throw new \moodle_exception('lmslabs_notconnected', 'mod_aiscience');
        }
        $body = [
            'feature' => 'science',
            'task' => $task,
            'jobId' => sha1($CFG->wwwroot . "\0" . $jobid),
            'requestId' => sha1($CFG->wwwroot . "\0" . $jobid . "\0" . $task . "\0" . microtime(true) . random_int(0, PHP_INT_MAX)),
            'prompt' => $prompt,
            'maxTokens' => $maxtokens,
        ];
        if ($items) {
            $body['items'] = array_values($items);
        }
        [$code, $data] = static::post('/api/ai/science', $body, 900);
        if ($code === 402) {
            self::forget();
            throw new \moodle_exception('lmslabs_nocredits', 'mod_aiscience');
        }
        if ($code === 401 || $code === 403) {
            throw new \moodle_exception('lmslabs_notconnected', 'mod_aiscience');
        }
        if ($code !== 200 || !is_array($data) || !isset($data['text'])) {
            $why = is_array($data) && is_string($data['message'] ?? null) ? ' ' . clean_param($data['message'], PARAM_TEXT) : '';
            throw new \moodle_exception('lmslabs_failed', 'mod_aiscience', '', trim(($code ?: 'no connection') . $why));
        }
        $charged = [];
        foreach ((array)($data['charged'] ?? []) as $k => $n) {
            $charged[clean_param((string)$k, PARAM_ALPHANUMEXT)] = max(0, (int)$n);
        }
        self::$last = [
            'credits' => max(0, (int)($data['creditsCharged'] ?? array_sum($charged))),
            'balance' => self::balance_of($data),
            'charged' => $charged,
        ];
        self::forget();
        return (string)$data['text'];
    }

    /**
     * The connection light for the settings page.
     *
     * @return string HTML
     */
    public static function status_html(): string {
        $c = 'mod_aiscience';
        try {
            $st = self::status(true);
        } catch (\Throwable $e) {
            $st = ['connected' => false, 'balance' => -1, 'code' => 0];
        }
        if (!empty($st['connected'])) {
            if ($st['balance'] === self::UNLIMITED) {
                $text = get_string('lmslabs_connected_unlimited', $c);
            } else if ($st['balance'] >= 0) {
                $text = get_string('lmslabs_connected_balance', $c, $st['balance']);
            } else {
                $text = get_string('lmslabs_connected', $c);
            }
            $colour = '#15803d';
        } else {
            $text = self::configured() ? get_string('lmslabs_rejected', $c) : get_string('lmslabs_missing', $c);
            $colour = '#b45309';
        }
        return \html_writer::div(
            \html_writer::span('', '', ['style' => "display:inline-block;width:10px;height:10px;border-radius:50%;" .
                "background:$colour;margin-right:8px;vertical-align:middle", 'aria-hidden' => 'true']) .
            \html_writer::span(s($text), '', ['style' => "font-weight:600;color:$colour;vertical-align:middle"]),
            '',
            ['style' => 'margin:0 0 12px', 'role' => 'status']
        );
    }
}
