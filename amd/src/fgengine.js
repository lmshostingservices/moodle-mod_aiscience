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

/**
 * Rule-based auto-marking from LMS Labs Fast Grade (local_fastgrade 1.4.1), used unchanged: no AI, no external services. Text is
 * split into sentences, words are
 * reduced to their stems (Porter algorithm), and each assessor-set rule is checked.
 *
 * @module     mod_aiscience/fgengine
 * @copyright  2026 LMS Hosting Services / LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

export const STOP = new Set(('a an the and or but if of to in on at by for with from as is are was were be been being it its ' +
    'this that these those there their they them he she his her we our you your i me my not no do does did can could ' +
    'should would will shall may might must have has had so such than then also into about over under more most other ' +
    'some any each which who whom what when where why how all both either neither').split(' '));

/* Porter stemmer (Martin Porter, 1980; the algorithm is public domain). */
const step2list = {ational: 'ate', tional: 'tion', enci: 'ence', anci: 'ance', izer: 'ize', bli: 'ble', alli: 'al',
    entli: 'ent', eli: 'e', ousli: 'ous', ization: 'ize', ation: 'ate', ator: 'ate', alism: 'al', iveness: 'ive',
    fulness: 'ful', ousness: 'ous', aliti: 'al', iviti: 'ive', biliti: 'ble', logi: 'log', isation: 'ize', iser: 'ize'};
const step3list = {icate: 'ic', ative: '', alize: 'al', alise: 'al', iciti: 'ic', ical: 'ic', ful: '', ness: ''};
const c = '[^aeiou]';
const v = '[aeiouy]';
const C = c + '[^aeiouy]*';
const V = v + '[aeiou]*';
const mgr0 = new RegExp('^(' + C + ')?' + V + C);
const meq1 = new RegExp('^(' + C + ')?' + V + C + '(' + V + ')?$');
const mgr1 = new RegExp('^(' + C + ')?' + V + C + V + C);
const sV = new RegExp('^(' + C + ')?' + v);

/**
 * Stem one lower-case word.
 *
 * @param {string} w
 * @returns {string}
 */
export const stem = w => {
    if (w.length < 3 || !/^[a-z]+$/.test(w)) {
        return w;
    }
    let firstch = w[0];
    if (firstch === 'y') {
        w = 'Y' + w.slice(1);
    }
    let re;
    let re2;
    let re3;
    let re4;
    let m;
    re = /^(.+?)(ss|i)es$/;
    re2 = /^(.+?)([^s])s$/;
    if (re.test(w)) {
        w = w.replace(re, '$1$2');
    } else if (re2.test(w)) {
        w = w.replace(re2, '$1$2');
    }
    re = /^(.+?)eed$/;
    re2 = /^(.+?)(ed|ing)$/;
    if (re.test(w)) {
        m = re.exec(w);
        if (mgr0.test(m[1])) {
            w = w.slice(0, -1);
        }
    } else if (re2.test(w)) {
        m = re2.exec(w);
        const st = m[1];
        if (sV.test(st)) {
            w = st;
            re2 = /(at|bl|iz)$/;
            re3 = new RegExp('([^aeiouylsz])\\1$');
            re4 = new RegExp('^' + C + v + '[^aeiouwxy]$');
            if (re2.test(w)) {
                w += 'e';
            } else if (re3.test(w)) {
                w = w.slice(0, -1);
            } else if (re4.test(w)) {
                w += 'e';
            }
        }
    }
    re = /^(.+?)y$/;
    if (re.test(w)) {
        m = re.exec(w);
        if (sV.test(m[1])) {
            w = m[1] + 'i';
        }
    }
    re = new RegExp('^(.+?)(ational|tional|enci|anci|izer|iser|bli|alli|entli|eli|ousli|ization|isation|ation|ator|alism|' +
        'iveness|fulness|ousness|aliti|iviti|biliti|logi)$');
    if (re.test(w)) {
        m = re.exec(w);
        if (mgr0.test(m[1])) {
            w = m[1] + step2list[m[2]];
        }
    }
    re = /^(.+?)(icate|ative|alize|alise|iciti|ical|ful|ness)$/;
    if (re.test(w)) {
        m = re.exec(w);
        if (mgr0.test(m[1])) {
            w = m[1] + step3list[m[2]];
        }
    }
    re = /^(.+?)(al|ance|ence|er|ic|able|ible|ant|ement|ment|ent|ou|ism|ate|iti|ous|ive|ize|ise)$/;
    re2 = /^(.+?)(s|t)(ion)$/;
    if (re.test(w)) {
        m = re.exec(w);
        if (mgr1.test(m[1])) {
            w = m[1];
        }
    } else if (re2.test(w)) {
        m = re2.exec(w);
        const st = m[1] + m[2];
        if (mgr1.test(st)) {
            w = st;
        }
    }
    re = /^(.+?)e$/;
    if (re.test(w)) {
        m = re.exec(w);
        const st = m[1];
        re2 = new RegExp('^' + C + v + '[^aeiouwxy]$');
        if (mgr1.test(st) || (meq1.test(st) && !re2.test(st))) {
            w = st;
        }
    }
    if (/ll$/.test(w) && mgr1.test(w)) {
        w = w.slice(0, -1);
    }
    if (firstch === 'y') {
        w = 'y' + w.slice(1);
    }
    return w;
};

/**
 * Words with positions.
 *
 * @param {string} text
 * @returns {Array} [{w, s, start, end}]
 */
