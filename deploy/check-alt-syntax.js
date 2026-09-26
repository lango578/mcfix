#!/usr/bin/env node
/**
 * 模板替代语法检查器。
 *
 * 这个环境里没有 PHP、也没有网络，装不了 PHP，所以 `php -l` 用不了。
 * 与其写一个"看起来全能、实际会误导人"的分析器，这里只做**一件事**：
 *
 *   检查 `<?php if (...): ?> ... <?php endif; ?>` 这类替代语法是否一一配对。
 *
 * 为什么只做这一件：模板里少写一个 endif，浏览器里只表现为"页面后半截消失"，
 * 是最难定位的一类错误；而这个检查的算法极其朴素（数开启、数闭合），
 * 可以人工复核，实测在全部文件上都稳定给出正确答案。
 *
 * **括号平衡、字符串闭合、正则字面量这些，请交给真实 PHP：**
 *   php bin/mcfix.php doctor          # 会加载全部类，语法错误会直接暴露
 *   php -l <文件>                     # 逐文件语法检查
 *
 * 用法： node deploy/check-alt-syntax.js [目录或文件]
 */

'use strict';

const fs = require('fs');
const path = require('path');

const ROOT = process.argv[2] ? path.resolve(process.argv[2]) : path.resolve(__dirname, '..');

function collect(dir, out = []) {
  if (fs.existsSync(dir) && fs.statSync(dir).isFile()) {
    return dir.endsWith('.php') ? [dir] : [];
  }
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    if (['node_modules', '.git', 'storage', 'deploy'].includes(entry.name)) continue;
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) collect(full, out);
    else if (entry.name.endsWith('.php')) out.push(full);
  }
  return out;
}

/**
 * 抹掉注释与字符串内容。
 * 只做替换，不做结构判断；唯一的"语义"判断是正则字面量，条件收得很紧。
 */
function blankNoise(code) {
  const out = code.split('');
  const blank = (from, to) => {
    for (let k = from; k < to && k < out.length; k++) {
      if (out[k] !== '\n') out[k] = ' ';
    }
  };

  let i = 0;
  while (i < code.length) {
    const ch = code[i];
    const nx = code[i + 1];

    if (ch === '/' && nx === '/') {
      const s = i;
      while (i < code.length && code[i] !== '\n') i++;
      blank(s, i);
      continue;
    }
    if (ch === '#' && nx !== '[') {
      const s = i;
      while (i < code.length && code[i] !== '\n') i++;
      blank(s, i);
      continue;
    }
    if (ch === '/' && nx === '*') {
      const s = i;
      while (i < code.length && !(code[i] === '*' && code[i + 1] === '/')) i++;
      i = Math.min(i + 2, code.length);
      blank(s, i);
      continue;
    }
    if (ch === '<' && code.startsWith('<<<', i)) {
      const m = /^<<<[ \t]*('?)([A-Za-z_][A-Za-z0-9_]*)\1[ \t]*\r?\n/.exec(code.slice(i));
      if (m) {
        const s = i;
        i += m[0].length;
        const re = new RegExp('(^|\\n)([ \\t]*)' + m[2] + '(?=[;,)\\]\\s]|$)', 'm');
        const hit = re.exec(code.slice(i));
        i = hit ? i + hit.index + hit[0].length : code.length;
        blank(s, i);
        continue;
      }
    }
    // 正则字面量：只在 = ( , [ : => return case 之后才认
    if (ch === '/') {
      let back = i - 1;
      while (back >= 0 && (code[back] === ' ' || code[back] === '\t' || code[back] === '\n')) back--;
      const prev = back >= 0 ? code[back] : '';
      const ctx = code.slice(Math.max(0, back - 9), back + 1);
      if (prev === '=' || prev === '(' || prev === ',' || prev === '[' || prev === ':'
        || /(?:=>|return|case)\s*$/.test(ctx)) {
        let pos = i + 1;
        let closed = false;
        while (pos < code.length && code[pos] !== '\n') {
          if (code[pos] === '\\') { pos += 2; continue; }
          if (code[pos] === '/') { closed = true; break; }
          pos++;
        }
        if (closed) {
          let end = pos + 1;
          while (end < code.length && /[imsuxADSU]/.test(code[end])) end++;
          blank(i, end);
          i = end;
          continue;
        }
      }
    }
    // 字符串
    if (ch === "'" || ch === '"') {
      const s = i;
      const quote = ch;
      i++;
      while (i < code.length) {
        if (code[i] === '\\') { i += 2; continue; }
        if (code[i] === quote) { i++; break; }
        i++;
      }
      blank(s, i);
      continue;
    }

    i++;
  }

  return out.join('');
}

const files = collect(ROOT).sort();
let failed = 0;
const report = [];

for (const file of files) {
  const src = fs.readFileSync(file, 'utf8');
  const rel = path.relative(ROOT, file).replace(/\\/g, '/');
  const counts = { if: 0, foreach: 0, for: 0, while: 0, endif: 0, endforeach: 0, endfor: 0, endwhile: 0 };

  const segRe = /<\?(?:php|=)([\s\S]*?)(?:\?>|$)/g;
  let seg;
  while ((seg = segRe.exec(src)) !== null) {
    const code = blankNoise(seg[1]);

    for (const kind of ['if', 'foreach', 'for', 'while']) {
      const closeRe = new RegExp('end' + kind + '\\s*;', 'g');
      counts['end' + kind] += (code.match(closeRe) || []).length;

      const openRe = new RegExp('(?:^|[\\s;{}()\\[\\]:,=])' + kind + '\\s*\\(', 'g');
      let m;
      while ((m = openRe.exec(code)) !== null) {
        const start = m.index + m[0].length - 1;
        let depth = 0;
        let pos = start;
        for (; pos < code.length; pos++) {
          if (code[pos] === '(') depth++;
          else if (code[pos] === ')') {
            depth--;
            if (depth === 0) break;
          }
        }
        if (depth !== 0) continue;
        if (/^\s*:/.test(code.slice(pos + 1))) {
          counts[kind]++;
        }
      }
    }
  }

  const issues = [];
  for (const kind of ['if', 'foreach', 'for', 'while']) {
    if (counts[kind] !== counts['end' + kind]) {
      issues.push(`${kind} 替代语法不配对：开启 ${counts[kind]} 个 / 闭合 ${counts['end' + kind]} 个`);
    }
  }

  if (issues.length) {
    failed++;
    report.push(`✗ ${rel}`);
    issues.forEach((line) => report.push('    ' + line));
  }
}

report.push('');
report.push(`替代语法检查：${files.length} 个 PHP 文件，${failed} 个有问题`);
report.push('');
report.push('这只是模板结构的一项检查，不代表语法正确。请在真实 PHP 环境做最终确认：');
report.push('  php bin/mcfix.php doctor');
report.push('  php -l <文件>');

console.log(report.join('\n'));
process.exit(failed > 0 ? 1 : 0);
