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

namespace mod_aiscience\local;

/**
 * Prepares experiment HTML for the locked sandbox.
 *
 * The experiment runs in an iframe with sandbox="allow-scripts" (opaque origin, no Moodle cookies)
 * and a Content-Security-Policy that blocks all network access. It talks to Moodle only through
 * the LabKit bridge injected here.
 *
 * @package    mod_aiscience
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class player {
    /** @var string Content security policy applied inside every experiment. */
    const CSP = "default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; img-src data: blob:; "
        . "media-src data: blob:; font-src data:; connect-src 'none'; form-action 'none'; base-uri 'none'";

    /**
     * Wrap experiment HTML with the CSP, fonts and LabKit bridge.
     *
     * @param string $html experiment document
     * @return string
     */
    public static function wrap(string $html): string {
        $head = '<meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="' . self::CSP . '">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<style>' . self::font_css() . '[hidden]{display:none!important}</style>'
            . '<script>' . self::labkit_js() . '</script>';
        // Remove any CSP the experiment tries to set itself, and any base tag.
        $html = preg_replace('~<meta[^>]+http-equiv\s*=\s*["\']?content-security-policy[^>]*>~i', '', $html);
        $html = preg_replace('~<base\b[^>]*>~i', '', $html);
        $html = preg_replace('~<meta[^>]+http-equiv\s*=\s*["\']?refresh[^>]*>~i', '', $html);
        if (preg_match('~<head(\s[^>]*)?>~i', $html)) {
            return preg_replace('~<head(\s[^>]*)?>~i', '$0' . $head, $html, 1);
        }
        if (preg_match('~<html(\s[^>]*)?>~i', $html)) {
            return preg_replace('~<html(\s[^>]*)?>~i', '$0<head>' . $head . '</head>', $html, 1);
        }
        return '<!doctype html><html lang="en"><head>' . $head . '<style>body{margin:0}</style></head><body>' . $html
            . '</body></html>';
    }

    /**
     * Bundled fonts as data URIs (the sandbox can't fetch files).
     *
     * @return string
     */
    public static function font_css(): string {
        global $CFG;
        $cache = \cache::make('mod_aiscience', 'fonts');
        if ($css = $cache->get('css')) {
            return $css;
        }
        $faces = [
            ['Archivo', 700, 'archivo-latin-700-normal.woff2'],
            ['Archivo', 800, 'archivo-latin-800-normal.woff2'],
            ['Archivo', 900, 'archivo-latin-900-normal.woff2'],
            ['Atkinson Hyperlegible', 400, 'atkinson-hyperlegible-latin-400-normal.woff2'],
            ['Atkinson Hyperlegible', 700, 'atkinson-hyperlegible-latin-700-normal.woff2'],
            ['JetBrains Mono', 500, 'jetbrains-mono-latin-500-normal.woff2'],
            ['JetBrains Mono', 700, 'jetbrains-mono-latin-700-normal.woff2'],
        ];
        $css = '';
        foreach ($faces as [$family, $weight, $file]) {
            $path = $CFG->dirroot . '/mod/aiscience/fonts/' . $file;
            if (is_readable($path)) {
                $css .= "@font-face{font-family:'$family';font-weight:$weight;font-style:normal;font-display:swap;"
                    . "src:url(data:font/woff2;base64," . base64_encode(file_get_contents($path)) . ") format('woff2')}";
            }
        }
        $cache->set('css', $css);
        return $css;
    }

    /**
     * The LabKit bridge: the only way an experiment can talk to Moodle.
     *
     * @return string
     */
    public static function labkit_js(): string {
        return <<<'JS'
window.LabKit=(function(){var seq=0,pend={},subs={},ctl=[];
function send(t,p){try{parent.postMessage({labkit:1,type:t,payload:p},"*")}catch(e){}}
function call(t,p){return new Promise(function(res,rej){var id=++seq;pend[id]={res:res,rej:rej};
 parent.postMessage({labkit:1,type:t,payload:p,id:id},"*")})}
addEventListener("error",function(e){send("error",{message:String(e.message||"Script error"),line:e.lineno||0})});
addEventListener("message",function(e){if(e.source!==parent)return;var m=e.data;if(!m||!m.labkit)return;
 if(m.reply&&pend[m.reply]){m.error?pend[m.reply].rej(new Error(m.error)):pend[m.reply].res(m.result);delete pend[m.reply];return}
 if(m.type==="reading"&&subs[m.channel])subs[m.channel].v(m.value);
 if(m.type==="lost"&&subs[m.channel]&&subs[m.channel].l)subs[m.channel].l();
 if(m.type==="control")ctl.forEach(function(f){try{f(m.cmd)}catch(x){}})});
addEventListener("load",function(){send("ready",{})});
return{version:"1.0",
 progress:function(p){send("progress",p)},
 time:function(l){send("time",{label:String(l)})},
 onControl:function(f){ctl.push(f)},
 saveResponse:function(k,v){send("response",{k:String(k),v:String(v)})},
 submit:function(p){send("submit",p)},
 speak:function(t){send("speak",{text:String(t)})},
 dictate:function(){return call("dictate",{})},
 sensors:{connect:function(kind,onValue,onLost){return call("connect",{kind:kind})
  .then(function(r){subs[r.channel]={v:onValue,l:onLost};return r.name})}}};})();
JS;
    }
}