export const tokens = text => {
    const out = [];
    const re = /[\p{L}\p{N}][\p{L}\p{N}'’-]*/gu;
    let m;
    while ((m = re.exec(text))) {
        const w = m[0].toLowerCase().replace(/[’']s$/, '').replace(/['’]/g, '');
        out.push({w, s: stem(w), start: m.index, end: m.index + m[0].length});
    }
    return out;
};

/**
 * Sentences with offsets.
 *
 * @param {string} text
 * @returns {Array} [{start, end, text}]
 */
export const sentences = text => {
    const out = [];
    const re = /[^.!?\n]+(?:[.!?]+(?=\s|$)|\n|$)/g;
    let m;
    while ((m = re.exec(text))) {
        let start = m.index;
        let end = m.index + m[0].length;
        while (start < end && /\s/.test(text[start])) {
            start++;
        }
        while (end > start && /\s/.test(text[end - 1])) {
            end--;
        }
        if (end - start > 2) {
            out.push({start, end, text: text.slice(start, end)});
        }
        if (m[0].length === 0) {
            re.lastIndex++;
        }
    }
    return out;
};

/**
 * A phrase as a list of stems (stop words kept out, unless the phrase is only stop words).
 *
 * @param {string} phrase
 * @returns {string[]}
 */
export const phraseStems = phrase => {
    const tk = tokens(phrase);
    const content = tk.filter(x => !STOP.has(x.w));
    return (content.length ? content : tk).map(x => x.s);
};

/**
 * Find a phrase's stems in a token list (in order, allowing up to 2 extra words between).
 *
 * @param {Array} tk tokens of a sentence
 * @param {string[]} stems
 * @returns {Array} [{start, end}] character ranges
 */
export const findPhrase = (tk, stems) => {
    const hits = [];
    if (!stems.length) {
        return hits;
    }
    for (let i = 0; i < tk.length; i++) {
        if (tk[i].s !== stems[0]) {
            continue;
        }
        let j = i;
        let k = 1;
        let gap = 0;
        while (k < stems.length && j + 1 < tk.length) {
            j++;
            if (tk[j].s === stems[k]) {
                k++;
                gap = 0;
            } else if (STOP.has(tk[j].w) || gap < 2) {
                gap++;
                if (gap > 3) {
                    break;
                }
            } else {
                break;
            }
        }
        if (k === stems.length) {
            hits.push({start: tk[i].start, end: tk[j].end});
        }
    }
    return hits;
};

/** Ready-made patterns. */
export const PRESETS = {
    year: /\b(19|20)\d{2}\b/g,
    citation: /\(([A-Z][A-Za-z'’-]+(?: (?:&|and) [A-Z][A-Za-z'’-]+| et al\.?)?),? ?(19|20)\d{2}[a-z]?(?:, ?p+\. ?\d+(?:-\d+)?)?\)/g,
    legislation: /\b[A-Z][A-Za-z()&,' -]{3,80}? (Act|Regulations?|Code of Practice|Standards?) (19|20)\d{2}\b/g,
    money: /\$\s?\d[\d,]*(\.\d{2})?\b/g,
    percent: /\b\d+(\.\d+)?\s?%/g,
    number: /\b\d+(\.\d+)?\b/g,
    url: /\bhttps?:\/\/\S+/g,
    email: /\b[\w.+-]+@[\w-]+\.[\w.-]+\b/g,
};

/**
 * A rule's alternatives: each entry may hold synonyms separated by "|" or "/".
 *
 * @param {Array} terms
 * @returns {Array} [{label, stems: [[...], ...]}]
 */
const alternatives = terms => (terms || []).map(t => ({
    label: t.split(/\s*[|/]\s*/)[0],
    stems: t.split(/\s*[|/]\s*/).filter(Boolean).map(phraseStems),
}));

/**
 * Check one criterion's rules against a text.
 *
 * @param {string} text
 * @param {Array} rules [{kind, config}]
 * @returns {Object} {met, total, ok, hits: [{start, end, label, kind, positive}], notes: []}
 */
export const evaluate = (text, rules) => {
    const sents = sentences(text).map(s => Object.assign(s, {tk: tokens(s.text)}));
    const allwords = tokens(text).length;
    const hits = [];
    const notes = [];
    let met = 0;
    let total = 0;
    const sentenceOf = (start, end) => sents.find(s => start >= s.start && end <= s.end + 1) || {start, end};
    (rules || []).forEach(rule => {
        const cfg = rule.config || {};
        const min = Math.max(1, cfg.min || 1);
        switch (rule.kind) {
            case 'term':
            case 'anyof': {
                total++;
                const found = new Map();
                alternatives(cfg.terms).forEach(alt => {
                    sents.forEach(s => {
                        alt.stems.forEach(st => {
                            findPhrase(s.tk, st).forEach(() => {
                                if (!found.has(alt.label)) {
                                    found.set(alt.label, []);
                                }
                                found.get(alt.label).push({start: s.start, end: s.end, label: alt.label});
                            });
                        });
                    });
                });
                const distinct = found.size;
                const count = [...found.values()].reduce((n, l) => n + l.length, 0);
                const ok = rule.kind === 'anyof' ? distinct >= min : (distinct > 0 && count >= min);
                if (ok) {
                    met++;
                }
                found.forEach(list => list.forEach(x => hits.push(Object.assign({}, x, {kind: rule.kind, positive: true,
                    rulelabel: cfg.label || ''}))));
                notes.push({kind: rule.kind, ok, label: cfg.label || (cfg.terms || []).slice(0, 4).join(', '),
                    detail: rule.kind === 'anyof' ? `${distinct}/${min}` : `${count}`, found: [...found.keys()]});
                break;
            }
            case 'pattern': {
                total++;
                let re = PRESETS[cfg.preset] ? new RegExp(PRESETS[cfg.preset].source, PRESETS[cfg.preset].flags) : null;
                if (!re && cfg.regex) {
                    try {
                        re = new RegExp(cfg.regex, 'giu');
                    } catch (e) {
                        re = null;
                    }
                }
                let n = 0;
                if (re) {
                    let m;
                    let guard = 0;
                    while ((m = re.exec(text)) && guard++ < 500) {
                        n++;
                        const s = sentenceOf(m.index, m.index + m[0].length);
                        hits.push({start: s.start, end: s.end, label: m[0], kind: 'pattern', positive: true, rulelabel: cfg.label});
                        if (m[0].length === 0) {
                            re.lastIndex++;
                        }
                    }
                }
                const ok = n >= min;
                if (ok) {
                    met++;
                }
                notes.push({kind: 'pattern', ok, label: cfg.label || cfg.preset || cfg.regex, detail: String(n)});
                break;
            }
            case 'mustnot': {
                total++;
                let bad = 0;
                alternatives(cfg.terms).forEach(alt => sents.forEach(s => alt.stems.forEach(st => findPhrase(s.tk,
                    st).forEach(() => {
                    bad++;
                    hits.push({start: s.start, end: s.end, label: alt.label, kind: 'mustnot', positive: false,
                        comment: cfg.comment || ''});
                }))));
                if (!bad) {
                    met++;
                }
                notes.push({kind: 'mustnot', ok: !bad, label: cfg.label || (cfg.terms || []).join(', '), detail: String(bad)});
                break;
            }
            case 'ideas': {
                total++;
                const res = ideasCheck(text, cfg.terms || []);
                const need = Math.min((cfg.terms || []).length, Math.max(1, cfg.min || Math.ceil((cfg.terms || []).length * 0.8)));
                const ok = res.found.length >= need;
                if (ok) {
                    met++;
                }
                notes.push({kind: 'ideas', ok, ratio: need ? Math.min(1, res.found.length / need) : 0, label: cfg.label || '',
                    detail: `${res.found.length}/${(cfg.terms || []).length}`, found: res.found, missing: res.missing});
                break;
            }
            case 'length': {
                total++;
                const ok = (!cfg.min || allwords >= cfg.min) && (!cfg.max || allwords <= cfg.max);
                if (ok) {
                    met++;
                }
                notes.push({kind: 'length', ok, label: cfg.label || '', detail: `${allwords}`, min: cfg.min, max: cfg.max});
                break;
            }
            case 'structure': {
                total++;
                let ok = false;
                if (cfg.preset === 'references') {
                    ok = /(^|\n)\s*(references?|reference list|bibliography|works cited|sources)\s*:?\s*(\n|$)/i.test(text);
                } else if (cfg.preset === 'headings') {
                    const heads = text.split('\n').filter(l => l.trim().length > 2 && l.trim().length < 70 &&
                        !/[.!?,;]$/.test(l.trim()) && /^[A-Z0-9]/.test(l.trim()));
                    ok = heads.length >= min;
                } else if (cfg.preset === 'list') {
                    ok = (text.match(/(^|\n)\s*([-•*–]|\d+[.)]|[a-z][.)])\s+\S/g) || []).length >= min;
                }
                if (ok) {
                    met++;
                }
                notes.push({kind: 'structure', ok, label: cfg.label || cfg.preset});
                break;
            }
            default:
                break;
        }
    });
    return {met, total, ok: total > 0 && met === total, partial: met > 0 && met < total, hits, notes, words: allwords};
};

