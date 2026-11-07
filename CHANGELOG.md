# Changelog

## 1.3.3 (2026-11-07)

Lab notebook and quality
- Students can delete their own notebook notes, photos and voice recordings (two clicks), until their work is graded. The recording or photo is deleted with it. Before this, a full notebook told students to delete items but gave them no way to.
- A note added while the notebook was still loading no longer disappears from the list until the page is reloaded.
- Messages about the notebook come from the language pack.
- Code meets the Moodle coding standard with no errors or warnings.
- Release files: added LICENSE (GNU GPL v3) and thirdpartylibs.xml (the three bundled SIL Open Font License typefaces). Every intentional raw parameter now states why it is raw.

## 1.3.2 (2026-11-07)

Release pipeline
- First stable release.
- Lab notebook text keeps symbols such as < and > (needed for science notes); it is stored as plain text and escaped on display.
- The changelog is now CHANGELOG.md, and the install steps say where to add the LMS Labs API key.

## 1.3.1-beta (2026-11-07)

Matches the live LMS Labs service
- LMS Labs recognises a site by its Moodle address, so the Site ID can now be left blank: the plugin sends this site's address. A Site ID that is a web address is accepted.
- Sites with unlimited LMS Labs credits show "Connected to LMS Labs · unlimited credits" and "Credits: unlimited", instead of an unknown balance.

## 1.3.0-beta (2026-11-06)

LMS Labs AI only
- All AI (planning, building, refining, AI marking and rubric suggestions) now runs through LMS Labs and uses the site's LMS Labs credits: plan 1 credit, build 10, refine 2, AI marking 1 per answer marked, rubric suggestion 1. Repairs after failed checks are not charged again. Prices come from LMS Labs and show on every AI button.
- Removed the Moodle AI subsystem and direct Anthropic options, the model settings and the admin-entered credit balance. Upgrading deletes any saved Anthropic key.
- New settings: LMS Labs Site ID and API key (or the shared LMS Labs AI settings plugin), with a connection light showing the credits left.
- Credits are charged by LMS Labs only when a request succeeds. Outages, unreadable replies and blank answers use no credits. The plugin keeps a log of credits used per activity and answer.
- If the site runs out of credits part-way through AI marking, the remaining reports are not sent.

## 1.2.0-beta (2026-11-06)

Teachers
- Auto-marking in Results & grading. Every answer gets a suggested mark from the built-in comparison engine (no AI, no credits), with the model answer and the reason shown beside it. Answers the engine is unsure about are flagged.
- AI marking: "Mark all answers with AI" or "Only the unsure ones". One credit per answer marked; blank answers are marked 0 with no credit used; a failed or unreadable AI reply refunds the credit. Every credit used is logged against the course, activity, answer and teacher.
- "Approve all suggested marks" saves each answer's mark and the grade to the gradebook in one step (with a confirm click). Nothing reaches the gradebook until a teacher approves it.
- Class insights tab: where students go wrong (per question, the common wrong answers and misconceptions), item analysis in plain English (how hard each question was and whether it separated strong and weak students), and a verdict per question: Reword the question, Teach it again, Check the marking, Working well. Every term has a tooltip. CSV export.
- Edit & refine: the Words editor is filtered by step, so you can find a sentence quickly.

Students
- Before-you-send check on the report: it points out missing evidence or reasoning before the report is submitted (the teacher sees whether they used it).
- Moving between steps puts keyboard and screen-reader focus on the step heading.

Fixes
- A short numeric answer (for example "6420 lux") was marked down for being short.
- "It changed" was matched as the misconception "no change".
- The marking bar counts now update after marking a single answer with AI.
- Privacy API covers per-answer marks and the credit log (credit rows are kept for billing but anonymised when a teacher is deleted).
- Ready-made experiments now carry a marking guide; a new automated check (twelve in all) makes sure AI-built experiments include one.

Testing on Moodle 4.5, 5.2 and 5.3: 173 stress tests, 28 integration tests, 15 or 16 Moodle AI end-to-end tests (fake OpenAI endpoint), 22 HTTP tests (30 students at once), a 20-process answer race and a 20-process credit race, 24 browser tests of marking and insights, 15 of Edit & refine, the browser walkthrough, and an accessibility audit (axe-core, 0 violations), all passing.

## 1.1.0-beta (2026-11-05)

Teachers
- New Edit & refine tab: reword any sentence students read, change slider ranges and starting values, ask AI to refine any version (with quick ideas), or edit the page code. Every save is a new version that runs the safety and learning checks; publish when ready. Students part way through keep their answers and notes.
- Results show Thinking shifts: who predicted the pattern, who changed their mind, and who still needs a conversation, by name, plus the most common mistaken prediction to open the next lesson with.
- The predictions chart marks the prediction the evidence supported. The grader shows each student's prediction check and a plain-English descriptor for every rubric level.
- Non-editing teachers now open Results & grading (they previously saw the student view).

Students
- Prediction check in Analyze (all four experiments): their own data is compared with the shape they predicted, and they explain what surprised them or what convinced them.
- Reports are grouped as Prediction, Claim, Evidence, Reasoning and Going further.

Fixes found by stress testing on Moodle 4.5, 5.2 and 5.3
- Answers sent at the same moment could overwrite each other or create a second attempt. Each student's saves are now serialised with Moodle's lock API.
- Typing "<" (for example "reading < 50") lost text or rejected a note, feedback or answer. Typed text is now kept exactly and always escaped when shown.
- Results failed to load when a student typed HTML into a prediction.
- Separate groups were ignored in Results and grading. Results now list only enrolled students.
- Scale grading and ungraded activities now work (feedback is saved without a grade).
- Students could attach answers to a draft or another activity's version.
- Course reset left photo and voice files behind.
- Media uploads are checked by their real content, and capped at 60 per student.
- Experiment checks now also block page navigation (another way data could leave the sandbox); a refresh meta tag is stripped.
- Number of answer boxes per attempt capped; negative readings rejected.
- Moodle 5.3's sticky footer covered the editor's save bar; phone layouts no longer scroll sideways.
- Error messages now say what went wrong instead of "Invalid parameter value".
- Label colours meet WCAG 2.1 AA contrast (axe-core: 0 violations on every teacher and student screen).
- Upgrading refreshes ready-made experiments in existing activities; teacher edits are never touched.

Testing: 154 stress tests, 28 integration tests, 22 HTTP tests (30 students at once), a 20-process race test, a 17-step browser walkthrough, 15 browser tests of Edit & refine, and an accessibility audit of 9 screens, all passing on Moodle 4.5, 5.2 and 5.3.

## 1.0.0-beta (2026-11-05)

- First release: teacher studio, AI pipeline (plan, build, checks, repair, publish, versions), sandboxed player with LabKit, lab notebook, voiceover and dictation, results and CER grading.
- Four ready-made experiments: Angle of Light, Pendulum Swing, Dissolving Race, Light and Plant Growth.
- Tested on Moodle 4.5, 5.2 and 5.3: install and upgrade, 28 integration tests, 17-step browser walkthrough as teacher and student.
