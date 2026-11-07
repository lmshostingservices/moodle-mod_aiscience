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
 * Prompts for planning, building, repairing and rubric suggestions.
 *
 * The learning design is encoded here: every experiment makes students predict, manipulate,
 * observe over time, record findings, solve a challenge and argue from their own evidence.
 *
 * @package    mod_aiscience
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class prompts {
    /**
     * The brief as a compact array.
     *
     * @param \stdClass $instance
     * @return array
     */
    public static function brief(\stdClass $instance): array {
        return array_filter([
            'question' => $instance->question,
            'year' => $instance->yearlevel,
            'lesson' => $instance->lessonlength,
            'curriculum' => \core_text::substr((string)$instance->curriculum, 0, 6000),
            'change' => $instance->ivariable,
            'measure' => $instance->dvariable,
            'dataSources' => $instance->datasources,
            'equipment' => $instance->equipment,
            'safety' => $instance->safety,
            'supports' => $instance->supports,
            'extra' => $instance->extra,
        ], fn($v) => trim((string)$v) !== '');
    }

    /**
     * Plan prompt: structured lesson design the teacher approves.
     *
     * @param \stdClass $instance
     * @return string
     */
    public static function plan(\stdClass $instance): string {
        $brief = json_encode(self::brief($instance), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $year = $instance->yearlevel ?: 'the stated year level';
        return <<<P
You are a master science teacher and learning designer. Design an inquiry experiment for this teacher brief:
$brief

Learning comes first. Students must DO the science: predict before seeing evidence, change the variables themselves,
watch the experiment evolve over time, notice and record findings, solve a challenge, then argue from their own data.
Align objectives to the teacher's own curriculum wording when given.

Six stages: Get started (explore), Predict, Set up, Collect, Analyze, Report (Claim-Evidence-Reasoning).

Reply with ONLY one JSON object of this shape:
{"title":"2-4 word name","drivingQuestion":"","objectives":["3-5 measurable objectives in curriculum wording"],
"curriculumLinks":["outcome codes or phrases from the teacher's curriculum this addresses"],
"inquirySkills":["skills addressed"],
"variables":{"independent":"","dependent":"","controlled":["..."]},
"model":{"description":"the science rule in one sentence, pitched at the year level",
 "formula":"formula, or 'pattern only' for young students"},
"interactives":["each drag target or slider and what it changes"],
"noticeWonder":["3 checkpoint questions autoplay pauses on"],
"challenge":"one goal students solve by manipulating the variables, e.g. find the angle that halves the light",
"findingsTable":["column headings students fill in"],
"misconceptions":["2-3 common misconceptions this exposes"],
"safety":["..."],
"stages":[["Stage name","what the student does, one sentence"]],
"cer":["6-9 report questions: claim, evidence x2-3, reasoning, prediction check, evaluation, extension"]}
Pitch all language for $year. Use Australian spelling.
P;
    }

    /**
     * LabKit contract text given to the model.
     *
     * @return string
     */
    public static function labkit(): string {
        return <<<'L'
LabKit API (already defined as window.LabKit before your code runs; ALWAYS feature-check with if(window.LabKit)):
- LabKit.progress({stage:Number 1-6, name:String, readings:Number}) when the student moves to a stage
  (readings = data points recorded so far)
- LabKit.saveResponse(key, value) when the student edits a written answer or findings cell
- LabKit.submit({prediction, answers:{...}, phases:{...summary numbers}, findings:[...rows],
  source:"sim"|"manual"|"sensor", readings:Number, report:String}) once when the report is finished
- LabKit.time(label) as experiment time advances ("12 s", "Day 6") so notebook notes are stamped with it
- LabKit.onControl(fn) fn receives "pause" or "play" from the plugin (e.g. while the student writes a note); obey it
- LabKit.speak(text) reads text aloud in the site's neutral voice (fallback: speechSynthesis)
- await LabKit.dictate() -> Promise<String> transcript of the student speaking; show the Error message if it rejects
- await LabKit.sensors.connect(kind, onValue, onLost) -> Promise<deviceName>, optional Bluetooth sensors owned by the plugin
The plugin already shows a Lab notebook (typed, voice and photo notes) beside your experiment: do not build your own notebook.
The experiment runs in a sandboxed iframe with NO network: no external scripts, fonts, images, fetch or storage you depend on.
Fonts available by name: "Archivo" (700-900, headings), "Atkinson Hyperlegible" (400/700, body),
"JetBrains Mono" (500/700, numbers).
L;
    }

    /**
     * Build prompt: the full experiment.
     *
     * @param \stdClass $instance
     * @param array $plan
     * @param string $instruction teacher change request
     * @param string $previous previous HTML to modify
     * @return string
     */
    public static function build(\stdClass $instance, array $plan, string $instruction = '', string $previous = ''): string {
        $planjson = json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $brief = json_encode(self::brief($instance), JSON_UNESCAPED_UNICODE);
        $year = $instance->yearlevel ?: 'the year level';
        $labkit = self::labkit();
        $change = '';
        if ($instruction !== '' && $previous !== '') {
            $change = "\nThe teacher asked for this change to the current experiment: \"$instruction\".\n"
                . "Here is the current experiment. Keep everything that works and make the change.\n"
                . "<current>\n$previous\n</current>\n";
        }
        return <<<P
Write a complete interactive science experiment as ONE self-contained HTML document for students.
Approved lesson plan:
$planjson
Teacher brief: $brief
$change
$labkit

LEARNING DESIGN (non-negotiable): every interaction exists to make students think like scientists.
- They predict before evidence, manipulate variables themselves, observe change over time, record findings in their own
  words and numbers, test their idea with a challenge, and justify a claim with their own evidence.

Hard requirements:
1. Output ONLY the HTML document, starting with <!doctype html>. No commentary, no markdown fence.
2. Six stages, each a <section data-stage="1".."6"> with a top stepper; one visible at a time; Back/Next buttons.
3. Stage 1 "Get started": a conference-demo-quality animated scene of the real apparatus (canvas + requestAnimationFrame,
   devicePixelRatio-sharp): glow, gradients, particles or flows that make the invisible science visible (photons, molecules,
   current, heat), a soft shadow, a HUD with the key quantity, and one real-world link (a place, season or everyday object).
   Students DRAG the apparatus with pointer events (pointerdown/pointermove, mouse and touch) to change a variable, with a pulsing
   "Drag" hint until first use. EVERY independent variable also has an <input type="range"> slider. Moving anything updates the
   animation, a big animated readout with units, and a mini graph instantly. Use a scientifically correct model.
4. Predict: 3-4 clickable graph-shape choices (small SVG sketches) plus a "why" box with sentence starters.
5. Set up: how to do it with real classroom equipment (manual measurements), with a simulation option always available.
   Optional sensors only via LabKit.sensors.connect.
6. Autoplay: Play/Pause with 1x, 2x, 4x (and a time-lapse for slow processes measured in hours, days or weeks) that runs the
   experiment through every condition, moving the controls and recording data so students watch it evolve. Call LabKit.time.
   Obey LabKit.onControl. Pause at 2-3 "Notice and wonder" checkpoints (from the plan) and ask the student what they notice;
   their answer is saved with LabKit.saveResponse.
7. Collect: an SVG chart (axes, ticks, labelled units) that draws as data arrives, marker buttons for each condition, the
   controls still live, plus a FINDINGS TABLE (<table>) the student fills in for each condition (value + "what I noticed"),
   with manual entry for real measurements.
8. Analyze: their chart, a scrubber ("At X the reading was Y"), averages per condition, comparison with the model, and the
   CHALLENGE from the plan: a target the student must hit by manipulating the variables, with feedback when they succeed.
9. Report: CER questions one at a time with numbered pills, evidence hints auto-filled from the student's own numbers and
   findings, a prediction-versus-result reflection, a final assembled report and a Copy button. Call LabKit.submit at the end.
10. Voiceover: a "Read aloud" toggle and a "Listen" button on every stage that read instructions and questions aloud with
   LabKit.speak (fallback speechSynthesis with one neutral English voice, rate 0.95), highlighting each line as it is read.
   A "Speak" button beside EVERY textarea that calls LabKit.dictate.
11. Visual quality: world-class. Archivo for headings, Atkinson Hyperlegible for body, JetBrains Mono for numbers. CSS variables,
   layered soft shadows, hover lift on cards, pressable buttons, stage entrance motion, prefers-reduced-motion respected,
   visible focus, works at 400px wide, light theme. Readable for $year.
12. Robust JS: no external resources, wrap localStorage in try/catch, no alert/confirm/prompt, no errors in the console.
   Keep the file under about 1,000 lines. Never navigate the page (no location changes, no window.open).
13. Teacher-editable: the teacher edits wording and slider ranges later without AI. Write every sentence a student reads
   as one whole string or one run of HTML text (never build a sentence by joining strings, never put a sentence in an id,
   class or comparison). Give each slider its settings in one object literal, for example
   {name:"Water temperature", unit:"°C", min:10, max:90, step:1, default:25}, and read the slider's range from it.
14. Prediction check: in Analyze, compare the shape of the student's OWN data with the prediction they picked (show both as
   small graphs side by side). Say plainly whether the evidence supports it. Ask "What in your data convinced you?" if it
   does, or "What surprised you, and what do you think now?" if not. Save LabKit.saveResponse("_predmatch", "yes" or "no"),
   LabKit.saveResponse("_datashape", a short phrase such as "goes up, but less and less") and the answer as "predrethink".
   Group the final report under the headings Prediction, Claim, Evidence, Reasoning.
15. Before you send it: when the student finishes the report questions, show a short check before sending. Is the claim a
   full sentence naming both variables? Does the evidence use at least two of their own numbers? Does the reasoning
   explain why ("because…")? If their prediction was not supported, did they say what changed their mind? Show a "Fix
   this" button for each gap, and let them send anyway. Save LabKit.saveResponse("_checkfirst", tips at the first check)
   and LabKit.saveResponse("_checksend", tips left when sent).
16. Marking guide: include one <script type="application/json" id="labkit-marking"> with
   {"iv":"…","dv":"…","unit":"…","idea":"the science idea in a few words","questions":[{"k":"<the report question's
   saveResponse key>","q":"<question>","part":"claim|evidence|reasoning|other","model":"<model answer>",
   "ideas":["word|synonym|synonym", …one entry per key idea…],"wrong":["<misconception phrase>", …],
   "numbers":<how many of the student's own numbers the answer should use>,"because":true|false}, …]}.
   Every report question needs an entry. LabKit.progress must also send {readings: <data points recorded so far>}.
P;
    }

    /**
     * Repair prompt after failed checks.
     *
     * @param string $html
     * @param string $failures
     * @return string
     */
    public static function repair(string $html, string $failures): string {
        return "This science experiment failed automated checks:\n$failures\n\nFix every failure without removing working "
            . "features. Reply with ONLY the complete corrected HTML document, starting with <!doctype html>.\n\n"
            . self::labkit() . "\n<experiment>\n$html\n</experiment>";
    }

    /**
     * Mark several answers from one student's report in one request (1 credit per answer marked).
     *
     * @param array $items list of [question => guide question, answer => string, hint => comparison findings]
     * @param string $data the student's own results
     * @param string $year
     * @return string
     */
    public static function markreport(array $items, string $data, string $year): string {
        $out = "Mark these answers from one $year student's science report. Mark each answer out of 4.\n"
            . "The student's own results: $data\n\n";
        foreach ($items as $it) {
            $q = $it['question'];
            $ideas = $q['ideas'] ? implode('; ', array_map(fn($g) => str_replace('|', ' / ', $g), $q['ideas'])) : 'none listed';
            $out .= "[{$q['k']}] Question: {$q['q']}\nPart: {$q['part']}\nModel answer: {$q['model']}\nKey ideas: $ideas\n"
                . ($q['numbers'] ? "Should use {$q['numbers']} number(s) from the student's own results.\n" : '')
                . ($q['because'] ? "Should explain why, not only describe.\n" : '')
                . ($it['hint'] !== ''
                    ? "Automatic check (numbers compared with their results, misconceptions): {$it['hint']}\n" : '')
                . "Student's answer: {$it['answer']}\n\n";
        }
        return $out . "Give credit for correct ideas in the student's own words, even if they differ from the model answer. "
            . "Trust the automatic check about whether numbers match their results. "
            . "4 = complete and correct, 3 = mostly, 2 = partly, 1 = a little, 0 = missing or wrong.\n"
            . "Reply with ONLY JSON: {\"KEY\":{\"mark\":0-4,\"why\":\"one short sentence for the teacher\"}, ...} "
            . "using each [KEY] above.";
    }

    /**
     * Mark one answer (AI marking, 1 credit).
     *
     * @param array $q guide question
     * @param string $answer
     * @param string $data the student's own results
     * @param string $year
     * @return string
     */
    public static function markanswer(array $q, string $answer, string $data, string $year): string {
        $ideas = $q['ideas'] ? implode('; ', array_map(fn($g) => str_replace('|', ' / ', $g), $q['ideas'])) : 'none listed';
        return "Mark this one answer from a $year student's science report, out of 4.\n"
            . "Question: {$q['q']}\nPart of the report: {$q['part']}\nModel answer: {$q['model']}\n"
            . "Key ideas to look for: $ideas\n"
            . ($q['numbers'] ? "It should use {$q['numbers']} number(s) from the student's own results.\n" : '')
            . ($q['because'] ? "It should explain why, not only describe.\n" : '')
            . "The student's own results: $data\nStudent's answer: $answer\n\n"
            . "Give credit for correct ideas in the student's own words, even if they differ from the model answer. "
            . "4 = complete and correct, 3 = mostly, 2 = partly, 1 = a little, 0 = missing or wrong.\n"
            . "Reply with ONLY JSON: {\"mark\":0-4,\"why\":\"one short sentence for the teacher\"}";
    }

    /**
     * Rubric suggestion prompt.
     *
     * @param string $question
     * @param string $report
     * @param string $year
     * @return string
     */
    public static function rubric(string $question, string $report, string $year): string {
        return "Score this $year student's science report against a Claim-Evidence-Reasoning rubric, 0-4 per criterion.\n"
            . "Investigation: $question\nReport:\n$report\n\n"
            . "Reply with ONLY JSON: {\"claim\":0-4,\"evidence\":0-4,\"reasoning\":0-4,\"reason\":\"one short sentence naming "
            . "the main gap or strength\",\"feedback\":\"two warm, specific sentences of feedback to the student\"}";
    }
}