/**
 * Suggest look-for terms from criterion wording and a benchmark answer (term frequency).
 *
 * @param {string} criterion
 * @param {string} benchmark
 * @returns {string[]}
 */
export const suggestTerms = (criterion, benchmark) => {
    const generic = new Set(['student', 'learner', 'describ', 'explain', 'identifi', 'identify', 'demonstr', 'includ', 'provid',
        'answer', 'question', 'must', 'least', 'correct', 'relev', 'appropri', 'exampl', 'list', 'outlin', 'discuss', 'use',
        'need', 'mark', 'award', 'point', 'show', 'make', 'give', 'state', 'clear', 'clearli', 'good', 'well']);
    const counts = new Map();
    const add = (text, weight) => {
        const tk = tokens(text).filter(x => !STOP.has(x.w) && x.w.length > 2 && !/^\d+$/.test(x.w) && !generic.has(x.s));
        tk.forEach(x => {
            const e = counts.get(x.s) || {n: 0, word: x.w};
            e.n += weight;
            if (x.w.length < e.word.length) {
                e.word = x.w;
            }
            counts.set(x.s, e);
        });
        for (let i = 0; i < tk.length - 1; i++) {
            if (tk[i + 1].start - tk[i].end <= 2) {
                const key = tk[i].s + ' ' + tk[i + 1].s;
                const e = counts.get(key) || {n: 0, word: tk[i].w + ' ' + tk[i + 1].w};
                e.n += weight * 1.5;
                counts.set(key, e);
            }
        }
    };
    add(criterion || '', 2);
    add(benchmark || '', 1);
    return [...counts.values()].filter(e => e.n >= 2 || (benchmark && e.n >= 1.5)).sort((a, b) => b.n - a.n)
        .slice(0, 12).map(e => e.word);
};

/* ---------- Key-point coverage (TF-IDF cosine on word stems) ---------- */

/**
 * Fold "-ise", "-ize" and "-y" endings together after stemming, which the stemmer leaves apart
 * ("prioritise", "prioritised", "priorities").
 *
 * @param {string} st stem
 * @returns {string}
 */
const norm = st => (st.length > 5 ? st.replace(/(?:is|iz|i)$/, '') : st);

// Rubric wording that says what to do rather than what the answer contains.
const META = new Set(('identify identifies identified describe describes described explain explains explained apply applies ' +
    'applied relevant correct correctly address addresses addressed mention mentions mentioned discuss discusses outline ' +
    'outlines demonstrate demonstrates show shows provide provides clearly appropriate appropriately incomplete named ' +
    'partly partially fully adequately student learner response answer select selects selected choose chooses use ' +
    'uses using include includes including list lists give gives').split(' ').map(w => norm(stem(w))));

// Common terms and their abbreviations count as one idea, whichever way the student writes them.
const LONGFORMS = [
    [/\bwork(?:place)?\s+health\s+(?:and|&)\s+safety\b/g, 'whs'],
    [/\boccupational\s+health\s+(?:and|&)\s+safety\b/g, 'ohs'],
    [/\bhealth\s+(?:and|&)\s+safety\s+representatives?\b/g, 'hsr'],
    [/\bhealth\s+(?:and|&)\s+safety\s+committees?\b/g, 'hsc'],
    [/\bpersons?\s+conducting\s+(?:a\s+)?business\s+(?:or|and)\s+undertakings?\b/g, 'pcbu'],
    [/\bpersonal\s+protective\s+equipment\b/g, 'ppe'],
    [/\b(?:material\s+)?safety\s+data\s+sheets?\b/g, 'sds'],
    [/\bsafe\s+work\s+method\s+statements?\b/g, 'swms'],
    [/\bjob\s+safety\s+analys[ie]s\b/g, 'jsa'],
    [/\bregistered\s+training\s+organi[sz]ations?\b/g, 'rto'],
    [/\bstandard\s+operating\s+procedures?\b/g, 'sop'],
    [/\bkey\s+performance\s+indicators?\b/g, 'kpi'],
    [/\bnational\s+disability\s+insurance\s+scheme\b/g, 'ndis'],
    [/\boh&s\b/g, 'ohs'],
];
const ABBR = {hsrs: 'hsr', pcbus: 'pcbu', msds: 'sds', kpis: 'kpi', sops: 'sop', rtos: 'rto'};

