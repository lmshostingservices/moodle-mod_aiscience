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
 * AI Science Experiment: teacher studio, student player, sandbox bridge, notebook and voiceover.
 *
 * @module     mod_aiscience/app
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/ajax', 'core/notification', 'mod_aiscience/marker'], function(Ajax, Notification, Marker) {
    'use strict';

    var C = null;
    var S = {tab: 'build', versions: [], latest: null, current: null, poll: null, versionid: 0, stage: 'Get started',
        exptime: '', notes: [], results: null, selected: null, frameReady: false, previewVersion: 0, available: false};
    var root = null;

    /**
     * Tone for a score: 'bad' below the first limit, 'warn' below the second, otherwise 'ok'.
     *
     * @param {Number} v
     * @param {Number} bad
     * @param {Number} warn
     * @returns {String}
     */
    var toneOf = function(v, bad, warn) {
        if (v < bad) {
            return 'bad';
        }
        return v < warn ? 'warn' : 'ok';
    };
    /**
     * The value for the first key that matches, or the fallback.
     *
     * @param {String} key
     * @param {Object} map
     * @param {*} fallback
     * @returns {*}
     */
    var pick = function(key, map, fallback) {
        return Object.prototype.hasOwnProperty.call(map, key) ? map[key] : fallback;
    };
    var creditsNote = function(balance) {
        if (balance >= 0) {
            return ' Credits left: ' + balance + '.';
        }
        return balance === -2 ? ' Credits: unlimited.' : '';
    };
    var creditsLeft = function(credits) {
        if (credits >= 0) {
            return credits;
        }
        return credits === -2 ? 'unlimited' : '–';
    };
    var rethinkHTML = function(answers) {
        var why = String(answers.predrethink || '').trim();
        if (why) {
            return '<span>“' + esc(why) + '”</span>';
        }
        return answers._predmatch === 'no' ?
            '<span class="aisx-muted">Hasn\'t explained what changed their mind yet.</span>' : '';
    };
    var versionTone = function(v) {
        if (v.current) {
            return 'ok';
        }
        return v.status === 'failed' ? 'bad' : '';
    };
    var esc = function(s) {
        return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function(c) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
        });
    };
    var $ = function(sel, el) {
        return (el || root).querySelector(sel);
    };
    var $$ = function(sel, el) {
        return Array.prototype.slice.call((el || root).querySelectorAll(sel));
    };
    var call = function(methodname, args) {
        return Ajax.call([{methodname: 'mod_aiscience_' + methodname, args: args}])[0];
    };
    var parse = function(s, d) {
        try {
            var v = JSON.parse(s);
            return v === null ? d : v;
        } catch (e) {
            return d;
        }
    };
    var toast = function(text) {
        var t = document.createElement('div');
        t.className = 'aisx-toast';
        t.setAttribute('role', 'status');
        t.textContent = text;
        document.body.appendChild(t);
        setTimeout(function() {
            t.classList.add('out');
        }, 2600);
        setTimeout(function() {
            t.remove();
        }, 3200);
    };
    var icon = function(name) {
        var p = {
            spark: '<circle cx="12" cy="7" r="3.2" fill="currentColor"/><path d="M7 13v6M12 13v5M17 13v4" stroke="currentColor"' +
                ' stroke-width="2" stroke-linecap="round" stroke-dasharray="2 3"/><path d="M4 21L20 17" stroke="currentColor"' +
                ' stroke-width="2.4" stroke-linecap="round"/>',
            speaker: '<path d="M4 9v6h4l5 4V5L8 9H4z" fill="currentColor"/><path d="M16 8.5a5 5 0 0 1 0 7" fill="none"' +
                ' stroke="currentColor" stroke-width="2" stroke-linecap="round"/>',
            mic: '<rect x="9" y="3" width="6" height="11" rx="3" fill="currentColor"/><path d="M5 11a7 7 0 0 0 14 0M12 18v3"' +
                ' fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>',
            camera: '<path d="M4 8h3l2-3h6l2 3h3v11H4z" fill="none" stroke="currentColor" stroke-width="2"' +
                ' stroke-linejoin="round"/><circle cx="12" cy="13" r="3.5" fill="none" stroke="currentColor" stroke-width="2"/>',
            check: '<path d="M5 12.5l4.5 4.5L19 7" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"' +
                ' stroke-linejoin="round"/>',
            cross: '<path d="M7 7l10 10M17 7L7 17" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"/>'
        }[name] || '';
        return '<svg class="aisx-i" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">' + p + '</svg>';
    };

    /* ------------------------------------------------------------------ voiceover */
    var Voice = {
        voice: null,
        pick: function() {
            if (!('speechSynthesis' in window)) {
                return null;
            }
            var vs = window.speechSynthesis.getVoices();
            if (!vs.length) {
                return null;
            }
            var wanted = (C.voice || '').toLowerCase();
            var found = null;
            if (wanted) {
                found = vs.find(function(v) {
                    return v.name.toLowerCase().indexOf(wanted) === 0;
                });
            }
            if (!found) {
                var neutral = ['Microsoft Aria Online', 'Microsoft Jenny Online', 'Microsoft Natasha Online', 'Google US English',
                    'Samantha', 'Google UK English Female', 'Karen', 'Microsoft Zira'];
                neutral.some(function(n) {
                    found = vs.find(function(v) {
                        return v.name.indexOf(n) === 0;
                    });
                    return !!found;
                });
            }
            if (!found) {
                found = vs.find(function(v) {
                    return /^en[-_](AU|GB|US)/i.test(v.lang);
                }) || vs.find(function(v) {
                    return /^en/i.test(v.lang);
                }) || vs[0];
            }
            return found;
        },
        speak: function(text) {
            if (!('speechSynthesis' in window) || !text) {
                return;
            }
            if (!Voice.voice) {
                Voice.voice = Voice.pick();
            }
            var u = new window.SpeechSynthesisUtterance(String(text).replace(/°/g, ' degrees'));
            if (Voice.voice) {
                u.voice = Voice.voice;
                u.lang = Voice.voice.lang;
            } else {
                u.lang = 'en-AU';
            }
            u.rate = C.voicerate || 0.95;
            u.pitch = 1;
            window.speechSynthesis.speak(u);
        },
        stop: function() {
            if ('speechSynthesis' in window) {
                window.speechSynthesis.cancel();
            }
        }
    };

    /* ------------------------------------------------------------------ dictation */
    var dictate = function() {
        return new Promise(function(resolve, reject) {
            var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
            if (!SR) {
                reject(new Error('Speaking answers needs Chrome, Edge or Safari. You can type instead.'));
                return;
            }
            var r = new SR();
            r.lang = document.documentElement.lang === 'en' ? 'en-AU' : (document.documentElement.lang || 'en-AU');
            r.interimResults = false;
            r.maxAlternatives = 1;
            r.onresult = function(e) {
                resolve(e.results[0][0].transcript);
            };
            r.onerror = function(e) {
                reject(new Error(e.error === 'not-allowed' ? 'Allow the microphone to speak your answer.' :
                    'I could not hear that. Try again, a little closer to the microphone.'));
            };
            r.onend = function() {
                setTimeout(function() {
                    reject(new Error('I did not catch anything. Try again.'));
                }, 50);
            };
            try {
                r.start();
            } catch (e) {
                reject(new Error('The microphone is busy. Try again.'));
            }
        });
    };

    /* ------------------------------------------------------------------ sandbox bridge */
    var frame = function() {
        return $('#aisx-frame');
    };
    var post = function(msg) {
        var f = frame();
        if (f && f.contentWindow) {
            msg.labkit = 1;
            f.contentWindow.postMessage(msg, '*');
        }
    };
    var debouncers = {};
    var debounce = function(key, fn, ms) {
        clearTimeout(debouncers[key]);
        debouncers[key] = setTimeout(fn, ms);
    };
    var record = function(type, payload) {
        if (!C.cansubmit || S.isTeacherPreview) {
            return Promise.resolve();
        }
        return call('record', {cmid: C.cmid, versionid: S.versionid, type: type, payload: JSON.stringify(payload || {})})
            .catch(function() {
                feed('Could not save just now. Your work is kept on this page; it will try again.');
            });
    };
    var feed = function(text) {
        var f = $('#aisx-feed');
        if (!f) {
            return;
        }
        var s = document.createElement('span');
        s.textContent = new Date().toLocaleTimeString([], {hour: '2-digit', minute: '2-digit', second: '2-digit'}) + ' · ' + text;
        f.prepend(s);
        while (f.children.length > 6) {
            f.lastChild.remove();
        }
    };
    // eslint-disable-next-line complexity -- One handler for every message the sandboxed experiment can send; each case is short.
    var onMessage = function(e) {
        var f = frame();
        if (!f || e.source !== f.contentWindow) {
            return;
        }
        var m = e.data;
        if (!m || !m.labkit || typeof m.type !== 'string') {
            return;
        }
        var p = m.payload || {};
        switch (m.type) {
            case 'ready':
                S.frameReady = true;
                feed('Experiment loaded');
                break;
            case 'progress':
                S.stage = String(p.name || '').slice(0, 60);
                feed('Reached stage ' + (p.stage || '?') + ': ' + S.stage);
                debounce('progress', function() {
                    record('progress', {stage: p.stage, name: S.stage, readings: p.readings || 0});
                }, 600);
                break;
            case 'time':
                S.exptime = String(p.label || '').slice(0, 30);
                break;
            case 'response':
                debounce('r' + p.k, function() {
                    record('response', {k: p.k, v: p.v});
                }, 900);
                break;
            case 'submit':
                record('submit', p).then(function() {
                    if (C.cansubmit && !S.isTeacherPreview) {
                        toast('Report sent to your teacher');
                    }
                    return null;
                }).catch(Notification.exception);
                feed('Report submitted');
                if (S.isTeacherPreview) {
                    toast('Preview report received. Students\' reports go to Results & grading.');
                }
                break;
            case 'speak':
                Voice.speak(p.text);
                break;
            case 'dictate':
                dictate().then(function(text) {
                    f.contentWindow.postMessage({labkit: 1, reply: m.id, result: text}, '*');
                    return null;
                }).catch(function(err) {
                    f.contentWindow.postMessage({labkit: 1, reply: m.id, error: err.message}, '*');
                });
                break;
            case 'connect':
                f.contentWindow.postMessage({labkit: 1, reply: m.id, error: 'Bluetooth sensors arrive in a later update. ' +
                    'Use the simulation or take real measurements and type them in.'}, '*');
                break;
            case 'error':
                feed('Script message: ' + String(p.message || '').slice(0, 120));
                break;
        }
    };
    var loadExperiment = function(versionid) {
        var f = frame();
        if (!f) {
            return Promise.resolve();
        }
        S.frameReady = false;
        f.removeAttribute('srcdoc');
        return call('experiment', {cmid: C.cmid, versionid: versionid || 0}).then(function(r) {
            if (!r.html) {
                f.replaceWith(Object.assign(document.createElement('div'), {className: 'aisx-empty',
                    innerHTML: '<h3>' + esc(C.role === 'teacher' ? 'No experiment yet' : 'Not published yet') + '</h3><p>' +
                        esc(C.role === 'teacher' ? 'Plan one in Build, or pick a ready-made experiment in the activity settings.' :
                            'Your teacher hasn\'t published this experiment yet.') + '</p>'}));
                return null;
            }
            S.versionid = r.versionid;
            f.srcdoc = r.html;
            var t = $('#aisx-pvtitle');
            if (t) {
                t.textContent = r.title;
            }
            return null;
        }).catch(Notification.exception);
    };

    /* ------------------------------------------------------------------ notebook */
    var notebookHTML = function() {
        return '<aside class="aisx-card aisx-nb" aria-labelledby="aisx-nbh">' +
            '<p class="aisx-eyebrow">Always beside the experiment</p><h3 id="aisx-nbh">Lab notebook</h3>' +
            '<p class="aisx-muted aisx-small">Type, speak or snap what you notice at any stage. Your notes are saved and go to ' +
            'your teacher with your report.</p>' +
            '<div class="aisx-prompts" aria-label="Note starters"><button type="button" data-starter="I noticed ">I noticed…' +
            '</button><button type="button" data-starter="I wonder why ">I wonder…</button><button type="button" ' +
            'data-starter="This surprised me because ">Surprised me…</button></div>' +
            '<label class="aisx-chip"><input type="checkbox" id="aisx-nbpause" checked> Pause the experiment while I ' +
                'write</label>' +
            '<label for="aisx-nbtext" class="sr-only">New note</label>' +
            '<textarea id="aisx-nbtext" rows="3" placeholder="What did you notice?"></textarea>' +
            '<div class="aisx-row"><button type="button" class="aisx-btn aisx-primary" id="aisx-nbadd">Add note</button>' +
            '<button type="button" class="aisx-btn" id="aisx-nbspeak">' + icon('mic') + '<span>Speak</span></button>' +
            '<button type="button" class="aisx-btn" id="aisx-nbrec" aria-pressed="false"><span class="aisx-recdot"></span>' +
            '<span id="aisx-nbrecl">Record voice</span></button>' +
            '<label class="aisx-btn" for="aisx-nbfile">' + icon('camera') + '<span>Photo or audio</span></label>' +
            '<input type="file" id="aisx-nbfile" class="sr-only" accept="image/*,audio/*" capture="environment"></div>' +
            '<p class="aisx-note" id="aisx-nbmsg" role="status"></p><ol class="aisx-nblist" id="aisx-nblist"></ol></aside>';
    };
    var noteItem = function(n) {
        var kind = pick(n.kind, {text: 'Typed', audio: 'Voice'}, 'Photo');
        var body;
        if (n.kind === 'text') {
            body = esc(n.content);
        } else if (n.kind === 'audio') {
            body = '<audio controls preload="none" src="' + esc(n.url) + '"></audio>' + (n.content ? '<div class="aisx-tr">' +
                esc(n.content) + '</div>' : '');
        } else {
            body = '<img src="' + esc(n.url) + '" alt="Photo added to the notebook" loading="lazy">';
        }
        var when = n.timecreated ? new Date(n.timecreated * 1000).toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'}) : '';
        var del = n.id ? '<button type="button" class="aisx-nbdel" data-del="' + esc(String(n.id)) + '" aria-label="Delete this ' +
            kind.toLowerCase() + ' note">Delete</button>' : '';
        return '<li><div class="aisx-meta">' + esc([when, n.stagelabel, n.exptime ? 'at ' + n.exptime : '', kind]
            .filter(Boolean).join(' · ')) + del + '</div>' + body + '</li>';
    };
    var renderNotes = function() {
        var l = $('#aisx-nblist');
        if (l) {
            l.innerHTML = S.notes.map(noteItem).join('');
        }
    };
    var loadNotes = function() {
        if (!C.cansubmit || S.isTeacherPreview) {
            return;
        }
        call('notes', {cmid: C.cmid}).then(function(r) {
            // Keep notes added while the list was loading.
            var have = {};
            r.notes.forEach(function(n) {
                have[String(n.id)] = true;
            });
            S.notes = S.notes.filter(function(n) {
                return n.id && !have[String(n.id)];
            }).concat(r.notes);
            renderNotes();
            return null;
        }).catch(function() {
            return null;
        });
    };
    var addLocalNote = function(n) {
        S.notes.unshift(Object.assign({timecreated: Math.floor(Date.now() / 1000), stagelabel: S.stage, exptime: S.exptime}, n));
        renderNotes();
    };
    var upload = function(blob, filename, transcript) {
        var msg = $('#aisx-nbmsg');
        if (!C.cansubmit || S.isTeacherPreview) {
            addLocalNote({id: 'local-' + Date.now(), kind: blob.type.indexOf('audio') === 0 ? 'audio' : 'image',
                url: URL.createObjectURL(blob), content: transcript || ''});
            msg.textContent = 'Saved on this page (teacher preview notes are not stored).';
            return;
        }
        var fd = new FormData();
        fd.append('media', blob, filename);
        fd.append('cmid', C.cmid);
        fd.append('versionid', S.versionid);
        fd.append('stagelabel', S.stage);
        fd.append('exptime', S.exptime);
        fd.append('transcript', transcript || '');
        fd.append('sesskey', C.sesskey);
        msg.textContent = 'Saving…';
        fetch(C.uploadurl, {method: 'POST', body: fd, credentials: 'same-origin'}).then(function(r) {
            return r.json();
        }).then(function(r) {
            if (!r.noteid) {
                var why = r.error ? String(r.error) : 'Upload failed';
                throw new Error(why);
            }
            addLocalNote({id: r.noteid, kind: r.kind, url: r.url, content: transcript || ''});
            msg.textContent = r.kind === 'audio' ? 'Voice note saved.' : 'Photo saved.';
            return null;
        }).catch(function(err) {
            msg.textContent = 'That didn\'t save: ' + err.message + '. Try again.';
        });
    };
    var bindNotebook = function() {
        var text = $('#aisx-nbtext');
        var msg = $('#aisx-nbmsg');
        var pause = $('#aisx-nbpause');
        text.addEventListener('focus', function() {
            if (pause.checked) {
                post({type: 'control', cmd: 'pause'});
            }
        });
        $$('[data-starter]').forEach(function(b) {
            b.addEventListener('click', function() {
                text.value = (text.value ? text.value.trimEnd() + ' ' : '') + b.dataset.starter;
                text.focus();
                text.setSelectionRange(text.value.length, text.value.length);
            });
        });
        $('#aisx-nbadd').addEventListener('click', function() {
            var t = text.value.trim();
            if (!t) {
                text.focus();
                return;
            }
            var afterAdd = function(r) {
                addLocalNote({id: r && r.noteid ? r.noteid : 'local-' + Date.now(), kind: 'text', content: t});
                text.value = '';
                msg.textContent = 'Note saved.';
                if (pause.checked) {
                    post({type: 'control', cmd: 'play'});
                }
            };
            if (!C.cansubmit || S.isTeacherPreview) {
                afterAdd();
                return;
            }
            call('addnote', {cmid: C.cmid, versionid: S.versionid, content: t, stagelabel: S.stage, exptime: S.exptime})
                .then(afterAdd).catch(Notification.exception);
        });
        // Delete a note: the first click asks, the second deletes.
        $('#aisx-nblist').addEventListener('click', function(e) {
            var b = e.target.closest('[data-del]');
            if (!b) {
                return;
            }
            if (b.dataset.armed !== '1') {
                b.dataset.armed = '1';
                b.textContent = 'Click again to delete';
                b.classList.add('aisx-armed');
                setTimeout(function() {
                    if (b.isConnected && b.dataset.armed === '1') {
                        b.dataset.armed = '';
                        b.textContent = 'Delete';
                        b.classList.remove('aisx-armed');
                    }
                }, 4000);
                return;
            }
            var id = b.dataset.del;
            var drop = function() {
                S.notes = S.notes.filter(function(n) {
                    return String(n.id) !== id;
                });
                renderNotes();
                msg.textContent = 'Note deleted.';
            };
            if (id.indexOf('local-') === 0 || !C.cansubmit || S.isTeacherPreview) {
                drop();
                return;
            }
            b.disabled = true;
            call('deletenote', {cmid: C.cmid, noteid: +id}).then(drop).catch(function(err) {
                b.disabled = false;
                msg.textContent = err.message || 'That note could not be deleted.';
            });
        });
        $('#aisx-nbspeak').addEventListener('click', function() {
            msg.textContent = 'Listening…';
            if (pause.checked) {
                post({type: 'control', cmd: 'pause'});
            }
            dictate().then(function(t) {
                text.value = (text.value ? text.value.trimEnd() + ' ' : '') + t;
                msg.textContent = 'Check it, then press Add note.';
                text.focus();
                return null;
            }).catch(function(err) {
                msg.textContent = err.message;
            });
        });
        var rec = null;
        var chunks = [];
        var sr = null;
        var transcript = '';
        $('#aisx-nbrec').addEventListener('click', function() {
            var btn = $('#aisx-nbrec');
            if (rec && rec.state === 'recording') {
                rec.stop();
                if (sr) {
                    try {
                        sr.stop();
                    } catch (e) {
                        sr = null;
                    }
                }
                return;
            }
            if (!navigator.mediaDevices || !window.MediaRecorder) {
                msg.textContent = 'Voice recording needs a newer browser. You can type or use Speak instead.';
                return;
            }
            navigator.mediaDevices.getUserMedia({audio: true}).then(function(stream) {
                chunks = [];
                transcript = '';
                rec = new window.MediaRecorder(stream);
                rec.ondataavailable = function(e) {
                    chunks.push(e.data);
                };
                rec.onstop = function() {
                    stream.getTracks().forEach(function(t) {
                        t.stop();
                    });
                    var type = rec.mimeType || 'audio/webm';
                    upload(new Blob(chunks, {type: type}), 'voice.' + (type.indexOf('mp4') > -1 ? 'm4a' : 'webm'), transcript);
                    btn.setAttribute('aria-pressed', 'false');
                    $('#aisx-nbrecl').textContent = 'Record voice';
                    if (pause.checked) {
                        post({type: 'control', cmd: 'play'});
                    }
                };
                var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
                if (SR) {
                    sr = new SR();
                    sr.continuous = true;
                    sr.lang = 'en-AU';
                    sr.onresult = function(e) {
                        transcript = Array.prototype.map.call(e.results, function(r) {
                            return r[0].transcript;
                        }).join(' ');
                    };
                    try {
                        sr.start();
                    } catch (e) {
                        sr = null;
                    }
                }
                rec.start();
                if (pause.checked) {
                    post({type: 'control', cmd: 'pause'});
                }
                btn.setAttribute('aria-pressed', 'true');
                $('#aisx-nbrecl').textContent = 'Stop recording';
                msg.textContent = 'Recording… press Stop when you\'re done.';
                return null;
            }).catch(function() {
                msg.textContent = 'Allow the microphone to record a voice note.';
            });
        });
        $('#aisx-nbfile').addEventListener('change', function(e) {
            var file = e.target.files[0];
            if (file) {
                upload(file, file.name, '');
            }
            e.target.value = '';
        });
    };

    /* ------------------------------------------------------------------ player (student and preview) */
    var playerHTML = function(teacher) {
        return '<div class="aisx-pvbar">' +
            '<div><p class="aisx-eyebrow">' + (teacher ? 'Student view' : 'Your experiment') + '</p>' +
            (teacher ? '<h2 id="aisx-pvtitle">' + esc(C.name) + '</h2>' : '<p class="aisx-muted aisx-small" style="margin:0">' +
            'Work through the six steps. Your notebook saves as you go.</p>') + '</div><div class="aisx-row">' +
            (teacher ? '<div class="aisx-seg" role="group" aria-label="Device width"><button type="button" aria-pressed="true" ' +
                'data-w="100%">Laptop</button><button type="button" aria-pressed="false" data-w="820px">Tablet</button>' +
                '<button type="button" aria-pressed="false" data-w="400px">Phone</button></div>' : '') +
            '<button type="button" class="aisx-btn" id="aisx-restart">' + (teacher ? 'Restart as new student' : 'Start again') +
            '</button></div></div>' +
            '<div class="aisx-pvgrid"><div class="aisx-stage"><iframe class="aisx-frame" id="aisx-frame" ' +
            'title="Science experiment" sandbox="allow-scripts allow-forms" allow="fullscreen"></iframe></div>' +
            notebookHTML() + '</div>' + (teacher ? '<div class="aisx-feed" id="aisx-feed" aria-live="polite"></div>' : '');
    };
    var bindPlayer = function(teacher) {
        bindNotebook();
        $('#aisx-restart').addEventListener('click', function() {
            Voice.stop();
            if (teacher) {
                S.notes = [];
                renderNotes();
            }
            loadExperiment(S.previewVersion);
        });
        $$('.aisx-seg button').forEach(function(b) {
            b.addEventListener('click', function() {
                $$('.aisx-seg button').forEach(function(x) {
                    x.setAttribute('aria-pressed', x === b);
                });
                frame().style.width = b.dataset.w;
            });
        });
    };

    /* ------------------------------------------------------------------ studio: build */
    var STATUS = {planning: 'Designing the lesson', planned: 'Plan ready for your review', building: 'Writing the experiment',
        checking: 'Running checks', ready: 'Ready to publish', failed: 'Needs attention', published: 'Live to students',
        retired: 'Earlier version'};
    var listHTML = function(a) {
        return (a || []).map(function(x) {
            return '<li>' + esc(x) + '</li>';
        }).join('');
    };
    var planHTML = function(p, editable) {
        var v = p.variables || {};
        var m = p.model || {};
        var stages = (p.stages || []).map(function(s) {
            return '<div><b>' + esc(Array.isArray(s) ? s[0] : s.name) + '</b><span>' +
                esc(Array.isArray(s) ? s[1] : s.task) + '</span></div>';
        }).join('');
        return '<div class="aisx-plan">' + (editable ?
            '<div class="aisx-field"><label for="aisx-pltitle">Title</label><input type="text" id="aisx-pltitle" value="' +
            esc(p.title) + '"></div><div class="aisx-field"><label for="aisx-plobj">Learning objectives ' +
            '<span class="aisx-muted">(one per line, edit freely)</span></label><textarea id="aisx-plobj" rows="4">' +
            esc((p.objectives || []).join('\n')) + '</textarea></div>' :
            '<h3 class="aisx-dq">' + esc(p.drivingQuestion || '') + '</h3><div class="aisx-box"><h4>Learning objectives</h4><ul>' +
            listHTML(p.objectives) + '</ul></div>') +
            '<div class="aisx-grid2">' +
            '<div class="aisx-box"><h4>Variables</h4><ul><li><b>Change:</b> ' + esc(v.independent) + '</li><li><b>Measure:</b> ' +
            esc(v.dependent) + '</li><li><b>Keep the same:</b> ' + esc((v.controlled || []).join(', ')) + '</li></ul></div>' +
            '<div class="aisx-box"><h4>Science model</h4><p>' + esc(m.description) + '</p><code>' + esc(m.formula) +
            '</code></div>' +
            '<div class="aisx-box"><h4>How students interact</h4><ul>' + listHTML(p.interactives) + '</ul></div>' +
            '<div class="aisx-box"><h4>Notice and wonder checkpoints</h4><ul>' + listHTML(p.noticeWonder) + '</ul></div>' +
            '<div class="aisx-box"><h4>Challenge</h4><p>' + esc(p.challenge || '') + '</p><h4>Findings table</h4><p>' +
            esc((p.findingsTable || []).join(' · ')) + '</p></div>' +
            '<div class="aisx-box"><h4>Misconceptions targeted</h4><ul>' + listHTML(p.misconceptions) + '</ul></div></div>' +
            '<div class="aisx-box"><h4>Stages</h4><div class="aisx-stagelist">' + stages + '</div></div>' +
            ((p.curriculumLinks || []).length ? '<div class="aisx-box"><h4>Your curriculum</h4><p>' +
                esc(p.curriculumLinks.join(' · ')) + '</p></div>' : '') + '</div>';
    };
    var checksHTML = function(list) {
        if (!list || !list.length) {
            return '';
        }
        return '<ul class="aisx-checks">' + list.map(function(c) {
            return '<li class="' + (c.pass ? 'pass' : 'fail') + '"><span class="aisx-ck">' + icon(c.pass ? 'check' : 'cross') +
                '</span><span>' + esc(c.label) + (c.detail ? '<small>' + esc(c.detail) + '</small>' : '') + '</span></li>';
        }).join('') + '</ul>';
    };
    var pipeHTML = function(v) {
        var order = ['plan', 'build', 'check', 'publish'];
        var at = !v ? -1 : {planning: 0, planned: 0, building: 1, checking: 2, ready: 3, failed: 2, published: 4,
            retired: 4}[v.status];
        var names = [['Plan', 'Lesson design you approve'], ['Build', 'AI writes the experiment'],
            ['Check', 'Automated safety & learning tests'], ['Publish', 'You approve, students see it']];
        return '<div class="aisx-pipe">' + order.map(function(o, i) {
            var cls = '';
            if (i < at) {
                cls = 'done';
            } else if (i === at) {
                cls = 'on';
            }
            return '<div class="' + cls + '"><b>' + (i + 1) + ' ' + names[i][0] + '</b>' + names[i][1] + '</div>';
        }).join('') + '</div>';
    };
    // eslint-disable-next-line complexity -- Renders every Build state (planning, building, checks, versions) in one place.
    var renderBuild = function() {
        var pane = $('#aisx-pane-build');
        var vs = S.versions;
        var latest = vs[0] || null;
        var current = vs.find(function(v) {
            return v.current;
        }) || null;
        var working = latest && !latest.current && latest.status !== 'retired' ? latest : null;
        var head = '<div class="aisx-verrow"><span class="aisx-pill ' + (current ? 'live' : 'draft') + '">' +
            (current ? 'Live to students' : 'Not published') + '</span>' + (current ? '<b>' +
            esc(parse(current.plan, {}).title || C.name) + '</b><span class="aisx-muted">Version ' + current.versionnum +
            (current.source === 'library' ? ' · ready-made library' : '') + '</span>' : '') +
            '<a class="aisx-btn aisx-sm" href="' + esc(C.editurl) + '">Edit brief</a></div>';
        var body = '';
        var target = working || current;
        if (working && (working.status === 'planning' || working.status === 'building' || working.status === 'checking')) {
            var age = Math.floor(Date.now() / 1000) - working.timemodified;
            body = '<div class="aisx-status"><span class="aisx-spin"></span>' + esc(STATUS[working.status]) + '…</div>' +
                '<p class="aisx-muted aisx-small">' + (working.status === 'planning' ? 'Usually under a minute.' :
                'Usually 2–5 minutes. You can leave this page; it carries on.') + (age > 75 ?
                ' Still queued? Moodle runs it when scheduled tasks next run.' : '') + '</p>' +
                (working.instruction ? '<p class="aisx-note">Change requested: "' + esc(working.instruction) + '"</p>' : '');
        } else if (working && working.status === 'planned') {
            body = '<p class="aisx-eyebrow aisx-blue">Review before building</p><p class="aisx-muted aisx-small">Check the ' +
                'plan suits your class. Edit the title or objectives, then build.</p>' + planHTML(parse(working.plan, {}), true) +
                '<div class="aisx-row aisx-mt"><button type="button" class="aisx-btn aisx-primary" data-act="build" ' +
                'data-id="' + working.id + '">Approve plan &amp; build' + cost('build') + '</button><button type="button" ' +
                'class="aisx-btn" data-act="plan">Plan again' + cost('plan') + '</button></div>';
        } else if (working && working.status === 'ready') {
            body = checksHTML(parse(working.checks, [])) + '<div class="aisx-row aisx-mt"><button type="button" class="aisx-btn" ' +
                'data-act="preview" data-id="' + working.id + '">Preview as a student</button><button type="button" ' +
                'class="aisx-btn" data-act="refine" data-id="' + working.id + '">Edit</button>' + (C.canpublish ?
                '<button type="button" class="aisx-btn aisx-ok" data-act="publish" data-id="' + working.id +
                '">Approve &amp; publish</button>' : '') + '</div>' + planHTML(parse(working.plan, {}), false);
        } else if (working && working.status === 'failed') {
            body = '<p class="aisx-err">' + esc(working.error || 'Something went wrong.') + '</p>' +
                checksHTML(parse(working.checks, [])) + '<div class="aisx-row">' + (working.plan ?
                '<button type="button" class="aisx-btn aisx-primary" data-act="build" data-id="' + working.id +
                '">Build again' + cost('build') + '</button>' : '') + '<button type="button" class="aisx-btn" data-act="plan">' +
                'Plan again' + cost('plan') + '</button></div>';
        } else if (current) {
            body = checksHTML(parse(current.checks, [])) + '<div class="aisx-row aisx-mt"><button type="button" ' +
                'class="aisx-btn aisx-primary" data-act="gopreview">Open student preview</button><button type="button" ' +
                'class="aisx-btn" data-act="refine" data-id="' + current.id + '">Edit &amp; refine</button><button ' +
                'type="button" class="aisx-btn" data-act="goresults">See results</button></div>' +
                (S.available ? '<div class="aisx-change"><label for="aisx-chg"><b>Ask for a change</b></label>' +
                '<p class="aisx-muted aisx-small">For example: "Add a Year 4 vocabulary card", "Make the sliders bigger", ' +
                '"Use a torch instead of a lamp". You review the new version before students see it.</p>' +
                '<textarea id="aisx-chg" rows="2"></textarea><button type="button" class="aisx-btn" data-act="change">' +
                'Make this change' + cost('refine') + '</button></div>' : '') + planHTML(parse(current.plan, {}), false);
        } else {
            body = '<div class="aisx-hero"><div class="aisx-heroicon">' + icon('spark') + '</div><h3>Let\'s build your ' +
                'experiment</h3><p>' + esc(C.question || 'Add a question in the brief first.') + '</p>' + (S.available ?
                '<button type="button" class="aisx-btn aisx-sun aisx-lg" data-act="plan">Plan experiment' + cost('plan') +
                '</button>' + (S.prices && S.prices.build ? '<p class="aisx-muted aisx-small">Building the experiment after ' +
                'you approve the plan uses ' + S.prices.build + ' LMS Labs credits.' + creditsNote(S.balance) + '</p>' : '') :
                '<p class="aisx-err">' + NOAI + ', or pick a ready-made experiment in ' +
                '<a href="' + esc(C.editurl) + '">the activity settings</a>.</p>') + '</div>';
        }
        var vrows = vs.map(function(v) {
            return '<tr><td>' + v.versionnum + '</td><td>' + esc(parse(v.plan, {}).title || '—') + '</td><td>' +
                ({library: 'Library', edit: 'Your edit'}[v.source] || (v.instruction ? 'AI refine' : 'AI')) +
                (v.instruction ? '<small class="aisx-vnote">' + esc(v.instruction) + '</small>' : '') +
                    '</td><td><span class="aisx-tag ' + versionTone(v) + '">' +
                        esc(v.current ? 'Live' : STATUS[v.status] || v.status) +
                '</span></td><td>' + new Date(v.timemodified * 1000).toLocaleString([], {dateStyle: 'medium',
                timeStyle: 'short'}) + '</td><td class="aisx-r">' + (v.status !== 'planning' && v.status !== 'planned' &&
                v.status !== 'building' ? '<button type="button" class="aisx-btn aisx-sm" data-act="preview" data-id="' + v.id +
                '">Preview</button> <button type="button" class="aisx-btn aisx-sm" data-act="refine" data-id="' + v.id +
                '">Edit</button>' : '') + (C.canpublish && !v.current && (v.status === 'ready' || v.status === 'retired') ?
                ' <button type="button" class="aisx-btn aisx-sm" data-act="publish" data-id="' + v.id + '">Publish</button>' :
                '') + '</td></tr>';
        }).join('');
        pane.innerHTML = '<div class="aisx-cols"><section class="aisx-card"><p class="aisx-eyebrow">Experiment</p>' + head +
            pipeHTML(target) + '<div aria-live="polite">' + body + '</div></section>' +
            '<section class="aisx-card"><p class="aisx-eyebrow">Brief</p><h3 class="aisx-q">' +
            esc(C.question || 'No question yet') + '</h3><p class="aisx-muted">' + esc(C.year) + '</p>' +
            '<h4 class="aisx-h4">Versions</h4>' + (vs.length ? '<div class="aisx-tblwrap"><table class="aisx-tbl"><thead><tr><th>' +
            '#</th><th>Title</th><th>Source</th><th>Status</th><th>Updated</th><th></th></tr></thead><tbody>' + vrows +
            '</tbody></table></div>' : '<p class="aisx-muted">No versions yet.</p>') + '</section></div>';
        $$('[data-act]', pane).forEach(function(b) {
            b.addEventListener('click', function() {
                act(b.dataset.act, parseInt(b.dataset.id || '0', 10), b);
            });
        });
        var busy = working && ['planning', 'building', 'checking'].indexOf(working.status) > -1;
        clearTimeout(S.poll);
        if (busy) {
            S.poll = setTimeout(loadStatus, 4000);
        }
    };
    var act = function(what, id, btn) {
        var afterAsk = function() {
            loadStatus();
        };
        if (btn) {
            btn.disabled = true;
        }
        if (what === 'plan') {
            call('plan', {cmid: C.cmid}).then(afterAsk).catch(Notification.exception);
        } else if (what === 'build') {
            var title = $('#aisx-pltitle');
            var obj = $('#aisx-plobj');
            call('build', {cmid: C.cmid, versionid: id, title: title ? title.value : '', objectives: obj ? obj.value : ''})
                .then(afterAsk).catch(Notification.exception);
        } else if (what === 'publish') {
            call('publish', {cmid: C.cmid, versionid: id}).then(function() {
                toast('Published. Students see this version now.');
                C.published = id;
                afterAsk();
                return null;
            }).catch(Notification.exception);
        } else if (what === 'change') {
            var t = $('#aisx-chg').value.trim();
            if (!t) {
                btn.disabled = false;
                $('#aisx-chg').focus();
                return;
            }
            call('change', {cmid: C.cmid, instruction: t}).then(afterAsk).catch(Notification.exception);
        } else if (what === 'preview') {
            S.previewVersion = id;
            tab('preview');
        } else if (what === 'gopreview') {
            S.previewVersion = 0;
            tab('preview');
        } else if (what === 'goresults') {
            tab('results');
        } else if (what === 'refine') {
            S.refineVersion = id;
            tab('refine');
        }
    };
    var cost = function(task) {
        var n = S.prices && S.prices[task];
        return n ? ' · ' + n + (n === 1 ? ' credit' : ' credits') : '';
    };
    var NOAI = 'This site is not connected to LMS Labs AI. Ask your Moodle admin to add the LMS Labs Site ID and API key';
    var loadStatus = function() {
        return call('status', {cmid: C.cmid}).then(function(r) {
            S.versions = r.versions;
            S.available = r.available;
            S.prices = parse(r.prices, {});
            S.balance = r.credits;
            if (S.tab === 'build') {
                renderBuild();
            }
            return null;
        }).catch(Notification.exception);
    };

    /* ------------------------------------------------------------------ studio: results */
    var svg = function(tag, attrs, parent) {
        var e = document.createElementNS('http://www.w3.org/2000/svg', tag);
        Object.keys(attrs).forEach(function(k) {
            e.setAttribute(k, attrs[k]);
        });
        if (parent) {
            parent.appendChild(e);
        }
        return e;
    };
    var drawPool = function(el, rows) {
        el.innerHTML = '';
        var keys = [];
        rows.forEach(function(r) {
            Object.keys(parse(r.phases, {})).forEach(function(k) {
                if (keys.indexOf(k) < 0 && typeof parse(r.phases, {})[k] === 'number') {
                    keys.push(k);
                }
            });
        });
        if (!keys.length) {
            svg('text', {x: 280, y: 140, 'text-anchor': 'middle', 'class': 'aisx-svgmuted'}, el).textContent =
                'Class data appears here as reports come in.';
            return;
        }
        var all = [];
        rows.forEach(function(r) {
            keys.forEach(function(k) {
                var v = parse(r.phases, {})[k];
                if (typeof v === 'number') {
                    all.push(v);
                }
            });
        });
        var max = Math.max.apply(null, all.concat([1])) * 1.12;
        var nice = Math.pow(10, Math.floor(Math.log10(max)));
        max = Math.ceil(max / nice) * nice;
        var L = 64;
        var T = 16;
        var W = 476;
        var H = 220;
        var yAt = function(v) {
            return T + H - v / max * H;
        };
        for (var i = 0; i <= 4; i++) {
            var v = max * i / 4;
            svg('line', {x1: L, x2: L + W, y1: yAt(v), y2: yAt(v), 'class': 'aisx-grid'}, el);
            svg('text', {x: L - 8, y: yAt(v) + 4, 'text-anchor': 'end', 'class': 'aisx-svgmuted'}, el).textContent =
                Math.round(v).toLocaleString();
        }
        var bw = W / keys.length;
        keys.forEach(function(k, ki) {
            var cx = L + bw * (ki + 0.5);
            var vals = rows.map(function(r) {
                return parse(r.phases, {})[k];
            }).filter(function(x) {
                return typeof x === 'number';
            });
            var mean = vals.reduce(function(a, b) {
                return a + b;
            }, 0) / Math.max(1, vals.length);
            svg('rect', {x: cx - bw * 0.28, y: yAt(mean), width: bw * 0.56, height: T + H - yAt(mean), rx: 6,
                'class': 'aisx-bar k' + (ki % 4)}, el);
            vals.forEach(function(x, j) {
                svg('circle', {cx: cx + ((j % 7) - 3) * 6, cy: yAt(x), r: 4.5, 'class': 'aisx-dot'}, el);
            });
            svg('text', {x: cx, y: T + H + 20, 'text-anchor': 'middle', 'class': 'aisx-svglabel'}, el).textContent =
                k.charAt(0).toUpperCase() + k.slice(1);
            var top = Math.min.apply(null, vals.map(function(x) {
                return yAt(x);
            }).concat([yAt(mean)]));
            svg('text', {x: cx, y: Math.max(12, top - 12), 'text-anchor': 'middle', 'class': 'aisx-svgval'}, el).textContent =
                Math.round(mean).toLocaleString();
        });
    };
    var drawPred = function(el, rows) {
        el.innerHTML = '';
        var counts = {};
        var right = {};
        rows.forEach(function(r) {
            if (parse(r.answers, {})._predmatch === 'yes' && r.prediction) {
                right[r.prediction] = true;
            }
        });
        rows.forEach(function(r) {
            if (r.prediction) {
                counts[r.prediction] = (counts[r.prediction] || 0) + 1;
            }
        });
        var keys = Object.keys(counts);
        if (!keys.length) {
            svg('text', {x: 0, y: 20, 'class': 'aisx-svgmuted'}, el).textContent = 'No predictions yet.';
            el.setAttribute('viewBox', '0 0 560 40');
            return;
        }
        var max = Math.max.apply(null, keys.map(function(k) {
            return counts[k];
        }));
        el.setAttribute('viewBox', '0 0 560 ' + (keys.length * 32 + 8));
        keys.forEach(function(k, i) {
            var y = 6 + i * 32;
            var w = counts[k] / max * 240;
            svg('text', {x: 0, y: y + 15, 'class': 'aisx-svglabel'}, el).textContent = (right[k] ? '✓ ' : '') +
                (k.length > 30 ? k.slice(0, 29) + '…' : k);
            svg('rect', {x: 240, y: y, width: Math.max(3, w), height: 20, rx: 5, 'class': 'aisx-bar ' + (right[k] ||
                !Object.keys(right).length ? 'k1' : 'kx')}, el);
            svg('text', {x: 248 + w, y: y + 15, 'class': 'aisx-svgval'}, el).textContent = counts[k] + (right[k] ?
                ' · matches the evidence' : '');
        });
    };
    var tipn = 0;
    var tip = function(text) {
        tipn++;
        return '<span class="aisx-tipw"><button type="button" class="aisx-i" aria-label="What does this mean?" ' +
            'aria-describedby="aisx-tip' + tipn + '">i</button><span role="tooltip" class="aisx-tipb" id="aisx-tip' + tipn + '">' +
            esc(text) + '</span></span>';
    };
    var markAll = function() {
        var r = S.results;
        S.marks = S.marks || {};
        r.attempts.forEach(function(a) {
            if (a.status !== 'inprogress' && !S.marks[a.attemptid]) {
                S.marks[a.attemptid] = Marker.markAttempt(r, a);
            }
        });
    };
    var refreshMark = function(m) {
        m.unsure = Object.keys(m.q).filter(function(k) {
            return m.q[k].conf === 'low' && !m.q[k].empty;
        });
        m.total = Object.keys(m.q).reduce(function(t, k) {
            return t + m.q[k].mark;
        }, 0);
        m.rubric = Marker.rubricOf(m.guide, m.q);
        m.feedback = Marker.feedbackFor(m.guide, m.q, m.rubric);
    };
    var preview = function(rub, max) {
        var t = (rub.claim || 0) + (rub.evidence || 0) + (rub.reasoning || 0);
        return max > 0 ? Math.round(t / 12 * max * 10) / 10 + ' / ' + max : t + ' / 12';
    };
    var ungraded = function() {
        return S.results.attempts.filter(function(a) {
            return a.status === 'submitted' && S.marks[a.attemptid];
        });
    };
    var unsureItems = function() {
        var items = [];
        ungraded().forEach(function(a) {
            S.marks[a.attemptid].unsure.forEach(function(k) {
                items.push({attemptid: a.attemptid, k: k});
            });
        });
        return items;
    };
    var allItems = function() {
        var items = [];
        ungraded().forEach(function(a) {
            var m = S.marks[a.attemptid];
            Object.keys(m.q).forEach(function(k) {
                if (!m.q[k].empty && m.q[k].by !== 'ai') {
                    items.push({attemptid: a.attemptid, k: k});
                }
            });
        });
        return items;
    };
    var withHints = function(items) {
        return items.map(function(it) {
            var q = S.marks[it.attemptid] && S.marks[it.attemptid].q[it.k];
            return {attemptid: it.attemptid, k: it.k, hint: q && q.by === 'engine' ? q.why : ''};
        });
    };
    var runAI = function(items, btn, msg) {
        if (btn.dataset.sure !== '1') {
            btn.dataset.sure = '1';
            btn.dataset.label = btn.textContent;
            btn.textContent = 'Click again to use up to ' + items.length + (items.length === 1 ? ' credit' : ' credits');
            return;
        }
        $$('.aisx-amark button').forEach(function(x) {
            x.disabled = true;
        });
        // Five reports per request: each report is one AI request on the server.
        var byAttempt = {};
        items.forEach(function(it) {
            (byAttempt[it.attemptid] = byAttempt[it.attemptid] || []).push(it);
        });
        var ids = Object.keys(byAttempt);
        var batches = [];
        for (var b = 0; b < ids.length; b += 5) {
            batches.push([].concat.apply([], ids.slice(b, b + 5).map(function(id) {
                return byAttempt[id];
            })));
        }
        var used = 0;
        var total = batches.length;
        var run = function() {
            if (!batches.length) {
                toast('AI marked ' + used + (used === 1 ? ' answer' : ' answers') + ' · ' + used + (used === 1 ? ' credit' :
                    ' credits') + ' used.');
                renderResults();
                return;
            }
            msg.textContent = 'Marking with AI… ' + (total - batches.length + 1) + ' of ' + total + ' groups of reports';
            call('aimark', {cmid: C.cmid, items: JSON.stringify(withHints(batches.shift()))}).then(function(res) {
                applyAI(res.results, 'ai');
                var charged = res.results.filter(function(x) {
                    return x.charged;
                }).length;
                used += charged;
                S.results.credits = res.credits;
                S.results.creditsused += charged;
                if (res.results.some(function(x) {
                    return /credits/.test(x.error);
                })) {
                    batches = [];
                    toast('Out of LMS Labs credits. The comparison marks (no credits) stay for the rest.');
                }
                run();
                return null;
            }).catch(function(e) {
                Notification.exception(e);
                renderResults();
            });
        };
        run();
    };
    var applyAI = function(rows, by) {
        rows.forEach(function(x) {
            var m = S.marks[x.attemptid];
            if (!m || !m.q[x.k] || x.error) {
                return;
            }
            m.q[x.k] = Object.assign({}, m.q[x.k], {mark: x.mark, why: x.why, by: by, conf: 'high'});
            refreshMark(m);
        });
    };
    var amarkHTML = function() {
        var r = S.results;
        var todo = ungraded();
        var unsure = unsureItems();
        return '<div class="aisx-amark"><div class="aisx-amhead"><b>Auto-marking</b>' + tip('Each answer is compared with ' +
            'the model answer and key ideas for its question, using the same comparison engine as Fast Grade. It runs in your ' +
            'browser, so it uses no AI and no credits. It checks the key ideas (with common synonyms), whether numbers come from ' +
            'the student\'s own results, whether they explain why, and common misconceptions. Nothing is saved until you ' +
            'approve it.') + '</div><p class="aisx-small">' + (todo.length ? 'Suggested marks for <b>' + todo.length +
            '</b> ungraded ' + (todo.length === 1 ? 'report' : 'reports') + '. <b>' + unsure.length + '</b> ' +
            (unsure.length === 1 ? 'answer is' : 'answers are') + ' marked "unsure"' + tip('"Unsure" means the comparison ' +
            'could not be confident: for example the answer may be right in different words, or it mixes a right idea with a ' +
            'misconception. Read these yourself, or have them checked.') + '.' : 'No ungraded reports right now.') + '</p>' +
            (todo.length ? '<div class="aisx-row">' + (r.aimark && allItems().length ? '<button type="button" class="aisx-btn ' +
            'aisx-sun aisx-sm" id="aisx-aimall"' + (r.credits === 0 ? ' disabled' : '') + '>Mark all ' + allItems().length +
            ' answers with AI · ' + allItems().length + ' credits</button>' + tip('Recommended for science reports. The AI reads ' +
            'each answer like a teacher would, so it gives credit for right ideas in the student\'s own words. It is also told ' +
            'what the comparison found (whether the numbers match the student\'s own results, and any misconceptions). 1 credit ' +
            'per answer marked. Blank answers and failed calls use no credits.') : '') + (r.aimark && unsure.length ? '<button ' +
            'type="button" class="aisx-btn aisx-sm" id="aisx-aim"' + (r.credits === 0 ? ' disabled' : '') + '>Only the ' +
            unsure.length + ' unsure · ' + unsure.length + (unsure.length === 1 ? ' credit' : ' credits') + '</button>' : '') +
            (S.ondevice && unsure.length ? '<button type="button" class="aisx-btn aisx-sm" id="aisx-od">Check unsure answers on ' +
            'this computer (no credits)</button>' : '') + '<button type="button" class="aisx-btn aisx-primary aisx-sm" ' +
            'id="aisx-approve">Approve all suggested marks</button></div>' : '') +
            (r.aimark ? '<p class="aisx-note">AI marking: 1 LMS Labs credit per answer marked. Credits left: <b>' +
            creditsLeft(r.credits) +
            '</b> · used by this activity: ' + r.creditsused + '.</p>' : '') + '<p class="aisx-note" id="aisx-amsg" ' +
            'aria-live="polite"></p></div>';
    };
    var refreshBar = function() {
        var old = $('.aisx-amark');
        if (old) {
            old.insertAdjacentHTML('afterend', amarkHTML());
            old.remove();
            bindAmark();
        }
    };
    var bindAmark = function() {
        var msg = $('#aisx-amsg');
        var od = $('#aisx-od');
        if (od) {
            od.addEventListener('click', function() {
                var items = unsureItems();
                od.disabled = true;
                var i = 0;
                var nextItem = function() {
                    if (i >= items.length) {
                        msg.textContent = 'Checked ' + items.length + ' answers on this computer.';
                        renderResults();
                        return;
                    }
                    var it = items[i++];
                    var a = S.results.attempts.find(function(x) {
                        return x.attemptid === it.attemptid;
                    });
                    var m = S.marks[it.attemptid];
                    var q = m.guide.questions.find(function(x) {
                        return x.k === it.k;
                    });
                    msg.textContent = 'Checking ' + i + ' of ' + items.length + '…';
                    Marker.onDeviceMark(q, Marker.parse(a.answers, {})[it.k], a, S.results.year).then(function(res) {
                        applyAI([{attemptid: it.attemptid, k: it.k, mark: res.mark, why: res.why}], 'ondevice');
                        return null;
                    }).catch(function() {
                        return null;
                    }).then(nextItem).catch(Notification.exception);
                };
                nextItem();
            });
        }
        var aim = $('#aisx-aim');
        if (aim) {
            aim.addEventListener('click', function() {
                runAI(unsureItems(), aim, msg);
            });
        }
        var aimall = $('#aisx-aimall');
        if (aimall) {
            aimall.addEventListener('click', function() {
                runAI(allItems(), aimall, msg);
            });
        }
        var ap = $('#aisx-approve');
        if (ap) {
            ap.addEventListener('click', function() {
                var todo = ungraded();
                if (ap.dataset.sure !== '1') {
                    ap.dataset.sure = '1';
                    ap.textContent = 'Click again to grade ' + todo.length + (todo.length === 1 ? ' report' : ' reports');
                    return;
                }
                ap.disabled = true;
                var items = todo.map(function(a) {
                    var m = S.marks[a.attemptid];
                    return {attemptid: a.attemptid, claim: m.rubric.claim, evidence: m.rubric.evidence,
                        reasoning: m.rubric.reasoning, feedback: a.feedback || m.feedback, marks: m.q};
                });
                call('applymarks', {cmid: C.cmid, items: JSON.stringify(items)}).then(function(res) {
                    toast('Graded ' + res.saved + (res.saved === 1 ? ' report' : ' reports') + '. Grades are in the gradebook.');
                    S.marks = {};
                    loadResults();
                    return null;
                }).catch(Notification.exception);
            });
        }
    };
    var renderResults = function() {
        var pane = $('#aisx-pane-results');
        var r = S.results;
        if (!r) {
            pane.innerHTML = '<div class="aisx-status"><span class="aisx-spin"></span>Loading results…</div>';
            return;
        }
        var rows = r.attempts;
        markAll();
        var submitted = rows.filter(function(a) {
            return a.status !== 'inprogress';
        });
        var graded = rows.filter(function(a) {
            return a.grade >= 0;
        });
        var avg = graded.length ? (graded.reduce(function(s, a) {
            return s + a.grade;
        }, 0) / graded.length).toFixed(1) : '—';
        var notes = rows.reduce(function(s, a) {
            return s + a.notes.length;
        }, 0);
        var shifts = rows.map(function(a) {
            return {a: a, ans: parse(a.answers, {})};
        }).filter(function(x) {
            return x.ans._predmatch;
        });
        var right = shifts.filter(function(x) {
            return x.ans._predmatch === 'yes';
        });
        var wrong = shifts.filter(function(x) {
            return x.ans._predmatch === 'no';
        });
        var revised = wrong.filter(function(x) {
            return (x.ans.predrethink || '').trim().length > 10;
        });
        var follow = wrong.filter(function(x) {
            return revised.indexOf(x) < 0;
        });
        var shiftHTML = shifts.length ? '<section class="aisx-card aisx-shift"><p class="aisx-eyebrow">Thinking shifts</p>' +
            '<h3>Did their ideas change?</h3><div class="aisx-shiftbar" role="img" aria-label="' + right.length +
            ' predicted the pattern, ' + revised.length + ' changed their minds, ' + follow.length + ' still to talk to">' +
            [[right.length, 'ok'], [revised.length, 'rev'], [follow.length, 'todo']].map(function(x) {
                return x[0] ? '<i class="' + x[1] + '" style="flex:' + x[0] + '"></i>' : '';
            }).join('') + '</div><ul class="aisx-shiftkey"><li><i class="ok"></i><b>' + right.length + '</b> predicted the ' +
            'pattern their data showed</li><li><i class="rev"></i><b>' + revised.length + '</b> started with a different idea ' +
            'and explained what changed their mind</li><li><i class="todo"></i><b>' + follow.length + '</b> started with a ' +
            'different idea and haven\'t explained it yet' + (follow.length ? ': <span class="aisx-names">' +
            follow.slice(0, 8).map(function(x) {
                return esc(x.a.fullname);
            }).join(', ') + (follow.length > 8 ? ' and ' + (follow.length - 8) + ' more' : '') + '</span>' : '') +
            '</li></ul>' + (wrong.length ? '<p class="aisx-muted aisx-small">Start the next lesson with the most common ' +
            'mistaken prediction: <b>' + esc(commonWrong(wrong)) + '</b>. Ask a student who changed their mind to explain ' +
            'what their data showed.</p>' : '') + '</section>' : '';
        var kpis = [['Submitted', submitted.length + ' / ' + rows.length], ['Graded', graded.length],
            ['Average grade', avg + (graded.length ? ' / ' + r.maxgrade : '')], ['Notebook entries', notes]];
        var gradeCell = function(a, maxgrade) {
            if (a.grade >= 0) {
                return a.grade + ' / ' + maxgrade;
            }
            var mk = S.marks[a.attemptid];
            if (!mk) {
                return '—';
            }
            return '<span class="aisx-sugg">' + preview(mk.rubric, maxgrade) + (mk.unsure.length ? ' · ' +
                mk.unsure.length + ' unsure' : '') + '</span>';
        };
        var trs = rows.map(function(a, i) {
            var st = pick(a.status, {graded: ['ok', 'Graded'], submitted: ['warn', 'Needs grading']},
                ['', 'Working: ' + (a.stagename || 'stage ' + a.stage)]);
            return '<tr tabindex="0" data-i="' + i + '" class="' + (S.selected === i ? 'sel' : '') + '"><td>' +
                esc(a.fullname) + '</td><td>' + esc(a.prediction || '—') + '</td><td>' + (a.readings || '—') + '</td><td>' +
                a.notes.length + '</td><td><span class="aisx-tag ' + st[0] + '">' + esc(st[1]) + '</span></td><td>' +
                gradeCell(a, r.maxgrade) + '</td></tr>';
        }).join('');
        pane.innerHTML = '<div class="aisx-kpis">' + kpis.map(function(k) {
            return '<div class="aisx-kpi"><div class="v">' + esc(k[1]) + '</div><div class="l">' + esc(k[0]) + '</div></div>';
        }).join('') + '</div>' + shiftHTML +
            '<div class="aisx-rgrid"><section class="aisx-card"><h3>Class data, pooled</h3><p class="aisx-muted ' +
            'aisx-small">Bars show the class average for each condition; dots are individual students. Outliers are a ' +
            'great start for a discussion about measurement.</p><svg id="aisx-pool" viewBox="0 0 560 270" role="img" ' +
            'aria-label="Class averages per condition"></svg><h3 class="aisx-mt">What students predicted</h3><p ' +
                'class="aisx-muted ' +
            'aisx-small">✓ marks the prediction their own data supported.</p>' +
            '<svg id="aisx-pred" viewBox="0 0 560 140" role="img" aria-label="Prediction choices"></svg></section>' +
            '<section class="aisx-card"><h3>Students</h3>' + amarkHTML() +
                (rows.length ? '<div class="aisx-tblwrap"><table class="aisx-tbl ' +
            'aisx-click"><thead><tr><th>Student</th><th>Prediction</th><th>Readings</th><th>Notes</th><th>Status</th>' +
            '<th>Grade' + tip('Saved grades are shown in black. Suggested marks from auto-marking are shown in grey until you ' +
            'approve them.') + '</th></tr></thead><tbody>' + trs + '</tbody></table></div>' :
            '<p class="aisx-muted">No students have started yet.</p>') + '<div id="aisx-grader"></div></section></div>';
        bindAmark();
        drawPool($('#aisx-pool'), submitted);
        drawPred($('#aisx-pred'), rows);
        $$('tbody tr[data-i]', pane).forEach(function(tr) {
            var pick = function() {
                S.selected = parseInt(tr.dataset.i, 10);
                S.selectedId = r.attempts[S.selected].attemptid;
                renderResults();
                $('#aisx-grader').scrollIntoView({block: 'nearest', behavior: 'smooth'});
            };
            tr.addEventListener('click', pick);
            tr.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    pick();
                }
            });
        });
        renderGrader();
    };
    var commonWrong = function(list) {
        var c = {};
        list.forEach(function(x) {
            var k = x.a.prediction || '—';
            c[k] = (c[k] || 0) + 1;
        });
        return Object.keys(c).sort(function(a, b) {
            return c[b] - c[a];
        })[0];
    };
    var RUBRIC = {
        claim: ['No claim yet.', 'A claim, but it doesn\'t answer the question.', 'Partly answers the question.',
            'A clear claim that answers the question.', 'Clear and precise: says how the variables are related.'],
        evidence: ['No evidence.', 'Evidence isn\'t from their data.', 'Uses their data, but not specific numbers.',
            'Specific numbers from their own data.', 'Enough specific numbers, compared to show the pattern.'],
        reasoning: ['No reasoning.', 'Repeats the evidence without explaining it.', 'Names a science idea but doesn\'t ' +
            'link it to the evidence.', 'Links the evidence to the science idea.', 'A clear chain from evidence to claim, ' +
            'using the science idea in their own words.']
    };
    var checkLine = function(ans) {
        if (ans._checkfirst === undefined) {
            return '';
        }
        var f = parseInt(ans._checkfirst, 10);
        var e = ans._checksend === undefined ? null : parseInt(ans._checksend, 10);
        return '<p class="aisx-note">Before-you-send check: ' + f + (f === 1 ? ' tip' : ' tips') + ' at first' + (e === null ? '' :
            ', ' + e + ' left when sent' + (e < f ? ' (improved after feedback)' : '')) + '.</p>';
    };
    // eslint-disable-next-line complexity -- Renders the grader: answers, marks, rubric and feedback for one report.
    var renderGrader = function() {
        var g = $('#aisx-grader');
        var r = S.results;
        var a = S.selected !== null ? r.attempts[S.selected] : null;
        if (!a) {
            g.innerHTML = '<p class="aisx-muted aisx-small aisx-mt">Select a student to read their report, findings and ' +
                'notebook, and grade it with the CER rubric.</p>';
            return;
        }
        var rub = parse(a.rubric, {});
        var mk = S.marks && S.marks[a.attemptid];
        a._rub = a._rub || (a.status === 'graded' || !mk ? {claim: rub.claim, evidence: rub.evidence, reasoning: rub.reasoning} :
            Object.assign({}, mk.rubric));
        var answers = parse(a.answers, {});
        var findings = parse(answers._findings || '[]', []);
        var crit = [['claim', 'Claim'], ['evidence', 'Evidence'], ['reasoning', 'Reasoning']];
        var ftable = findings.length ? '<div class="aisx-tblwrap"><table class="aisx-tbl"><tbody>' + findings.map(function(row) {
            return '<tr>' + (Array.isArray(row) ? row : Object.values(row)).map(function(c) {
                return '<td>' + esc(c) + '</td>';
            }).join('') + '</tr>';
        }).join('') + '</tbody></table></div>' : '';
        var pc = answers._predmatch ? '<div class="aisx-pcline ' + (answers._predmatch === 'yes' ? 'ok' : 'no') + '"><b>' +
            (answers._predmatch === 'yes' ? 'Prediction supported' : 'Prediction not supported') + '</b> · predicted "' +
            esc(a.prediction || '—') + '", their data ' + esc(answers._datashape || '') + '.' + rethinkHTML(answers) +
            '</div>' : '';
        var BY = {engine: 'Comparison', ondevice: 'On-device AI', ai: 'AI · 1 credit', teacher: 'You'};
        var qHTML = mk && !mk.guide.basic ? '<h4 class="aisx-h4">Answers, marked one by one' + tip('Each answer has a ' +
            'suggested mark out of 4 with the reason. Click a number to change it. The Claim, Evidence and Reasoning scores ' +
            'below are the averages of the answers in each part.') + '</h4><ol class="aisx-qlist">' +
                mk.guide.questions.map(function(q) {
                var m = mk.q[q.k];
                var ansText = String(answers[q.k] || '').trim();
                return '<li class="aisx-qi ' + (m.conf === 'low' && !m.empty ? 'unsure' : '') +
                    '"><div class="aisx-qh"><span class="aisx-qpart ' +
                    q.part + '">' + esc(Marker.PART[q.part]) + '</span><b>' + esc(q.q) + '</b></div><p class="aisx-qa">' +
                    (ansText ? esc(ansText) : '<i class="aisx-muted">No answer</i>') + '</p><div class="aisx-qm" role="group" ' +
                    'aria-label="Mark for this answer">' + [0, 1, 2, 3, 4].map(function(v) {
                        return '<button type="button" data-qk="' + esc(q.k) + '" data-qv="' + v + '" aria-pressed="' +
                            (m.mark === v) +
                            '">' + v + '</button>';
                    }).join('') + '<span class="aisx-qby">' + esc(BY[m.by] || m.by) + (m.conf === 'low' && !m.empty ?
                    ' · <b>unsure</b>' : '') + '</span>' + (m.conf === 'low' && !m.empty && S.results.aimark &&
                    a.status !== 'graded' ? '<button type="button" class="aisx-link" data-qai="' + esc(q.k) + '">Mark with AI ' +
                    '(1 credit)</button>' : '') + '</div><p class="aisx-qwhy">' + esc(m.why) + '</p><details class="aisx-model">' +
                    '<summary>Model answer</summary>' + esc(q.model) + '</details></li>';
            }).join('') + '</ol><details class="aisx-model"><summary>Whole report as the student sent it</summary>' +
            '<div class="aisx-quote">' + esc(a.report || 'No report yet.') + '</div></details>' :
            '<div class="aisx-quote">' + esc(a.report || 'No report yet.') + '</div>';
        g.innerHTML = '<div class="aisx-grader"><h3>' + esc(a.fullname) + '</h3>' + (pc || '<p class="aisx-muted aisx-small">' +
            'Predicted: ' + esc(a.prediction || '—') + '</p>') + checkLine(answers) + qHTML +
            (ftable ? '<h4 class="aisx-h4">Findings table</h4>' + ftable : '') +
            '<h4 class="aisx-h4">Lab notebook · ' + a.notes.length + '</h4>' + (a.notes.length ? '<ol class="aisx-nblist ' +
            'aisx-short">' + a.notes.map(noteItem).join('') + '</ol>' : '<p class="aisx-muted aisx-small">No notes.</p>') +
            '<div id="aisx-sug"></div>' + (r.aisuggest && a.report ? '<button type="button" class="aisx-btn aisx-sm" ' +
            'id="aisx-suggest">Suggest scores with AI</button>' : '') + '<div class="aisx-rub" role="group" aria-label="CER ' +
            'rubric">' + crit.map(function(c) {
                return '<span class="h">' + c[1] + '</span>' + [0, 1, 2, 3, 4].map(function(v) {
                    return '<button type="button" data-k="' + c[0] + '" data-v="' + v + '" aria-pressed="' + (a._rub[c[0]] === v) +
                        '" aria-label="' + c[1] + ' ' + v + ': ' + esc(RUBRIC[c[0]][v]) + '" title="' + esc(RUBRIC[c[0]][v]) +
                            '">' +
                        v + '</button>';
                }).join('') + '<span class="aisx-rubd" id="aisx-rubd-' + c[0] + '">' + (a._rub[c[0]] >= 0 ?
                    esc(RUBRIC[c[0]][a._rub[c[0]]]) : 'Pick a level.') + '</span>';
            }).join('') + '</div><label for="aisx-fb" class="aisx-small"><b>Feedback to the student</b></label>' +
            '<textarea id="aisx-fb" rows="3">' + esc(a.feedback || (mk && a.status !== 'graded' ? mk.feedback : '')) +
                '</textarea><div class="aisx-row aisx-mt">' +
            (C.cangrade ? '<button type="button" class="aisx-btn aisx-primary" id="aisx-savegrade">Save grade</button>' : '') +
            '<span class="aisx-note" id="aisx-gnote">' + (a.grade >= 0 ? 'Saved: ' + a.grade + ' / ' + r.maxgrade : '') +
            '</span></div></div>';
        $$('.aisx-rub button', g).forEach(function(b) {
            b.addEventListener('click', function() {
                a._rub[b.dataset.k] = parseInt(b.dataset.v, 10);
                $('#aisx-rubd-' + b.dataset.k).textContent = RUBRIC[b.dataset.k][a._rub[b.dataset.k]];
                $$('.aisx-rub button[data-k="' + b.dataset.k + '"]', g).forEach(function(x) {
                    x.setAttribute('aria-pressed', x === b);
                });
            });
        });
        $$('[data-qk]', g).forEach(function(b) {
            b.addEventListener('click', function() {
                var m = mk.q[b.dataset.qk];
                mk.q[b.dataset.qk] = Object.assign({}, m, {mark: parseInt(b.dataset.qv, 10), by: 'teacher', conf: 'high'});
                refreshMark(mk);
                a._rub = Object.assign({}, mk.rubric);
                renderGrader();
                refreshBar();
            });
        });
        $$('[data-qai]', g).forEach(function(b) {
            b.addEventListener('click', function() {
                b.disabled = true;
                b.textContent = 'Marking…';
                call('aimark', {cmid: C.cmid, items: JSON.stringify(withHints([{attemptid: a.attemptid, k: b.dataset.qai}]))})
                    .then(function(res) {
                    S.results.credits = res.credits;
                    if (res.results[0] && res.results[0].error) {
                        toast(res.results[0].error);
                    }
                    applyAI(res.results, 'ai');
                    S.results.creditsused += res.results.filter(function(x) {
                        return x.charged;
                    }).length;
                    a._rub = Object.assign({}, mk.rubric);
                    renderGrader();
                    refreshBar();
                    return null;
                }).catch(Notification.exception);
            });
        });
        var sug = $('#aisx-suggest');
        if (sug) {
            sug.addEventListener('click', function() {
                sug.disabled = true;
                $('#aisx-sug').innerHTML = '<div class="aisx-status"><span class="aisx-spin"></span>Reading the report…</div>';
                call('suggest', {cmid: C.cmid, attemptid: a.attemptid}).then(function(s) {
                    $('#aisx-sug').innerHTML = '<div class="aisx-aisug"><b>AI suggestion:</b> Claim ' + s.claim + ', Evidence ' +
                        s.evidence + ', Reasoning ' + s.reasoning + '. ' + esc(s.reason) + ' <button type="button" ' +
                        'class="aisx-link" id="aisx-usesug">Use these</button></div>';
                    $('#aisx-usesug').addEventListener('click', function() {
                        a._rub = {claim: s.claim, evidence: s.evidence, reasoning: s.reasoning};
                        var fb = $('#aisx-fb');
                        if (!fb.value.trim()) {
                            fb.value = s.feedback;
                        }
                        a.feedback = fb.value;
                        renderGrader();
                    });
                    return null;
                }).catch(function(e) {
                    $('#aisx-sug').innerHTML = '';
                    Notification.exception(e);
                });
            });
        }
        var save = $('#aisx-savegrade');
        if (save) {
            save.addEventListener('click', function() {
                var fb = $('#aisx-fb').value;
                var afterGrade = function() {
                    toast('Grade saved to the gradebook');
                    delete S.marks[a.attemptid];
                    loadResults();
                };
                if (mk && !mk.guide.basic) {
                    call('applymarks', {cmid: C.cmid, items: JSON.stringify([{attemptid: a.attemptid, claim: a._rub.claim || 0,
                        evidence: a._rub.evidence || 0, reasoning: a._rub.reasoning || 0, feedback: fb, marks: mk.q}])})
                        .then(afterGrade).catch(Notification.exception);
                } else {
                    call('grade', {cmid: C.cmid, attemptid: a.attemptid, claim: a._rub.claim || 0, evidence: a._rub.evidence || 0,
                        reasoning: a._rub.reasoning || 0, feedback: fb}).then(afterGrade).catch(Notification.exception);
                }
            });
        }
    };
    var loadResults = function() {
        S.results = null;
        renderResults();
        return call('results', {cmid: C.cmid}).then(function(r) {
            S.results = r;
            if (S.selectedId) {
                var i = r.attempts.findIndex(function(a) {
                    return a.attemptid === S.selectedId;
                });
                S.selected = i > -1 ? i : null;
            }
            if (S.tab === 'insights') {
                renderInsights();
            } else {
                renderResults();
            }
            return null;
        }).catch(Notification.exception);
    };

    /* ------------------------------------------------------------------ class insights */
    var median = function(a) {
        var n = a.filter(function(x) {
            return typeof x === 'number' && isFinite(x);
        }).sort(function(x, y) {
            return x - y;
        });
        if (!n.length) {
            return null;
        }
        return n.length % 2 ? n[(n.length - 1) / 2] : (n[n.length / 2 - 1] + n[n.length / 2]) / 2;
    };
    var bar = function(pct, tone) {
        return '<span class="aisx-meter ' + (tone || '') + '"><i style="width:' + Math.max(0, Math.min(100, pct || 0)) +
            '%"></i></span>';
    };
    var renderInsights = function() {
        var pane = $('#aisx-pane-insights');
        var r = S.results;
        if (!r) {
            pane.innerHTML = '<div class="aisx-status"><span class="aisx-spin"></span>Loading class insights…</div>';
            return;
        }
        markAll();
        var all = r.attempts;
        var done = all.filter(function(a) {
            return a.status !== 'inprogress';
        });
        if (!all.length) {
            pane.innerHTML = '<section class="aisx-card"><h3>Class insights</h3><p class="aisx-muted">Insights appear once ' +
                'students ' +
                'start the experiment.</p></section>';
            return;
        }
        var g = Marker.guideFor(r, done[0] || all[0]);
        var rows = done.map(function(a) {
            var m = S.marks[a.attemptid];
            return {a: a, name: a.fullname, marks: m.q, rubric: a.status === 'graded' ? parse(a.rubric, m.rubric) : m.rubric,
                ans: parse(a.answers, {})};
        });
        // KPIs.
        var mins = done.map(function(a) {
            return a.timesubmitted && a.timecreated ? (a.timesubmitted - a.timecreated) / 60 : null;
        });
        var checked = rows.filter(function(x) {
            return x.ans._checkfirst !== undefined;
        });
        var improved = checked.filter(function(x) {
            return x.ans._checksend !== undefined && parseInt(x.ans._checksend, 10) < parseInt(x.ans._checkfirst, 10);
        });
        var kpi = function(v, l, t) {
            return '<div class="aisx-kpi"><div class="v">' + esc(v) + '</div><div class="l">' + esc(l) + (t ? tip(t) : '') +
                '</div></div>';
        };
        var medmin = median(mins);
        var html = '<div class="aisx-kpis">' + kpi(done.length + ' / ' + all.length, 'Reports submitted') +
            kpi(medmin === null ? '—' : Math.round(medmin) +
                ' min', 'Typical time', 'The middle time from opening the experiment ' +
            'to sending the report. Half the class took longer, half took less.') +
            kpi(checked.length ? improved.length + ' / ' + checked.length : '—', 'Improved after feedback', 'Students see a ' +
            '"Before you send it" check on their report. This counts how many fixed at least one thing it pointed out.') +
            kpi(all.length ? (all.reduce(function(t, a) {
                return t + a.notes.length;
            }, 0) / all.length).toFixed(1) : '—', 'Notes per student', 'Lab notebook entries: typed, spoken, voice or photo.') +
            '</div>';
        // Questions.
        var items = done.length ? Marker.itemAnalysis(g, rows) : [];
        html += '<section class="aisx-card aisx-ins"><p class="aisx-eyebrow">Where students go wrong</p><h3>Question by ' +
            'question</h3>' +
            '<p class="aisx-muted aisx-small">Marks come from auto-marking, or your own marks where you have graded. ' +
            'Each question gets a plain-English verdict: is the wording the problem, or does the idea need more teaching?</p>' +
            (items.length ? '<div class="aisx-tblwrap"><table class="aisx-tbl aisx-itbl"><thead><tr><th>Question</th><th>Average' +
            tip('The average mark on this question, as a percentage. This is what testing experts call the "facility" or ' +
            'difficulty. Between 30% and 90% is healthy. Below 30% almost everyone struggled; above 90% almost everyone got it.') +
            '</th><th>Tells who understands?' + tip('This is the "discrimination index". It asks: did the students who did well ' +
            'on the rest of the report also do well here? Above 0.3 = yes, the question picks out students who understand. ' +
            'Near 0 = it doesn\'t tell you much. Below 0 = students who understood everything else did worse here, which ' +
            'usually means the question or its marking is misleading.') + '</th><th>Verdict' + tip('"Reword the question" ' +
            'when nearly everyone struggled, even strong students. "Teach it again" when strong students got it but many did ' +
            'not. "Check the marking" when strong students did worse than weaker ones. "Working well", "Fine" or "Easy" ' +
            'otherwise.') + '</th></tr></thead><tbody>' + items.map(function(it) {
                var d = it.disc === null ? '—' : it.disc.toFixed(2);
                var dtone = it.disc === null ? '' : toneOf(it.disc, 0, 0.2);
                return '<tr><td><span class="aisx-qpart ' + it.part + '">' + esc(it.partLabel) + '</span> ' + esc(it.q) +
                    '</td><td class="aisx-nw">' + (it.facility === null ? '—' : it.facility + '% ' + bar(it.facility,
                    toneOf(it.facility, 30, 50))) +
                        '</td><td class="aisx-nw"><span class="aisx-tag ' +
                    dtone + '">' + d + '</span>' + (it.top !== null ? '<small class="aisx-muted"> strong ' + it.top + '% · ' +
                    'others ' + it.bottom + '%</small>' : '') + '</td><td><span class="aisx-verdict ' + it.tone + '">' +
                        esc(it.verdict) +
                    '</span></td></tr><tr class="aisx-adv"><td colspan="4"><p>' + esc(it.advice) + '</p>' + (it.weak.length ?
                    '<details><summary>' + it.weak.length + (it.weak.length === 1 ? ' student' : ' students') + ' scored 2 or ' +
                    'less</summary><ul>' + it.weak.slice(0, 12).map(function(w) {
                        return '<li><b>' + esc(w.name) + ':</b> ' + esc(String(w.ans[it.k] || '(blank)').slice(0, 160)) + '</li>';
                    }).join('') + '</ul></details>' : '') + '</td></tr>';
            }).join('') + '</tbody></table></div>' : '<p class="aisx-muted">No reports yet.</p>') + '</section>';
        // Claim, Evidence, Reasoning.
        var parts = ['claim', 'evidence', 'reasoning'].map(function(p) {
            var vals = rows.map(function(x) {
                return x.rubric[p] || 0;
            });
            var avg = vals.length ? vals.reduce(function(a, b) {
                return a + b;
            }, 0) / vals.length : 0;
            return {p: p, avg: avg, low: rows.filter(function(x) {
                return (x.rubric[p] || 0) <= 1;
            })};
        });
        var weakest = parts.slice().sort(function(a, b) {
            return a.avg - b.avg;
        })[0];
        var advice = {claim: 'Model a one-sentence claim that names both variables: "As ' + (g.iv || 'the thing we changed') +
            ' increases, ' + (g.dv || 'what we measured') + '…".', evidence: 'Show students how to lift two numbers from their ' +
            'findings table into a sentence, with units.', reasoning: 'Practise "This happens because…" sentences that use ' +
            (g.idea || 'the science idea') + '.'};
        html += '<section class="aisx-card aisx-ins"><p class="aisx-eyebrow">Scientific explanation</p><h3>Claim, Evidence, ' +
            'Reasoning' + tip('The three parts of a scientific explanation. Claim: what they found. Evidence: the numbers that ' +
            'show it. Reasoning: the science idea that explains why. Each is scored 0 to 4.') + '</h3><div class="aisx-cer">' +
            parts.map(function(x) {
                return '<div><b>' + esc(Marker.PART[x.p]) + '</b><span class="aisx-big">' + x.avg.toFixed(1) +
                    '<small> / 4</small></span>' +
                    bar(x.avg / 4 * 100, toneOf(x.avg, 2, 3)) + '<small class="aisx-muted">' +
                    x.low.length + ' scored 0–1</small></div>';
            }).join('') + '</div>' + (rows.length ? '<p class="aisx-small"><b>Biggest gap: ' + esc(Marker.PART[weakest.p]) +
            '.</b> ' + esc(advice[weakest.p]) + '</p>' : '') + '</section>';
        // Misconceptions.
        var wrongs = {};
        rows.forEach(function(x) {
            Object.keys(x.marks).forEach(function(k) {
                var w = x.marks[k].wrong;
                if (w) {
                    wrongs[w] = wrongs[w] || [];
                    if (wrongs[w].indexOf(x.name) < 0) {
                        wrongs[w].push(x.name);
                    }
                }
            });
        });
        var stuck = rows.filter(function(x) {
            return x.ans._predmatch === 'no' && String(x.ans.predrethink || '').trim().length <= 10;
        });
        html += '<section class="aisx-card aisx-ins"><p class="aisx-eyebrow">Misconceptions</p><h3>Ideas to tackle next ' +
            'lesson</h3>' + (Object.keys(wrongs).length ? '<ul class="aisx-mis">' + Object.keys(wrongs).sort(function(a, b) {
                return wrongs[b].length - wrongs[a].length;
            }).map(function(w) {
                return '<li><b>"' + esc(w) + '"</b> in ' + wrongs[w].length + (wrongs[w].length === 1 ? ' report' : ' reports') +
                    ': <span class="aisx-names">' + esc(wrongs[w].join(', ')) + '</span></li>';
            }).join('') + '</ul>' : '<p class="aisx-muted aisx-small">No common misconceptions found in the reports.</p>') +
            '<p class="aisx-small"><b>' + stuck.length + '</b> ' + (stuck.length === 1 ? 'student\'s' : 'students\'') +
            ' prediction wasn\'t supported by their data and they haven\'t explained what changed their mind' +
            (stuck.length ? ': <span class="aisx-names">' + esc(stuck.map(function(x) {
                return x.name;
            }).join(', ')) + '</span>' : '') + '.</p></section>';
        // Progress.
        var stages = [1, 2, 3, 4, 5, 6].map(function(n) {
            return all.filter(function(a) {
                return a.stage >= n;
            }).length;
        });
        var names = ['Get started', 'Predict', 'Set up', 'Collect', 'Analyze', 'Report'];
        var working = all.filter(function(a) {
            return a.status === 'inprogress';
        });
        html += '<section class="aisx-card aisx-ins"><p class="aisx-eyebrow">Progress</p><h3>How far students got' +
            tip('How many students reached each step. A big drop between two steps shows where students get stuck.') + '</h3>' +
            '<div class="aisx-funnel">' + stages.map(function(n, i) {
                return '<div><span>' + (i + 1) + '. ' + esc(names[i]) + '</span>' + bar(n / all.length * 100, 'ok') + '<b>' + n +
                    '</b></div>';
            }).join('') + '<div><span>Report sent</span>' + bar(done.length / all.length * 100, 'ok') + '<b>' + done.length +
                '</b></div>' +
            '</div>' + (working.length ? '<p class="aisx-small"><b>Still working:</b> ' + working.map(function(a) {
                return esc(a.fullname) + ' (' + esc(a.stagename || 'step ' + a.stage) + ', active ' +
                    new Date(a.timemodified * 1000).toLocaleString([], {dateStyle: 'short', timeStyle: 'short'}) + ')';
            }).join('; ') + '</p>' : '') + '</section>';
        // Data quality.
        var keys = {};
        done.forEach(function(a) {
            var ph = parse(a.phases, {});
            Object.keys(ph).forEach(function(k) {
                if (isFinite(Number(ph[k]))) {
                    (keys[k] = keys[k] || []).push({name: a.fullname, v: Number(ph[k])});
                }
            });
        });
        var odd = [];
        Object.keys(keys).forEach(function(k) {
            var med = median(keys[k].map(function(x) {
                return x.v;
            }));
            keys[k].forEach(function(x) {
                if (med && Math.abs(x.v - med) / Math.abs(med) > 0.3) {
                    odd.push(x.name + ' (' + k + ': ' + x.v.toLocaleString() + ' vs class ' + med.toLocaleString() + ')');
                }
            });
        });
        html += '<section class="aisx-card aisx-ins"><p class="aisx-eyebrow">Data quality</p><h3>Readings far from the class' +
            tip('Readings more than 30% away from the class middle value for the same measurement. They may show a ' +
            'measuring problem, or something worth discussing.') + '</h3>' + (odd.length ? '<ul class="aisx-mis">' +
            odd.slice(0, 20).map(function(o) {
                return '<li>' + esc(o) + '</li>';
            }).join('') + '</ul>' : '<p class="aisx-muted aisx-small">No unusual readings.</p>') + '</section>';
        html += '<div class="aisx-row"><button type="button" class="aisx-btn" id="aisx-csv">Download as a spreadsheet ' +
            '(CSV)</button></div>';
        pane.innerHTML = html;
        $('#aisx-csv').addEventListener('click', function() {
            var qk = g.questions.map(function(q) {
                return q.k;
            });
            var head = ['Student', 'Status', 'Step reached', 'Minutes', 'Prediction', 'Prediction supported', 'Changed mind',
                'Readings', 'Notes', 'Report tips at first', 'Report tips when sent', 'Claim', 'Evidence', 'Reasoning', 'Grade']
                .concat(g.questions.map(function(q) {
                    return 'Q ' + q.k + ' (/4)';
                }));
            var lines = [head].concat(all.map(function(a) {
                var ans = parse(a.answers, {});
                var m = S.marks[a.attemptid];
                var rub = {};
                if (a.status === 'graded') {
                    rub = parse(a.rubric, {});
                } else if (m) {
                    rub = m.rubric;
                }
                return [a.fullname, a.status, a.stage, a.timesubmitted ? Math.round((a.timesubmitted - a.timecreated) / 60) : '',
                    a.prediction, ans._predmatch || '', String(ans.predrethink || '').trim() ? 'yes' : '', a.readings,
                    a.notes.length, ans._checkfirst || '', ans._checksend || '', rub.claim, rub.evidence, rub.reasoning,
                    a.grade >= 0 ? a.grade : ''].concat(qk.map(function(k) {
                        return m && m.q[k] ? m.q[k].mark : '';
                    }));
            })).map(function(row) {
                return row.map(function(c) {
                    c = c === undefined || c === null ? '' : String(c);
                    if (/^[=+\-@]/.test(c)) {
                        c = "'" + c;
                    }
                    return /[",\n]/.test(c) ? '"' + c.replace(/"/g, '""') + '"' : c;
                }).join(',');
            }).join('\r\n');
            var blob = new Blob(['\ufeff' + lines], {type: 'text/csv'});
            var link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = (C.name || 'experiment').replace(/[^\w-]+/g, '-') + '-class-insights.csv';
            document.body.appendChild(link);
            link.click();
            setTimeout(function() {
                URL.revokeObjectURL(link.href);
                link.remove();
            }, 1000);
        });
    };

    /* ------------------------------------------------------------------ edit & refine */
    var CHIPS = ['Simplify the language for younger readers', 'Add a stretch question for fast finishers',
        'Add a hint under every question', 'Add a key-vocabulary card at the start', 'Shorten it to fit a 30-minute lesson',
        'Make the autoplay slower so students can take notes'];
    var R = {base: 0, data: null, mode: 'words', texts: {}, numbers: {}, title: null, code: null, filter: '', only: false,
        step: 'all'};
    var refineCount = function() {
        var n = Object.keys(R.texts).length + Object.keys(R.numbers).length + (R.title !== null ? 1 : 0);
        return R.code !== null ? n + 1 : n;
    };
    var loadContent = function(vid) {
        var pane = $('#aisx-pane-refine');
        pane.innerHTML = '<div class="aisx-status"><span class="aisx-spin"></span>Reading the experiment…</div>';
        R.texts = {};
        R.numbers = {};
        R.title = null;
        R.code = null;
        return Promise.all([call('content', {cmid: C.cmid, versionid: vid || 0}), call('status', {cmid: C.cmid})])
            .then(function(res) {
                R.data = res[0];
                R.base = res[0].versionid;
                S.versions = res[1].versions;
                S.available = res[1].available;
                S.prices = parse(res[1].prices, {});
                S.balance = res[1].credits;
                renderRefine();
                return null;
            }).catch(function(e) {
                pane.innerHTML = '<div class="aisx-hero"><h3>Nothing to edit yet</h3><p>Build or pick an experiment first. ' +
                    'Once a version is ready you can reword it, change the sliders, ask AI to refine it, or edit the code.</p>' +
                    '<button type="button" class="aisx-btn aisx-primary" data-go="build">Go to Build</button></div>';
                var b = $('[data-go]', pane);
                if (b) {
                    b.addEventListener('click', function() {
                        tab('build');
                    });
                }
                window.console.warn(e);
            });
    };
    var updateBar = function() {
        var n = refineCount();
        var c = $('#aisx-rf-count');
        if (c) {
            c.textContent = n ? n + (n === 1 ? ' change' : ' changes') + ' not saved' : 'No changes yet';
            $$('#aisx-rf-bar [data-save]').forEach(function(b) {
                b.disabled = !n;
            });
            $('#aisx-rf-bar').classList.toggle('dirty', !!n);
        }
    };
    var wordsHTML = function() {
        var d = R.data;
        var q = R.filter.toLowerCase();
        var rows = d.texts.filter(function(t) {
            var cur = R.texts[t.id] !== undefined ? R.texts[t.id] : t.text;
            return (!q || cur.toLowerCase().indexOf(q) > -1 || t.text.toLowerCase().indexOf(q) > -1) &&
                (!R.only || R.texts[t.id] !== undefined) && (R.step === 'all' || String(t.step) === R.step);
        });
        var shown = rows.slice(0, 120);
        return '<div class="aisx-rf-tools"><label class="aisx-rf-search"><span class="aisx-sr">Find wording</span>' +
            '<input type="search" id="aisx-rf-q" placeholder="Find a word or sentence…" value="' + esc(R.filter) + '"></label>' +
            '<label class="aisx-rf-stepf">Step <select id="aisx-rf-step"><option value="all">All steps</option>' +
            stepOptions(d) + '</select></label><label class="aisx-rf-only"><input type="checkbox" id="aisx-rf-only"' +
            (R.only ? ' checked' : '') + '> Only my changes</label></div>' +
            '<div class="aisx-rf-field"><label for="aisx-rf-title">Title students see</label><input id="aisx-rf-title" ' +
            'type="text" maxlength="120" value="' + esc(R.title !== null ? R.title : d.title) + '"></div>' +
            '<p class="aisx-muted aisx-small">' + rows.length + ' of ' + d.texts.length + ' phrases' +
            (rows.length > shown.length ? ' · showing the first ' + shown.length + ', search to find others' : '') + '</p>' +
            '<ol class="aisx-rf-list">' + shown.map(function(t) {
                var changed = R.texts[t.id] !== undefined;
                var cur = changed ? R.texts[t.id] : t.text;
                return '<li class="' + (changed ? 'changed' : '') + '"><span class="aisx-rf-steplab">' + esc(t.stepname) +
                    '</span><textarea data-tid="' + t.id + '" rows="' +
                    Math.min(6, Math.max(1, Math.ceil(cur.length / 70))) + '" aria-label="Phrase">' + esc(cur) +
                    '</textarea>' + (changed ? '<div class="aisx-rf-was"><span>Was:</span> ' + esc(t.text) +
                    ' <button type="button" class="aisx-link" data-undo="' + t.id + '">Undo</button></div>' : '') + '</li>';
            }).join('') + '</ol>' + (!rows.length ? '<p class="aisx-muted">No phrases match.</p>' : '');
    };
    var stepOptions = function(d) {
        var seen = {};
        d.texts.forEach(function(t) {
            seen[t.step] = t.stepname;
        });
        return Object.keys(seen).sort(function(a, b) {
            return (a === '0' ? 99 : +a) - (b === '0' ? 99 : +b);
        }).map(function(k) {
            return '<option value="' + k + '"' + (R.step === k ? ' selected' : '') + '>' + esc(seen[k]) + '</option>';
        }).join('');
    };
    var slidersHTML = function() {
        var d = R.data;
        if (!d.numbers.length) {
            return '<p class="aisx-muted">This experiment sets its sliders in a way that can\'t be edited here. Ask AI to ' +
                'change them, or edit the code.</p>';
        }
        var f = [['min', 'Lowest'], ['max', 'Highest'], ['step', 'Step'], ['start', 'Starts at']];
        return '<p class="aisx-muted aisx-small">Change what students can set. Narrow the range for younger classes, or widen ' +
            'it for an extension.</p><div class="aisx-rf-sliders">' + d.numbers.map(function(n) {
                var cur = R.numbers[n.id] || {};
                return '<div class="aisx-rf-slider' + (R.numbers[n.id] ? ' changed' : '') + '"><h4>' + esc(n.label) +
                    (n.unit ? ' <span class="aisx-muted">(' + esc(n.unit) + ')</span>' : '') + '</h4><div class="aisx-rf-nums">' +
                    f.filter(function(x) {
                        return n[x[0]] !== '';
                    }).map(function(x) {
                        var v = cur[x[0]] !== undefined ? cur[x[0]] : n[x[0]];
                        return '<label>' + x[1] + '<input type="number" step="any" inputmode="decimal" data-nid="' + n.id +
                            '" data-k="' + x[0] + '" value="' + esc(v) + '"></label>';
                    }).join('') + '</div></div>';
            }).join('') + '</div>';
    };
    var aiHTML = function() {
        if (!S.available) {
            return '<p class="aisx-err">' + NOAI + ' to refine with AI. You can still reword it, change the sliders ' +
                'or edit the code.</p>';
        }
        return '<p class="aisx-muted aisx-small">Describe the change. AI rebuilds version ' + R.data.versionnum + ' with it, the ' +
            'safety and learning checks run again, and you review it before students see it.</p><div class="aisx-chips">' +
            CHIPS.map(function(c) {
                return '<button type="button" class="aisx-chip" data-chip="' + esc(c) + '">' + esc(c) + '</button>';
            }).join('') + '</div><label for="aisx-rf-ai" class="aisx-sr">Change to make</label><textarea id="aisx-rf-ai" ' +
            'rows="3" placeholder="For example: Use a torch instead of a lamp, and add a question about shadows."></textarea>' +
            '<div class="aisx-row aisx-mt"><button type="button" class="aisx-btn aisx-sun" id="aisx-rf-aigo">Refine with AI' +
            cost('refine') + '</button></div>';
    };
    var codeHTML = function() {
        var v = R.code !== null ? R.code : R.data.html;
        return '<p class="aisx-muted aisx-small">For confident editors. The page runs in a locked sandbox, and every save runs ' +
            'the same safety and learning checks as AI builds. You can always go back to an earlier version.</p>' +
            '<label for="aisx-rf-code" class="aisx-sr">Experiment code</label><textarea id="aisx-rf-code" class="aisx-code" ' +
            'spellcheck="false" rows="22">' + esc(v) + '</textarea><p class="aisx-muted aisx-small">' +
            Math.round(v.length / 1024) + ' KB · saving code replaces any wording or slider changes not yet saved.</p>';
    };
    var renderRefine = function() {
        var pane = $('#aisx-pane-refine');
        var d = R.data;
        var base = S.versions.find(function(v) {
            return v.id === R.base;
        }) || {};
        var options = S.versions.filter(function(v) {
            return ['ready', 'published', 'retired', 'failed'].indexOf(v.status) > -1 || v.current;
        }).map(function(v) {
            return '<option value="' + v.id + '"' + (v.id === R.base ? ' selected' : '') + '>Version ' + v.versionnum + ' · ' +
                esc(parse(v.plan, {}).title || C.name) +
                (v.current ? ' (live)' : pick(v.status, {failed: ' (checks failed)', ready: ' (draft)'}, '')) + '</option>';
        }).join('');
        var modes = [['words', 'Words'], ['sliders', 'Sliders'], ['ai', 'Ask AI'], ['code', 'Code']];
        var modeCount = function(mode, data) {
            var n = pick(mode, {words: data.texts.length, sliders: data.numbers.length}, null);
            return n === null ? '' : ' <span class="aisx-n">' + n + '</span>';
        };
        pane.innerHTML = '<section class="aisx-card aisx-rf"><div class="aisx-rf-head"><div><p class="aisx-eyebrow">Edit &amp; ' +
            'refine</p><h3>' + esc(d.title) + '</h3><p class="aisx-muted aisx-small">Changes are saved as a new version. ' +
            'Students keep seeing the live version until you publish.</p></div><label class="aisx-rf-from">Start from' +
            '<select id="aisx-rf-from">' + options + '</select></label></div>' + (d.inprogress ? '<p class="aisx-note">' +
            d.inprogress + (d.inprogress === 1 ? ' student is' : ' students are') + ' part way through. They keep their ' +
            'answers and notes when you publish a new version.</p>' : '') + (base.instruction ? '<p class="aisx-muted ' +
            'aisx-small">This version: ' + esc(base.instruction) + '</p>' : '') +
            '<div class="aisx-seg" role="tablist" aria-label="How to edit">' + modes.map(function(m) {
                return '<button type="button" role="tab" data-mode="' + m[0] + '" aria-selected="' + (R.mode === m[0]) + '">' +
                    m[1] + modeCount(m[0], d) + '</button>';
            }).join('') + '</div>' +
            '<div class="aisx-rf-bar" id="aisx-rf-bar"><span id="aisx-rf-count"></span><span class="aisx-grow"></span>' +
            '<button type="button" class="aisx-btn aisx-sm" id="aisx-rf-discard">Discard</button>' +
            '<button type="button" class="aisx-btn" data-save="draft">Save as draft</button>' + (C.canpublish ?
            '<button type="button" class="aisx-btn aisx-ok" data-save="publish">Save &amp; publish</button>' : '') + '</div>' +
            '<div id="aisx-rf-result" aria-live="polite"></div><div id="aisx-rf-body"></div></section>';
        $('#aisx-rf-from').addEventListener('change', function(e) {
            if (refineCount()) {
                e.target.value = String(R.base);
                toast('Save or discard your changes first.');
                return;
            }
            loadContent(parseInt(e.target.value, 10));
        });
        $$('[data-mode]', pane).forEach(function(b) {
            b.addEventListener('click', function() {
                R.mode = b.dataset.mode;
                $$('[data-mode]', pane).forEach(function(x) {
                    x.setAttribute('aria-selected', x === b);
                });
                renderRefineBody();
            });
        });
        $('#aisx-rf-discard').addEventListener('click', function() {
            loadContent(R.base);
        });
        $$('[data-save]', pane).forEach(function(b) {
            b.addEventListener('click', function() {
                saveRefine(b.dataset.save === 'publish', b);
            });
        });
        renderRefineBody();
    };
    var renderRefineBody = function() {
        var body = $('#aisx-rf-body');
        body.innerHTML = pick(R.mode, {words: wordsHTML, sliders: slidersHTML, ai: aiHTML}, codeHTML)();
        $('#aisx-rf-bar').hidden = R.mode === 'ai';
        updateBar();
        if (R.mode === 'words') {
            var q = $('#aisx-rf-q');
            q.addEventListener('input', function() {
                R.filter = q.value;
                var pos = q.selectionStart;
                renderRefineBody();
                var q2 = $('#aisx-rf-q');
                q2.focus();
                q2.setSelectionRange(pos, pos);
            });
            $('#aisx-rf-step').addEventListener('change', function(e) {
                R.step = e.target.value;
                renderRefineBody();
            });
            $('#aisx-rf-only').addEventListener('change', function(e) {
                R.only = e.target.checked;
                renderRefineBody();
            });
            $('#aisx-rf-title').addEventListener('input', function(e) {
                R.title = e.target.value === R.data.title ? null : e.target.value;
                updateBar();
            });
            $$('[data-tid]', body).forEach(function(ta) {
                ta.addEventListener('input', function() {
                    var orig = R.data.texts.find(function(t) {
                        return t.id === ta.dataset.tid;
                    });
                    if (ta.value === orig.text) {
                        delete R.texts[ta.dataset.tid];
                    } else {
                        R.texts[ta.dataset.tid] = ta.value;
                    }
                    ta.parentNode.classList.toggle('changed', R.texts[ta.dataset.tid] !== undefined);
                    updateBar();
                });
            });
            $$('[data-undo]', body).forEach(function(b) {
                b.addEventListener('click', function() {
                    delete R.texts[b.dataset.undo];
                    renderRefineBody();
                });
            });
        } else if (R.mode === 'sliders') {
            $$('[data-nid]', body).forEach(function(inp) {
                inp.addEventListener('input', function() {
                    var n = R.data.numbers.find(function(x) {
                        return x.id === inp.dataset.nid;
                    });
                    var cur = R.numbers[n.id] || {};
                    if (String(inp.value) === String(n[inp.dataset.k])) {
                        delete cur[inp.dataset.k];
                    } else {
                        cur[inp.dataset.k] = inp.value;
                    }
                    if (Object.keys(cur).length) {
                        R.numbers[n.id] = cur;
                    } else {
                        delete R.numbers[n.id];
                    }
                    inp.closest('.aisx-rf-slider').classList.toggle('changed', !!R.numbers[n.id]);
                    updateBar();
                });
            });
        } else if (R.mode === 'ai') {
            var ta = $('#aisx-rf-ai');
            if (!ta) {
                return;
            }
            $$('[data-chip]', body).forEach(function(c) {
                c.addEventListener('click', function() {
                    ta.value = (ta.value.trim() ? ta.value.trim() + ' ' : '') + c.dataset.chip + '.';
                    c.classList.add('on');
                    ta.focus();
                });
            });
            $('#aisx-rf-aigo').addEventListener('click', function(e) {
                var t = ta.value.trim();
                if (!t) {
                    ta.focus();
                    return;
                }
                e.target.disabled = true;
                call('change', {cmid: C.cmid, instruction: t, basedon: R.base}).then(function() {
                    toast('Refining with AI. You\'ll review it in Build before students see it.');
                    tab('build');
                    return null;
                }).catch(function(err) {
                    e.target.disabled = false;
                    Notification.exception(err);
                });
            });
        } else {
            var code = $('#aisx-rf-code');
            code.addEventListener('input', function() {
                R.code = code.value === R.data.html ? null : code.value;
                updateBar();
            });
            code.addEventListener('keydown', function(e) {
                if (e.key === 'Tab') {
                    e.preventDefault();
                    var a = code.selectionStart;
                    code.setRangeText('  ', a, code.selectionEnd, 'end');
                    code.dispatchEvent(new Event('input'));
                }
            });
        }
    };
    var saveText = function(status, ok, published, draft, failing) {
        if (status === 'published') {
            return published;
        }
        return ok ? draft : failing;
    };
    /**
     * Publish a version from the "saved" card.
     *
     * @param {Number} versionid
     * @returns {Promise}
     */
    var publishVersion = function(versionid) {
        return call('publish', {cmid: C.cmid, versionid: versionid}).then(function() {
            toast('Published. Students see this version now.');
            C.published = versionid;
            return loadContent(versionid);
        }).catch(Notification.exception);
    };
    /**
     * Show what a save did, with Preview and Publish buttons.
     *
     * @param {Object} r the saveedit result
     */
    var showSaved = function(r) {
        var ok = r.status !== 'failed';
        $('#aisx-rf-result').innerHTML = '<div class="aisx-rf-done ' + (ok ? 'ok' : 'bad') + '"><h4>' +
            saveText(r.status, ok, 'Live to students', 'Draft saved', 'Saved with failing checks') +
            '</h4><p>' + esc(r.summary) + '</p><div class="aisx-row" id="aisx-rf-actions"><button type="button" ' +
            'class="aisx-btn" data-pv="' + r.versionid + '">Preview as a student</button>' +
            (ok && r.status !== 'published' && C.canpublish ? '<button type="button" ' +
            'class="aisx-btn aisx-ok" data-pub="' + r.versionid + '">Publish</button>' : '') + '</div>' +
            checksHTML(parse(r.checks, []).filter(function(c) {
                return !ok || !c.pass;
            })) + '</div>';
        $('[data-pv]').addEventListener('click', function() {
            S.previewVersion = r.versionid;
            tab('preview');
        });
        var pub = $('[data-pub]');
        if (pub) {
            pub.addEventListener('click', function() {
                pub.disabled = true;
                publishVersion(r.versionid);
            });
        }
        // Bring the buttons into view, clear of Moodle's sticky footer.
        $('#aisx-rf-actions').scrollIntoView({behavior: 'smooth', block: 'center'});
    };
    var saveRefine = function(publish, btn) {
        $$('#aisx-rf-bar button').forEach(function(b) {
            b.disabled = true;
        });
        btn.classList.add('aisx-busy');
        var args = {cmid: C.cmid, versionid: R.base, title: R.title !== null ? R.title : '', publish: publish};
        if (R.code !== null) {
            args.html = R.code;
        } else {
            args.texts = JSON.stringify(R.texts);
            args.numbers = JSON.stringify(R.numbers);
        }
        var saved = null;
        call('saveedit', args).then(function(r) {
            saved = r;
            btn.classList.remove('aisx-busy');
            toast(saveText(r.status, r.status !== 'failed', 'Published. Students see the new version now.',
                'Saved as a draft. Preview it, then publish.', 'Saved, but some checks fail.'));
            if (r.status === 'published') {
                C.published = r.versionid;
            }
            return loadContent(r.versionid);
        }).then(function() {
            showSaved(saved);
            return null;
        }).catch(function(e) {
            btn.classList.remove('aisx-busy');
            updateBar();
            Notification.exception(e);
        });
    };

    /* ------------------------------------------------------------------ shell */
    var tab = function(name) {
        S.tab = name;
        $$('.aisx-tabs [role=tab]').forEach(function(t) {
            t.setAttribute('aria-selected', t.dataset.tab === name);
        });
        $$('.aisx-pane').forEach(function(p) {
            p.hidden = p.dataset.pane !== name;
        });
        Voice.stop();
        if (name === 'build') {
            loadStatus();
        } else if (name === 'preview') {
            S.isTeacherPreview = true;
            var pane = $('#aisx-pane-preview');
            pane.innerHTML = playerHTML(true);
            bindPlayer(true);
            S.notes = [];
            loadExperiment(S.previewVersion);
        } else if (name === 'results') {
            loadResults();
        } else if (name === 'insights') {
            S.results = null;
            renderInsights();
            loadResults();
        } else if (name === 'refine') {
            loadContent(S.refineVersion || 0);
            S.refineVersion = 0;
        }
    };
    var shellHTML = function() {
        return '<header class="aisx-head"><div class="aisx-mark"><span class="aisx-logo">' + icon('spark') + '</span><div>' +
            '<p class="aisx-kicker">Teacher studio</p><h2>AI Science Experiment</h2></div></div>' +
            '<div class="aisx-tabs" role="tablist">' + (C.cangenerate ? '<button type="button" role="tab" data-tab="build" ' +
            'aria-selected="true">Build</button><button type="button" role="tab" data-tab="refine" aria-selected="false">' +
            'Edit &amp; refine</button>' : '') + '<button type="button" role="tab" data-tab="preview" aria-selected="false">' +
            'Student preview</button>' +
            (C.canreports ? '<button type="button" role="tab" data-tab="results" aria-selected="false">Results &amp; grading' +
            '</button><button type="button" role="tab" data-tab="insights" aria-selected="false">Class insights</button>' : '') +
                '</div></header>' +
            '<div class="aisx-pane" data-pane="build" id="aisx-pane-build"></div>' +
            '<div class="aisx-pane" data-pane="refine" id="aisx-pane-refine" hidden></div>' +
            '<div class="aisx-pane" data-pane="preview" id="aisx-pane-preview" hidden></div>' +
            '<div class="aisx-pane" data-pane="results" id="aisx-pane-results" hidden></div>' +
            '<div class="aisx-pane" data-pane="insights" id="aisx-pane-insights" hidden></div>';
    };

    return {
        init: function(config) {
            C = config;
            root = document.getElementById('aisx-root');
            if (!root) {
                return;
            }
            window.addEventListener('message', onMessage);
            if ('speechSynthesis' in window) {
                Voice.voice = Voice.pick();
                window.speechSynthesis.addEventListener('voiceschanged', function() {
                    Voice.voice = Voice.pick();
                });
            }
            if (C.role === 'teacher') {
                Marker.onDeviceReady().then(function(ok) {
                    S.ondevice = ok;
                    return ok;
                }).catch(function() {
                    S.ondevice = false;
                    return false;
                });
                document.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape' && document.activeElement && document.activeElement.classList.contains('aisx-i')) {
                        document.activeElement.blur();
                    }
                });
                root.innerHTML = shellHTML();
                $$('.aisx-tabs [role=tab]').forEach(function(t) {
                    t.addEventListener('click', function() {
                        tab(t.dataset.tab);
                    });
                });
                var h = (location.hash || '').slice(1);
                var allowed = C.cangenerate ? ['build', 'refine', 'preview', 'results', 'insights'] : ['preview', 'results',
                    'insights'];
                var first = 'preview';
                if (C.cangenerate) {
                    first = 'build';
                } else if (C.canreports) {
                    first = 'results';
                }
                tab(allowed.indexOf(h) > -1 ? h : first);
            } else {
                S.isTeacherPreview = false;
                root.innerHTML = '<div class="aisx-student">' + playerHTML(false) + '</div>';
                bindPlayer(false);
                loadExperiment(0);
                loadNotes();
            }
        }
    };
});
