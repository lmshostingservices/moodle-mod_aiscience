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
 * Free auto-marking and item analysis, in the teacher's browser. Each answer is compared with the
 * experiment's marking guide using the Fast Grade comparison engine (no AI, no credits): key ideas with
 * synonyms, misconceptions, the student's own numbers, "because" reasoning and closeness to the model
 * answer. Optional on-device AI (Chrome's built-in model) double-checks unsure answers for free.
 *
 * @module     mod_aiscience/marker
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['mod_aiscience/fgengine'], function(FG) {
    'use strict';

    var parse = function(s, d) {
        try {
            var v = JSON.parse(s);
            return v === null || v === undefined ? d : v;
        } catch (e) {
            return d;
        }
    };
    var words = function(t) {
        return (String(t || '').match(/[\p{L}\p{N}]+/gu) || []).length;
    };
    var numbersIn = function(t) {
        return (String(t || '').replace(/(\d),(\d{3})/g, '$1$2').match(/-?\d+(?:\.\d+)?/g) || []).map(Number);
    };
    var label = function(group) {
        return String(group).split(/\s*[|/]\s*/)[0];
    };
    var BECAUSE = /\b(because|so|therefore|this means|which means|due to|as a result|since|causes?|makes?)\b/i;
    var PART = {claim: 'Claim', evidence: 'Evidence', reasoning: 'Reasoning', other: 'Going further'};

    /**
     * The student's own numbers: their measurements, plus each as a percentage of the largest.
     *
     * @param {Object} attempt
     * @param {Array} match phase names to use, or all
     * @returns {number[]}
     */
    var dataValues = function(attempt, match) {
        var ph = parse(attempt.phases, {});
        var vals = [];
        Object.keys(ph).forEach(function(k) {
            var v = Number(ph[k]);
            if (isFinite(v) && (!match || !match.length || match.indexOf(k) > -1)) {
                vals.push(v);
            }
        });
        var ans = parse(attempt.answers, {});
        if (!match || !match.length) {
            parse(ans._findings || '[]', []).forEach(function(r) {
                if (Array.isArray(r) && r[1]) {
                    numbersIn(r[1]).forEach(function(v) {
                        vals.push(v);
                    });
                }
            });
        }
        var all = Object.keys(ph).map(function(k) {
            return Number(ph[k]);
        }).filter(isFinite);
        var top = all.length ? Math.max.apply(null, all) : 0;
        if (top > 0) {
            vals.slice().forEach(function(v) {
                vals.push(v / top * 100);
            });
        }
        return vals;
    };
    var matchesData = function(n, vals) {
        return vals.some(function(v) {
            return Math.abs(n - v) <= Math.max(Math.abs(v) * 0.12, 0.06);
        });
    };

    /**
     * The guide for an attempt, or a basic one built from its answers when the page has none.
     *
     * @param {Object} results results service data
     * @param {Object} attempt
     * @returns {Object}
     */
    var guideFor = function(results, attempt) {
        var guides = results._guides || (results._guides = parse(results.guides, {}));
        var g = guides[attempt.versionid] || guides[results.current];
        if (g && g.questions && g.questions.length) {
            return g;
        }
        var ans = parse(attempt.answers, {});
        return {iv: '', dv: '', unit: '', idea: '', basic: true, questions: Object.keys(ans).filter(function(k) {
            return k.charAt(0) !== '_' && !/^(notice\d|challenge|prediction|find_|cp_)/.test(k);
        }).map(function(k) {
            return {k: k, q: k, part: /claim/i.test(k) ? 'claim' : /^ev|evid/i.test(k) ? 'evidence' : /why|reason/i.test(k) ?
                'reasoning' : 'other', model: '', ideas: [], wrong: [], numbers: 0, match: [], because: false};
        })};
    };

    /**
     * Mark one answer with the comparison engine.
     *
     * @param {Object} q guide question
     * @param {string} answer
     * @param {Object} attempt
     * @returns {Object} {mark, max, why, conf, by, missing, wrong, nonums, noreason}
     */
    var markAnswer = function(q, answer, attempt) {
        answer = String(answer || '').trim();
        var out = {mark: 0, max: 4, why: '', conf: 'high', by: 'engine', missing: [], wrong: '', nonums: false, noreason: false,
            empty: !answer, short: false};
        if (!answer) {
            out.why = 'No answer.';
            return out;
        }
        var n = words(answer);
        out.short = n < 5;
        var comps = [];
        var notes = [];
        var rules = [];
        (q.ideas || []).forEach(function(group) {
            rules.push({kind: 'anyof', config: {terms: [group], min: 1, label: label(group)}});
        });
        var ev = rules.length ? FG.evaluate(answer, rules) : {notes: []};
        var ideasFound = 0;
        ev.notes.forEach(function(note, i) {
            if (note.kind === 'anyof') {
                if (note.ok) {
                    ideasFound++;
                } else {
                    out.missing.push(label(q.ideas[i]));
                }
            }
        });
        // Misconceptions are matched as exact phrases: stemming drops words like "no" and "not".
        var flat = ' ' + answer.toLowerCase().replace(/[’']/g, '\'').replace(/[^\p{L}\p{N}'%]+/gu, ' ') + ' ';
        (q.wrong || []).some(function(w) {
            var phrase = ' ' + String(w).toLowerCase().replace(/[’']/g, '\'').replace(/[^\p{L}\p{N}'%]+/gu, ' ').trim() + ' ';
            if (phrase.trim() && flat.indexOf(phrase) > -1) {
                out.wrong = String(w);
                return true;
            }
            return false;
        });
        if ((q.ideas || []).length) {
            comps.push([ideasFound / q.ideas.length, 0.5]);
            notes.push(ideasFound === q.ideas.length ? 'Has the key ideas' : ideasFound ? 'Has some key ideas; missing "' +
                out.missing.join('", "') + '"' : 'Missing the key ideas ("' + out.missing.join('", "') + '")');
        }
        if (q.numbers) {
            var vals = dataValues(attempt, q.match);
            var seen = {};
            var matched = numbersIn(answer).filter(function(x) {
                var key = Math.round(x * 100);
                if (seen[key] || !matchesData(x, vals)) {
                    return false;
                }
                seen[key] = true;
                return true;
            }).length;
            comps.push([Math.min(1, matched / q.numbers), (q.ideas || []).length ? 0.35 : 0.8]);
            out.nonums = matched === 0;
            notes.push(matched >= q.numbers ? 'Uses their own numbers' : matched ? 'Uses ' + matched + ' of ' + q.numbers +
                ' numbers needed' : numbersIn(answer).length ? 'Numbers don\'t match their data' : 'No numbers from their data');
        }
        if (q.because) {
            var why = BECAUSE.test(answer);
            comps.push([why ? 1 : 0, 0.25]);
            out.noreason = !why;
            if (!why) {
                notes.push('Describes but doesn\'t explain why');
            }
        }
        var cov = null;
        var numbersMet = q.numbers && !out.nonums && notes[notes.length - 1] === 'Uses their own numbers';
        // A model answer with example numbers says little about wording when the question only asks for numbers.
        if (q.model && !(q.numbers && !(q.ideas || []).length)) {
            cov = FG.coverage(answer, [q.model], q.q || '')[0];
            comps.push([Math.min(1, cov.score / 0.5), 0.25]);
        }
        if (!comps.length) {
            comps.push([Math.min(1, n / 12), 1]);
            out.conf = 'low';
            notes.push('No marking guide for this question: marked on length only');
        }
        var total = 0;
        var weight = 0;
        comps.forEach(function(c) {
            total += c[0] * c[1];
            weight += c[1];
        });
        var raw = weight ? total / weight : 0;
        if (out.short && !numbersMet) {
            raw = Math.min(raw, 0.5);
        }
        if (out.wrong) {
            raw = Math.min(raw, 0.5);
            notes.unshift('May state a misconception ("' + out.wrong + '")');
        }
        out.mark = Math.round(raw * 4);
        // Confidence: does the rule check agree with the closeness to the model answer?
        var rulesOnly = comps.length > 1 && cov ? (total - Math.min(1, cov.score / 0.5) * 0.25) / (weight - 0.25) : raw;
        if (out.conf !== 'low') {
            if (out.wrong || (cov && Math.abs(rulesOnly - Math.min(1, cov.score / 0.5)) > 0.5 && n > 8) ||
                    (raw > 0.35 && raw < 0.65) || (n >= 15 && raw < 0.5)) {
                out.conf = 'low';
            } else if (raw >= 0.8 || raw <= 0.2) {
                out.conf = 'high';
            } else {
                out.conf = 'medium';
            }
        }
        out.why = notes.join('. ') + '.';
        return out;
    };

    /**
     * Mark a whole report.
     *
     * @param {Object} results
     * @param {Object} attempt
     * @returns {Object} {q: {k: mark}, rubric, feedback, unsure: [k], total, max}
     */
    var markAttempt = function(results, attempt) {
        var g = guideFor(results, attempt);
        var ans = parse(attempt.answers, {});
        var saved = parse(attempt.marks, {});
        var res = {q: {}, unsure: [], total: 0, max: 0, guide: g};
        g.questions.forEach(function(q) {
            var m = saved[q.k] && saved[q.k].by !== 'engine' ? Object.assign({conf: 'high', missing: []}, saved[q.k]) :
                markAnswer(q, ans[q.k], attempt);
            res.q[q.k] = m;
            res.total += m.mark;
            res.max += 4;
            if (m.conf === 'low' && !m.empty) {
                res.unsure.push(q.k);
            }
        });
        res.rubric = rubricOf(g, res.q);
        res.feedback = feedbackFor(g, res.q, res.rubric);
        return res;
    };
    var rubricOf = function(g, marks) {
        var r = {};
        ['claim', 'evidence', 'reasoning'].forEach(function(p) {
            var list = g.questions.filter(function(q) {
                return q.part === p && marks[q.k];
            }).map(function(q) {
                return marks[q.k].mark;
            });
            r[p] = list.length ? Math.round(list.reduce(function(a, b) {
                return a + b;
            }, 0) / list.length) : 0;
        });
        return r;
    };
    var feedbackFor = function(g, marks, rubric) {
        var good = [];
        var next = '';
        if (rubric.claim >= 3) {
            good.push('your claim is clear');
        }
        if (rubric.evidence >= 3) {
            good.push('you backed it up with your own numbers');
        }
        if (rubric.reasoning >= 3) {
            good.push('you explained why it happens');
        }
        var wrong = Object.keys(marks).map(function(k) {
            return marks[k].wrong;
        }).filter(Boolean)[0];
        var weakest = ['claim', 'evidence', 'reasoning'].sort(function(a, b) {
            return rubric[a] - rubric[b];
        })[0];
        if (wrong) {
            next = 'Look again at your results: your data doesn\'t support "' + wrong + '".';
        } else if (rubric[weakest] < 3) {
            next = {claim: 'Make your claim say how ' + (g.iv || 'what you changed') + ' affects ' + (g.dv || 'what you measured') +
                '.', evidence: 'Back up your claim with numbers from your own results' + (g.unit ? ' (in ' + g.unit + ')' : '') +
                '.', reasoning: 'Explain why it happens, using ' + (g.idea || 'the science idea') + '.'}[weakest];
        }
        var s = good.length ? 'Well done: ' + good.join(', ') + '.' : 'Thanks for your report.';
        return next ? s + ' Next step: ' + next : s + ' Keep it up.';
    };

    /* ---------------------------------------------------------------- item analysis */

    var mean = function(a) {
        return a.length ? a.reduce(function(x, y) {
            return x + y;
        }, 0) / a.length : null;
    };
    var corr = function(xs, ys) {
        if (xs.length < 5) {
            return null;
        }
        var mx = mean(xs);
        var my = mean(ys);
        var sxy = 0;
        var sxx = 0;
        var syy = 0;
        xs.forEach(function(x, i) {
            sxy += (x - mx) * (ys[i] - my);
            sxx += (x - mx) * (x - mx);
            syy += (ys[i] - my) * (ys[i] - my);
        });
        return sxx && syy ? sxy / Math.sqrt(sxx * syy) : null;
    };

    /**
     * Facility, discrimination and a plain-English verdict for every question.
     *
     * @param {Object} g guide
     * @param {Array} rows [{name, marks: {k: mark}, answers}]
     * @returns {Array}
     */
    var itemAnalysis = function(g, rows) {
        var totals = rows.map(function(r) {
            return Object.keys(r.marks).reduce(function(s, k) {
                return s + r.marks[k].mark;
            }, 0);
        });
        return g.questions.map(function(q) {
            var xs = [];
            var rest = [];
            rows.forEach(function(r, i) {
                if (r.marks[q.k]) {
                    xs.push(r.marks[q.k].mark / 4);
                    rest.push(totals[i] - r.marks[q.k].mark);
                }
            });
            var facility = xs.length ? Math.round(mean(xs) * 100) : null;
            var disc = corr(xs, rest);
            // Top and bottom groups (27% each, at least 2) for the plain-English comparison.
            var order = rows.map(function(r, i) {
                return {i: i, t: totals[i]};
            }).filter(function(o) {
                return rows[o.i].marks[q.k];
            }).sort(function(a, b) {
                return b.t - a.t;
            });
            var size = Math.max(2, Math.round(order.length * 0.27));
            var top = order.length >= 4 ? Math.round(mean(order.slice(0, size).map(function(o) {
                return rows[o.i].marks[q.k].mark / 4;
            })) * 100) : null;
            var bottom = order.length >= 4 ? Math.round(mean(order.slice(-size).map(function(o) {
                return rows[o.i].marks[q.k].mark / 4;
            })) * 100) : null;
            // What weak answers have in common.
            var weak = rows.filter(function(r) {
                return r.marks[q.k] && r.marks[q.k].mark <= 2;
            });
            var gaps = {};
            var add = function(key, text) {
                gaps[key] = gaps[key] || {n: 0, text: text};
                gaps[key].n++;
            };
            weak.forEach(function(r) {
                var m = r.marks[q.k];
                if (m.empty) {
                    add('empty', 'left it blank');
                } else if (m.wrong) {
                    add('w:' + m.wrong, 'wrote "' + m.wrong + '" (a misconception)');
                }
                (m.missing || []).forEach(function(x) {
                    add('m:' + x, 'didn\'t mention "' + x + '"');
                });
                if (m.nonums) {
                    add('nonums', 'didn\'t use numbers from their results');
                }
                if (m.noreason) {
                    add('noreason', 'described what happened but not why');
                }
                if (m.short && !m.empty) {
                    add('short', 'wrote fewer than 5 words');
                }
            });
            var gapList = Object.keys(gaps).map(function(k) {
                return {key: k, n: gaps[k].n, text: gaps[k].text};
            }).sort(function(a, b) {
                return b.n - a.n;
            });
            var verdict = judge(q, facility, disc, xs.length, gapList, weak.length);
            return {k: q.k, q: q.q, part: q.part, partLabel: PART[q.part], n: xs.length, facility: facility,
                disc: disc === null ? null : Math.round(disc * 100) / 100, top: top, bottom: bottom, weak: weak, gaps: gapList,
                verdict: verdict.v, tone: verdict.tone, advice: verdict.text};
        });
    };
    var rewordTip = function(q, gaps, weakn) {
        var top = gaps[0] ? gaps[0].key : '';
        var share = function(key) {
            var g = gaps.find(function(x) {
                return x.key === key;
            });
            return g && weakn ? g.n / weakn : 0;
        };
        if (share('short') + share('empty') > 0.4) {
            return 'Many answers were blank or very short, so students may not know what you want. Add a sentence starter, or say ' +
                'exactly what to include (for example: "Use two numbers from your table").';
        }
        if (top === 'nonums') {
            return 'Say in the question that you want numbers from their results, for example "Use your readings to…".';
        }
        if (top === 'noreason') {
            return 'Ask "why" directly, for example "Explain why… Start with: This happens because…".';
        }
        if (top.indexOf('m:') === 0) {
            return 'Students rarely mentioned "' + top.slice(2) + '". If that idea is what you\'re after, name it or hint at it in the ' +
                'question; if other answers are also right, add them to the key ideas.';
        }
        return 'Make the question more specific about what a full answer includes, and check the model answer matches it.';
    };
    var judge = function(q, f, d, n, gaps, weakn) {
        var gap = gaps[0] ? ' The most common gap: students ' + gaps[0].text + ' (' + gaps[0].n + ').' : '';
        if (n < 5 || f === null) {
            return {v: 'Too early', tone: 'muted', text: 'Needs at least 5 marked reports before the numbers mean much.'};
        }
        if (d !== null && d < 0) {
            return {v: 'Check the marking', tone: 'bad', text: 'Students who did well on the rest of the report scored lower here ' +
                'than students who struggled. The question, model answer or key ideas may be misleading. Read a few answers from ' +
                'your strongest students and compare them with the model answer.'};
        }
        if (f < 30 && (d === null || d < 0.2)) {
            return {v: 'Reword the question', tone: 'bad', text: 'Most students missed this, even ones who did well everywhere ' +
                'else, so the wording is the likely problem rather than the students. ' + rewordTip(q, gaps, weakn) + gap};
        }
        if (f < 50) {
            return {v: 'Teach it again', tone: 'warn', text: 'Many students missed this, but the students who understood the rest ' +
                'of the experiment got it, so the question works and the idea needs more teaching.' + gap};
        }
        if (f >= 90) {
            return {v: 'Easy', tone: 'muted', text: 'Almost everyone got this. Fine as a confidence builder; to make it show ' +
                'who really understands, ask for a reason or a number as well.'};
        }
        if (d !== null && d >= 0.3) {
            return {v: 'Working well', tone: 'ok', text: 'This question separates students who understand from students who ' +
                'don\'t, and most students got it.' + (weakn ? gap : '')};
        }
        return {v: 'Fine', tone: 'ok', text: 'Most students got it, but it doesn\'t tell you much about who understands the whole ' +
            'experiment. Asking students to explain why would make it more useful.' + (weakn ? gap : '')};
    };

    /* ---------------------------------------------------------------- on-device AI (free) */

    var lm = function() {
        return window.LanguageModel || (window.ai && window.ai.languageModel) || null;
    };
    var onDeviceReady = function() {
        var L = lm();
        if (!L) {
            return Promise.resolve(false);
        }
        var check = L.availability ? L.availability() : L.capabilities ? L.capabilities().then(function(c) {
            return c.available === 'readily' ? 'available' : c.available;
        }) : Promise.resolve('no');
        return Promise.resolve(check).then(function(a) {
            return a === 'available' || a === 'readily';
        }).catch(function() {
            return false;
        });
    };
    var onDeviceMark = function(q, answer, attempt, year) {
        var L = lm();
        var ideas = (q.ideas || []).map(function(x) {
            return x.split('|').join(' / ');
        }).join('; ');
        var prompt = 'Mark this answer from a ' + (year || 'school') + ' student\'s science report out of 4.\nQuestion: ' + q.q +
            '\nModel answer: ' + q.model + '\nKey ideas: ' + (ideas || 'none') + '\nThe student\'s results: ' + attempt.phases +
            '\nAnswer: ' + answer + '\nGive credit for correct ideas in the student\'s own words. Reply with only JSON: ' +
            '{"mark":0-4,"why":"one short sentence"}';
        return L.create().then(function(session) {
            return session.prompt(prompt).then(function(text) {
                if (session.destroy) {
                    session.destroy();
                }
                var m = String(text).match(/\{[\s\S]*\}/);
                var d = m ? parse(m[0], null) : null;
                if (!d || !isFinite(Number(d.mark))) {
                    throw new Error('unreadable');
                }
                return {mark: Math.max(0, Math.min(4, Math.round(Number(d.mark)))), why: String(d.why || '').slice(0, 300)};
            });
        });
    };

    return {parse: parse, guideFor: guideFor, markAnswer: markAnswer, markAttempt: markAttempt, rubricOf: rubricOf,
        feedbackFor: feedbackFor, itemAnalysis: itemAnalysis, onDeviceReady: onDeviceReady, onDeviceMark: onDeviceMark,
        numbersIn: numbersIn, PART: PART};
});