// Words that mean the same thing in marking criteria (stem => shared stem).
const CANON = {act: 'legisl', law: 'legisl', regul: 'legisl', statut: 'legisl', legal: 'legisl',
    employe: 'worker', staff: 'worker', employ: 'worker', danger: 'hazard', risk: 'hazard',
    discuss: 'consult', meet: 'consult', toolbox: 'consult', feedback: 'consult',
    rate: 'assess', evalu: 'assess', analys: 'assess', score: 'assess',
    minut: 'record', regist: 'record', log: 'record', document: 'record',
    shape: 'chang', influenc: 'chang', modif: 'chang'};

/**
 * Content stems of a text, without stop words and without words from the question.
 *
 * @param {string} text
 * @param {Set} demote stems to ignore
 * @returns {string[]}
 */
const contentStems = (text, demote) => {
    let low = String(text || '').toLowerCase();
    LONGFORMS.forEach(([re, ab]) => {
        low = low.replace(re, ab);
    });
    const out = [];
    tokens(low).forEach(x => {
        const w = ABBR[x.w] || x.w;
        const st = ABBR[x.w] ? w : norm(x.s);
        if (STOP.has(w) || w.length < 2 || demote.has(st) || META.has(st)) {
            return;
        }
        out.push(CANON[st] || st);
    });
    return out;
};

/* ---------- Checks from the assignment instructions (plain text rules, no AI) ---------- */

// Words that start a task step: "Identify the hazards…", "Explain how…".
const STEPVERB = new RegExp('^(identify|describe|explain|discuss|outline|list|select|choose|assess|evaluate|analy[sz]e|compare|' +
    'justify|recommend|develop|prepare|create|build|design|write|include|provide|use|apply|show|demonstrate|state|give|define|' +
    'summari[sz]e|reflect|calculate|tell|add|consider|review|plan|record|report|propose|investigate|examine|determine|' +
    'implement|monitor|consult|prioriti[sz]e|rate|name|what|how|why|who|when|where)\\b', 'i');

/**
 * The steps the instructions ask for: list items, and sentences that start with a task word.
 *
 * @param {string} text instructions, one block per line
 * @returns {string[]}
 */
export const instructionSteps = text => {
    const steps = [];
    const QSTART = '(?:what|how|why|who|when|where|which|' + STEPVERB.source.slice(2, -3) + ')\\b';
    const add = x => {
        x = x.trim().replace(/^(and|or|then|also)\s+/i, '').replace(/[.;:,?!]+$/, '').trim();
        if (x.length >= 12 && !/^(upload|submit|attach|save|use (apa|harvard)|reference)\b/i.test(x) &&
                !/\b(at least|no more than|up to|about|minimum of|maximum of|than|of|in)$/i.test(x)) {
            steps.push(x.charAt(0).toUpperCase() + x.slice(1));
        }
    };
    const LIST = /^(?:[-•*▪◦●–]|\(?\d{1,2}[.)]|\(?[a-h][.)])\s+(.+)$/i;
    const lines = String(text || '').split(/\n+/).map(l => l.trim()).filter(Boolean);
    const haslist = lines.filter(l => LIST.test(l)).length >= 2;
    lines.forEach(line => {
        const m = line.match(LIST);
        // Word limits and file types are checked separately, so take them out of the step text.
        const body = (m ? m[1] : line).replace(/\(?\s*\d[\d,]*\s*(?:(?:-|–|—|to)\s*\d[\d,]*\s*)?words?\s*\)?/gi, ' ')
            .replace(/\s{2,}/g, ' ');
        sentences(body).forEach(sn => {
            let x = sn.text.trim();
            // "Write a reflection: what…, how… and what…": the questions after the colon are the steps.
            const colon = x.indexOf(':');
            if (colon > 0 && colon < x.length - 12) {
                const after = x.slice(colon + 1);
                if (new RegExp('^\\s*' + QSTART, 'i').test(after)) {
                    x = after.trim();
                }
            }
            // Split a list of questions or tasks in one sentence: ", how…", "; explain…", " and what…".
            const clauses = x.split(new RegExp('\\s*(?:[,;]\\s*(?:and\\s+|or\\s+)?|\\s+and\\s+)(?=' + QSTART + ')', 'i'));
            const asks = clauses.filter(c => new RegExp('^\\s*' + QSTART, 'i').test(c));
            if (asks.length >= 2) {
                clauses.forEach(add);
            } else if (m || (!haslist && STEPVERB.test(x)) || /\?$/.test(x)) {
                add(x);
            }
        });
    });
    return [...new Set(steps)].slice(0, 12);
};

/**
 * The instruction step closest to a criterion (shared content words), or ''.
 *
 * @param {string} criterion
 * @param {string[]} steps
 * @returns {string}
 */
export const matchStep = (criterion, steps) => {
    const a = new Set(contentStems(criterion, new Set()));
    let best = '';
    let bestscore = 0;
    (steps || []).forEach(st => {
        const b = new Set(contentStems(st, new Set()));
        const shared = [...a].filter(x => b.has(x)).length;
        const score = shared / Math.max(1, Math.min(a.size, b.size));
        if (shared >= 2 && score > bestscore) {
            best = st;
            bestscore = score;
        }
    });
    return bestscore >= 0.3 ? best : '';
};

// Words too general to show a part was answered.
const KWFILLER = new Set(('one two three four five each every per using use used take taking make before after so then ' +
    'also still own way part question questions answer example examples difference between thing things new short ' +
    'long good well first second third main least most more less').split(' '));

