# AI Science Experiment for Moodle™

`mod_aiscience` is a Moodle activity that turns a teacher's brief into an interactive science experiment. Students explore, predict, measure, record findings and write a Claim-Evidence-Reasoning report that the teacher grades into the Moodle gradebook.

## Requirements

- Moodle 4.5 LTS to 5.3 LTS (tested on 4.5.15+, 5.2.4+ and 5.3+)
- PHP 8.1 or later (tested on 8.3)
- For building your own experiments and AI marking: an LMS Labs account with credits. Add the LMS Labs Site ID and API key in the plugin settings (or install the shared LMS Labs AI settings plugin). Ready-made experiments and the built-in marking engine use no AI and no credits.
- Credit prices: plan 1, build 10, refine 2, AI marking 1 per answer, rubric suggestion 1.

## Install

1. Unzip into `mod/aiscience` (Moodle 4.5) or `public/mod/aiscience` (Moodle 5.1 and later).
2. Visit Site administration > Notifications to install.
3. Site administration > Plugins > Activity modules > AI Science Experiment: add your LMS Labs API key (the Site ID defaults to this site's address) and choose the voiceover voice.

## What's included

- **Four ready-made experiments**: Angle of Light (Year 8), Pendulum Swing (Year 10), Dissolving Race (Year 7), Light and Plant Growth (Year 4).
- **Build your own with AI**: teacher brief, paste or upload your curriculum, plan review, build, twelve automated checks, publish, versions and rollback, change by plain-English request.
- **Edit & refine after publishing**: reword any sentence, change slider ranges, ask AI to refine any version, or edit the code. Each save is a checked new version; students keep their work when you publish.
- **Student experience**: six steps, drag-and-drop apparatus, sliders, autoplay with notice-and-wonder checkpoints, findings table, a prediction check that compares their data with what they predicted, challenge, a report grouped as Claim, Evidence and Reasoning, read-aloud, speak-your-answer.
- **Lab notebook**: typed notes, dictation, voice recordings and photos, stamped with the step and experiment time. Students can delete their own notes until their work is graded.
- **Results and grading**: Thinking shifts (who changed their mind, who still needs a conversation), pooled class data, prediction breakdown, report, findings and notebook side by side, CER rubric (0–4 each, with level descriptors), optional AI-suggested scores, gradebook sync.
- **Auto-marking**: every answer gets a suggested mark from the built-in comparison engine (no AI credits). AI marking through LMS Labs at one credit per answer marked; nothing is charged if the AI fails. Teachers approve marks before they reach the gradebook.
- **Class insights**: where students go wrong, item analysis (difficulty and discrimination) explained in plain English, and a suggestion per question: reword it, teach it again, or check the marking. CSV export.
- **Moodle integration**: gradebook, custom completion (records data, submits report), backup and restore, privacy API (export and delete), events, course reset.

## Safety

Experiments run in a sandboxed iframe (`allow-scripts`, no same-origin) with a Content-Security-Policy that blocks all network access. They talk to Moodle only through the LabKit message bridge, which the host validates.

## Licence

GNU GPL v3 or later. Bundled fonts (Archivo, Atkinson Hyperlegible, JetBrains Mono) are under the SIL Open Font License; see `fonts/`.

Moodle™ is a registered trademark of Moodle Pty Ltd. LMS Labs is not affiliated with or endorsed by Moodle Pty Ltd.