/**
 * A short label for a step: its first few words.
 *
 * @param {string} step
 * @returns {string}
 */
const stepLabel = step => {
    const w = step.replace(/[:|]/g, ' ').split(/\s+/).filter(Boolean);
    if (w.length <= 8) {
        return w.join(' ');
    }
    // Cut after 7 words, but not on a small word ("…with a").
    const cut = w.slice(0, 7);
    const small = /^(a|an|the|of|with|and|or|so|but|to|for|in|on|at|by|from|that|each|your|their|its)$/i;
    while (cut.length > 3 && small.test(cut[cut.length - 1])) {
        cut.pop();
    }
    return cut.join(' ').replace(/[,;]$/, '') + '…';
};

/**
 * Ready-made checks read from the assignment instructions: the parts asked for, the word limit,
 * referencing and any legislation named. Plain text rules: nothing leaves the browser.
 *
 * @param {string} text instructions, one block per line
 * @returns {Array} rules [{kind, config, from: 'instructions'}]
 */
export const fromInstructions = text => {
    const src = String(text || '');
    const rules = [];
    const rule = (kind, config) => rules.push({kind, config: Object.assign({label: '', terms: [], min: 1, max: 0, comment: '',
        preset: '', regex: ''}, config), from: 'instructions'});
    const steps = instructionSteps(src);
    if (steps.length >= 2) {
        const parts = steps.map(st => {
            // Distinct keywords: skip any that share a word with one already picked.
            const kws = [];
            const used = new Set();
            suggestTerms(st, '').filter(k => !META.has(norm(stem(k.split(' ')[0]))) &&
                !k.toLowerCase().split(' ').some(w => KWFILLER.has(w))).forEach(k => {
                const ws = k.toLowerCase().split(' ').map(w => norm(stem(w)));
                if (kws.length < 4 && !ws.some(w => used.has(w))) {
                    kws.push(k);
                    ws.forEach(w => used.add(w));
                }
            });
            return kws.length ? stepLabel(st) + ': ' + kws.join(', ') : '';
        }).filter(Boolean);
        if (parts.length >= 2) {
            rule('parts', {terms: parts});
        }
    }
    const n = x => parseInt(String(x).replace(/,/g, ''), 10);
    const range = src.match(/(\d[\d,]{1,5})\s*(?:-|–|—|to)\s*(\d[\d,]{1,5})\s*words/i);
    const atleast = src.match(/(?:at least|minimum(?: of)?|no fewer than|no less than)\s*(\d[\d,]{1,5})\s*words/i);
    const atmost = src.match(/(?:no more than|maximum(?: of)?|up to|not (?:to )?exceed(?:ing)?|under)\s*(\d[\d,]{1,5})\s*words/i);
    const about = src.match(/(?:about|around|approximately|approx\.?|roughly|~)\s*(\d[\d,]{1,5})\s*words/i);
    let min = 0;
    let max = 0;
    if (range) {
        min = n(range[1]);
        max = n(range[2]);
    } else if (atleast || atmost) {
        min = atleast ? n(atleast[1]) : 0;
        max = atmost ? n(atmost[1]) : 0;
    } else if (about) {
        min = Math.round(n(about[1]) * 0.9);
        max = Math.round(n(about[1]) * 1.1);
    }
    if (min || max) {
        const range = min ? `at least ${min} words` : `at most ${max} words`;
        rule('length', {min, max, label: min && max ? `${min}–${max} words` : range});
    }
    if (/\b(APA|Harvard|Chicago|MLA|reference list|referenc(e|ing)|in-text citations?|cite|bibliography)\b/i.test(src)) {
        rule('structure', {preset: 'references', label: 'a reference list'});
        rule('pattern', {preset: 'citation', min: 1, label: 'in-text citations'});
    }
    // Legislation named in full: capitalised words (and "and", "of", "(General)"), then Act, Regulations or Code.
    const LAWNAME = new RegExp('[A-Z][A-Za-z]+(?:\\s+(?:[A-Z][A-Za-z]+|and|of|for|the|\\([A-Za-z]+\\)))*' +
        '\\s+(?:Act|Regulations?|Code of Practice)(?=\\s+(?:19|20)\\d{2})', 'g');
    const laws = [...new Set((src.match(LAWNAME) || []).map(x => x.trim()))];
    if (laws.length) {
        rule('anyof', {terms: laws, min: laws.length, label: 'the legislation named'});
    } else if (/\b(legislation|act and regulations?|regulations?|codes? of practice)\b/i.test(src)) {
        rule('pattern', {preset: 'legislation', min: 1, label: 'legislation named with its year'});
    }
    if (/\bheadings?\b/i.test(src) && steps.length >= 2) {
        rule('structure', {preset: 'headings', min: Math.min(steps.length, 6), label: 'a heading for each part'});
    }
    return rules;
};

/* ---------- Key ideas: is each idea in a criterion present anywhere in the work? ---------- */

// Everyday words for the same idea, so "fitted barriers" counts as a control and "rated high" as a risk rating.
const IDEAWORDS = {
    control: 'control barrier guard replace eliminate substitute isolate engineering procedure limiter separate fence ' +
        'signage ppe interlock enclosure ventilation',
    assess: 'assess rating rated likelihood consequence matrix high medium low likely unlikely major minor moderate ' +
        'extreme catastrophic severity probability',
    consult: 'consult consultation hsr committee say concern concerns raise talk feedback toolbox',
    understand: 'understand understanding question questions quiz check explain ask confirm',
    action: 'action actions follow wear report must procedure steps',
    record: 'record records register minutes log document',
    review: 'review monitor audit inspect reassess',
    legisl: 'legislation act regulation regulations law code codes duty duties',
    budget: 'budget cost costs total totals price spend',
    formula: 'formula formulas sum calculate calculated calculation',
    // Reflection prompts: what was learned, how it was used, what comes next.
    learn: 'learn learned learnt lesson lessons realise realised realize realized understood discover discovered ' +
        'taught showed insight',
    apply: 'apply applied use used practice implement implemented introduce introduced tried put',
    future: 'next future will plan plans going intend differently improve',
    work: 'work workplace job site shift role',
};
// Words that describe the quality of work rather than an idea in it.
const NOTIDEA = new Set(('accur accurate content cover covers covered clear clearly support supports delivery deliver ' +
    'relevant detail detailed effective appropriate well good all every most some few main key overall quality ' +
    'complete completed correct justified justify sound strong weak range variety evidence shows show linked link ' +
    'aspect aspects element elements area areas level levels criterion criteria encourage encourages ensure ensures ' +
    'demonstrate demonstrates provide provides use uses using apply applies').split(' ').map(w => norm(stem(w))));
const IDEAMAP = (() => {
    const m = {};
    Object.entries(IDEAWORDS).forEach(([idea, words]) => words.split(' ').forEach(w => {
        m[norm(stem(w))] = idea;
    }));
    return m;
})();

/**
 * The ideas in a text, as canonical stems.
 *
 * @param {string} text
 * @returns {string[]}
 */
const ideaStems = text => contentStems(text, new Set()).map(st => IDEAMAP[st] || st).filter(st => !NOTIDEA.has(st));

/**
 * The key ideas a criterion asks for: its distinct content words, shown as words.
 *
 * @param {string} text criterion and its top level description
 * @returns {string[]}
 */
export const keyIdeas = text => {
    const seen = new Set();
    const out = [];
    tokens(String(text || '').toLowerCase()).forEach(x => {
        const st = ideaStems(x.w)[0];
        if (st && !seen.has(st) && x.w.length > 2 && !/^\d+$/.test(x.w)) {
            seen.add(st);
            out.push(x.w);
        }
    });
    return out.slice(0, 8);
};

/**
 * Check which key ideas appear anywhere in a text.
 *
 * @param {string} text
 * @param {string[]} ideas
 * @returns {Object} {found: [], missing: [], ratio}
 */
export const ideasCheck = (text, ideas) => {
    const have = new Set(ideaStems(text));
    const found = [];
    const missing = [];
    (ideas || []).forEach(w => {
        const st = w.split(/\s*[|/]\s*/).map(x => ideaStems(x)[0]).filter(Boolean);
        (st.some(x => have.has(x)) ? found : missing).push(w.split(/\s*[|/]\s*/)[0]);
    });
    return {found, missing, ratio: ideas && ideas.length ? found.length / ideas.length : 0};
};

/**
 * Split benchmark text into key points (lines, bullets or sentences).
 *
 * @param {string} text
 * @returns {string[]}
 */
export const splitPoints = text => {
    const lines = String(text || '').split(/\n+|(?:^|\s)[•▪◦●–-]\s+|\s\d+[.)]\s+/).map(l => l.trim()).filter(l => l.length > 3);
    const out = [];
    lines.forEach(l => {
        if (l.length > 220) {
            sentences(l).forEach(s => out.push(s.text));
        } else {
            out.push(l);
        }
    });
    return out.slice(0, 30);
};

/**
 * The stretches of text a key point can be found in: each sentence, each pair of neighbouring
 * sentences (a point is often made across two), and each run of short lines such as a table or a
 * list, taken as one block. A heading or a single table cell is never evidence on its own.
 *
 * @param {string} text
 * @returns {Array} [{start, end, text, pair}]
 */
export const windows = text => {
    const words = s => s.text.split(/\s+/).length;
    const ends = s => /[.!?:;]["')\]]?$/.test(s.text);
    // Lines that wrap mid-sentence (as in a PDF) are joined back into their sentence first.
    const one = [];
    let wrapping = false;
    sentences(text).forEach(s => {
        const last = one[one.length - 1];
        if (wrapping && last) {
            last.end = s.end;
            last.text += ' ' + s.text;
        } else {
            one.push(Object.assign({}, s));
        }
        wrapping = !ends(s) && words(s) >= 12;
    });
    if (one.length < 3) {
        return one;
    }
    const isShort = s => !ends(s) && words(s) < 12;
    const out = [];
    let run = [];
    // A run of four or more short lines is a table or list. A short line just before a paragraph is
    // that paragraph's heading, so it is left out of the run.
    const flush = next => {
        if (next && run.length) {
            run.pop();
        }
        if (run.length > 3) {
            out.push({start: run[0].start, end: run[run.length - 1].end, text: run.map(r => r.text).join('\n'),
                pair: true});
        }
        run = [];
    };
    one.forEach((s, i) => {
        if (isShort(s)) {
            run.push(s);
            return;
        }
        flush(s);
        out.push(s);
        const prev = one[i - 1];
        if (prev && !isShort(prev)) {
            out.push({start: prev.start, end: s.end, text: prev.text + ' ' + s.text, pair: true});
        }
    });
    flush();
    return out;
};

/**
 * Which key points does the text cover, and where.
 *
 * @param {string} text the student's text
 * @param {string[]} points key points
 * @param {string} question question wording (its words are not counted)
 * @returns {Array} [{point, status: covered|partial|missing, score, start, end}]
 */
export const coverage = (text, points, question = '') => {
    // Words from the question only count when the key point itself needs them, so restating the
    // question earns nothing but using its key terms in an answer still does.
    const qstems = new Set(contentStems(question || '', new Set()));
    const none = new Set();
    const sents = windows(text);
    const sstems = sents.map(s => contentStems(s.text, none));
    const pstems = points.map(p => contentStems(p, none));
    const docs = [...sstems, ...pstems];
    const df = new Map();
    docs.forEach(d => new Set(d).forEach(w => df.set(w, (df.get(w) || 0) + 1)));
    const n = docs.length;
    const vec = stems => {
        const tf = new Map();
        stems.forEach(w => tf.set(w, (tf.get(w) || 0) + 1));
        const v = new Map();
        let norm = 0;
        tf.forEach((c, w) => {
            const x = (1 + Math.log(c)) * Math.log(1 + n / (df.get(w) || 1));
            v.set(w, x);
            norm += x * x;
        });
        return {v, norm: Math.sqrt(norm) || 1};
    };
    const svecs = sstems.map(vec);
    const ssets = sstems.map(st => new Set(st));
    return points.map((p, pi) => {
        const pst = pstems[pi];
        const pset = new Set(pst);
        const skip = w => qstems.has(w) && !pset.has(w);
        const pv = vec(pst);
        let best = {score: 0, i: -1};
        svecs.forEach((sv, i) => {
            let dot = 0;
            let snorm = 0;
            sv.v.forEach((y, w) => {
                if (!skip(w)) {
                    snorm += y * y;
                }
            });
            pv.v.forEach((x, w) => {
                const y = sv.v.get(w);
                if (y) {
                    dot += x * y;
                }
            });
            const cos = dot / (pv.norm * (Math.sqrt(snorm) || 1));
            // Share of the point's words found in the sentence (alignment).
            const align = pset.size ? [...pset].filter(w => ssets[i].has(w)).length / pset.size : 0;
            // A pair has to do clearly better than its sentences to be chosen.
            const score = (0.5 * Math.min(1, cos) + 0.5 * Math.min(1, align)) * (sents[i].pair ? 0.92 : 1);
            if (score > best.score) {
                best = {score, i};
            }
        });
        const status = best.score >= 0.5 ? 'covered' : (best.score >= 0.3 ? 'partial' : 'missing');
        const s = best.i >= 0 ? sents[best.i] : null;
        return {point: p, status, score: Math.round(best.score * 100) / 100, start: s ? s.start : -1, end: s ? s.end : -1};
    });
};

/* ---------- Multi-part questions ---------- */

/**
 * Check each part was answered. Each entry is "label: keyword, keyword | synonym".
 *
 * @param {string} text
 * @param {string[]} parts
 * @returns {Array} [{label, ok, start, end}]
 */
export const partsCheck = (text, parts) => {
    const one = sentences(text);
    // Each sentence, and each pair of neighbouring sentences (an answer often spreads one point over two).
    const sents = one.concat(one.slice(1).map((s, i) => ({text: one[i].text + ' ' + s.text, start: one[i].start, end: s.end,
        pair: true}))).map(s => Object.assign(s, {tk: tokens(s.text), ideas: new Set(ideaStems(s.text))}));
    // A keyword is found as written, or as the same idea in everyday words ("lesson" for "learn").
    const has = (s, kw, st) => findPhrase(s.tk, st).length > 0 || (() => {
        const ids = ideaStems(kw);
        return ids.length > 0 && ids.every(x => s.ideas.has(x));
    })();
    return (parts || []).map(p => {
        const [label, kws] = p.includes(':') ? [p.slice(0, p.indexOf(':')).trim(), p.slice(p.indexOf(':') + 1)] : [p, p];
        const words = kws.split(/[,|/]/).map(x => x.trim()).filter(Boolean);
        const alts = words.map(phraseStems);
        // A part is answered by the sentence that uses the most of its keywords: at least two when it has two or more,
        // so one shared word (a title, a heading) is not enough.
        const meaningful = words.filter(w => ideaStems(w).length).length || alts.length;
        const need = Math.min(2, meaningful);
        let best = null;
        let bestn = 0;
        for (const s of sents) {
            const n = alts.filter((st, i) => has(s, words[i], st)).length;
            // Prefer a single sentence over a pair when they match as well.
            if (n > bestn || (n === bestn && n && best && best.pair && !s.pair)) {
                best = s;
                bestn = n;
            }
        }
        return best && bestn >= need ? {label, ok: true, start: best.start, end: best.end} :
            {label, ok: false, start: -1, end: -1};
    });
};

/* ---------- Australian VET compliance checks ---------- */

export const LAWS = [
    {name: 'Work Health and Safety Act', years: [2011, 2012, 2020], note: 'Model WHS Act 2011; SA and Tas 2012; WA 2020'},
    {name: 'Occupational Health and Safety Act', years: [2004], note: 'The OHS Act 2004 is current only in Victoria. Other ' +
        'states and territories now use a Work Health and Safety Act (model Act 2011, SA and Tas 2012, WA 2020)'},
    {name: 'Privacy Act', years: [1988]},
    {name: 'Fair Work Act', years: [2009]},
    {name: 'Disability Discrimination Act', years: [1992]},
    {name: 'Sex Discrimination Act', years: [1984]},
    {name: 'Racial Discrimination Act', years: [1975]},
    {name: 'Age Discrimination Act', years: [2004]},
    {name: 'Australian Human Rights Commission Act', years: [1986]},
    {name: 'Corporations Act', years: [2001]},
    {name: 'Competition and Consumer Act', years: [2010]},
    {name: 'National Vocational Education and Training Regulator Act', years: [2011]},
    {name: 'Education Services for Overseas Students Act', years: [2000]},
    {name: 'Copyright Act', years: [1968]},
    {name: 'Spam Act', years: [2003]},
    {name: 'Environment Protection and Biodiversity Conservation Act', years: [1999]},
    {name: 'Food Standards Australia New Zealand Act', years: [1991]},
    {name: 'National Disability Insurance Scheme Act', years: [2013]},
    {name: 'Workplace Gender Equality Act', years: [2012]},
    {name: 'Aged Care Act', years: [1997, 2024], note: 'The Aged Care Act 2024 replaced the 1997 Act from 1 November 2025'},
    {name: 'Anti-Money Laundering and Counter-Terrorism Financing Act', years: [2006]},
    {name: 'Standards for Registered Training Organisations', years: [2015, 2025],
        note: 'The 2025 Standards for RTOs replaced the 2015 Standards from 1 July 2025'},
];

/**
 * Legislation mentioned with a year that does not match, or that has been replaced.
 *
 * @param {string} text
 * @returns {Array} [{start, end, label, message, level: 'warn'|'info'}]
 */
export const lawCheck = text => {
    const out = [];
    LAWS.forEach(law => {
        const re = new RegExp(law.name.replace(/ /g, '\\s+') + '(?:\\s*\\(\\w+\\))?\\s*((?:19|20)\\d{2})?', 'gi');
        let m;
        while ((m = re.exec(text))) {
            const year = m[1] ? parseInt(m[1], 10) : 0;
            if (year && !law.years.includes(year)) {
                out.push({start: m.index, end: m.index + m[0].length, label: m[0], level: 'warn',
                    message: law.note || `${law.name} ${law.years.join(' / ')}`});
            } else if (!year) {
                out.push({start: m.index, end: m.index + m[0].length, label: m[0], level: 'info',
                    message: `${law.name} ${law.years[law.years.length - 1]}`});
            } else if (law.note && (law.name.startsWith('Aged Care') ? year === 1997 : (law.name.startsWith('Standards') ?
                year === 2015 : law.name.startsWith('Occupational')))) {
                out.push({start: m.index, end: m.index + m[0].length, label: m[0], level: 'warn', message: law.note});
            }
        }
    });
    return out;
};

/**
 * In-text citations without a matching reference, and references never cited.
 *
 * @param {string} text
 * @returns {Object} {uncited: string[], missing: [{label, start, end}], haslist: boolean}
 */
export const citationCheck = text => {
    const split = text.search(/(^|\n)\s*(references?|reference list|bibliography|works cited)\s*:?\s*(\n|$)/i);
    const body = split >= 0 ? text.slice(0, split) : text;
    // One entry per reference: a line with no year is the wrapped end of the entry before it.
    const refs = [];
    if (split >= 0) {
        text.slice(split).replace(/^\s+/, '').split('\n').slice(1).map(l => l.trim()).filter(Boolean).forEach(l => {
            if (refs.length && !/\b(?:19|20)\d{2}\b/.test(l)) {
                refs[refs.length - 1] += ' ' + l;
            } else {
                refs.push(l);
            }
        });
    }
    const cites = [];
    // Authors may be one surname, two joined by "and", "et al." or an organisation of up to five words.
    const name = "[A-Z][A-Za-z'’-]+(?:\\s(?:[A-Z][A-Za-z'’-]+|of|for|and|&)){0,4}";
    const re = new RegExp('\\((' + name + ')(?:\\set al\\.?)?(,?)\\s((?:19|20)\\d{2})[a-z]?[^)]*\\)|\\b(' + name +
        ')(?:\\set al\\.?)?\\s\\(((?:19|20)\\d{2})[a-z]?\\)', 'g');
    let m;
    while ((m = re.exec(body))) {
        const author = (m[1] || m[4]).replace(/\s(?:and|&)\s.*$/, '');
        // "(WA 2020)" or "Act 2011" are not citations: an all-capitals short word needs a comma before the year.
        if (m[1] && !m[2] && !/[a-z]/.test(author)) {
            continue;
        }
        if (/^(?:Act|Regulations?|Code|Standard|The|In|At|By|Since|From)$/.test(author.split(/\s/).pop())) {
            continue;
        }
        cites.push({author, year: m[3] || m[5], start: m.index, end: m.index + m[0].length, label: m[0]});
    }
    const has = (r, c) => r.includes(c.author) && r.includes(c.year);
    const missing = split >= 0 ? cites.filter(c => !refs.some(r => has(r, c))) : [];
    const uncited = refs.filter(r => !cites.some(c => has(r, c)));
    return {haslist: split >= 0, cites: cites.length, missing, uncited: split >= 0 ? uncited : []};
};

/* ---------- Grouping similar answers (fixed threshold, no training) ---------- */

/**
 * Group answers that use the same words (Jaccard similarity of word stems ≥ threshold).
 *
 * @param {Array} items [{key, text}]
 * @param {number} threshold
 * @returns {Array} groups: [[key, ...], ...] largest first, singletons left out
 */
export const groupSimilar = (items, threshold = 0.8) => {
    const sets = items.map(it => new Set(tokens(it.text).filter(x => !STOP.has(x.w)).map(x => x.s)));
    const parent = items.map((_, i) => i);
    const find = i => (parent[i] === i ? i : (parent[i] = find(parent[i])));
    for (let i = 0; i < items.length; i++) {
        for (let j = i + 1; j < items.length; j++) {
            const a = sets[i];
            const b = sets[j];
            if (!a.size || !b.size) {
                continue;
            }
            let inter = 0;
            a.forEach(w => {
                if (b.has(w)) {
                    inter++;
                }
            });
            const jac = inter / (a.size + b.size - inter);
            if (jac >= threshold) {
                parent[find(i)] = find(j);
            }
        }
    }
    const groups = new Map();
    items.forEach((it, i) => {
        const r = find(i);
        if (!groups.has(r)) {
            groups.set(r, []);
        }
        groups.get(r).push(it.key);
    });
    return [...groups.values()].filter(g => g.length > 1).sort((a, b) => b.length - a.length);
};

/**
 * Phrases far more common in one set of answers than another (log-odds), to suggest look-fors.
 *
 * @param {string[]} good texts marked satisfactory / high
 * @param {string[]} weak texts marked not yet satisfactory / low
 * @returns {string[]}
 */
export const discriminating = (good, weak) => {
    const count = texts => {
        const m = new Map();
        texts.forEach(tx => {
            const seen = new Set();
            const tk = tokens(tx).filter(x => !STOP.has(x.w) && x.w.length > 2);
            tk.forEach((x, i) => {
                [x.s, i < tk.length - 1 ? x.s + ' ' + tk[i + 1].s : null].filter(Boolean).forEach(k => {
                    if (!seen.has(k)) {
                        seen.add(k);
                        const e = m.get(k) || {n: 0, word: k.includes(' ') ? x.w + ' ' + tk[i + 1].w : x.w};
                        e.n++;
                        m.set(k, e);
                    }
                });
            });
        });
        return m;
    };
    const g = count(good);
    const w = count(weak);
    const out = [];
    g.forEach((e, k) => {
        const pg = (e.n + 0.5) / (good.length + 1);
        const pw = ((w.get(k) || {n: 0}).n + 0.5) / (weak.length + 1);
        const lo = Math.log(pg / (1 - Math.min(pg, 0.999))) - Math.log(pw / (1 - Math.min(pw, 0.999)));
        if (e.n >= Math.max(2, good.length * 0.3) && lo > 1) {
            out.push({word: e.word, lo});
        }
    });
    return out.sort((a, b) => b.lo - a.lo).slice(0, 15).map(x => x.word);
};
