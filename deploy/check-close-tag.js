#!/usr/bin/env node
/**
 * 查找 PHP 里"意外出现的 ?>"。
 *
 * 为什么需要这个脚本：`?>` 是 PHP 的段结束标签，它出现的位置比大多数人以为的要多：
 *
 *   1. 字符串里 ——  `'/white-?listed/i'` 这种，问号紧挨着 l，PHP 会把 `?l` 里的
 *      两个字符当成结束标签，字符串当场被截断。这是本项目真实踩过的语法错误。
 *   2. **单行注释里** —— 注释里写"正则里的问号紧挨 l 会被当成结束标签"并顺手把
 *      那个两字符序列打出来，同样会结束 PHP 段，后面的代码全部变成原样输出的文本。
 *      这不是语法错误，`php -l` 查不出来，表现为"页面后半截突然变成源码"。
 *   3. 块注释里 —— 同样会结束 PHP 段。
 *
 * 所以这个脚本扫字符串和两种注释。仍然只是**辅助**检查，不能替代 `php -l`，
 * 也不能替代真正打开一次页面看输出。
 *
 * 用法：node deploy/check-close-tag.js [目录]
 */
const fs = require('fs');
const path = require('path');

// 默认扫描本仓库根目录；也可以 node deploy/check-close-tag.js <目录>
const ROOT = path.resolve(process.argv[2] || path.join(__dirname, '..'));

function walk(dir, out = []) {
  if (!fs.existsSync(dir)) {
    console.error(`✗ 目录不存在：${dir}`);
    process.exit(2);
  }
  if (fs.statSync(dir).isFile()) return dir.endsWith('.php') ? [dir] : [];
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    if (['node_modules', '.git', 'storage', 'deploy', 'vendor'].includes(e.name)) continue;
    const full = path.join(dir, e.name);
    if (e.isDirectory()) walk(full, out);
    else if (e.name.endsWith('.php')) out.push(full);
  }
  return out;
}

let problems = 0;

for (const file of walk(ROOT).sort()) {
  const src = fs.readFileSync(file, 'utf8');
  const rel = path.relative(ROOT, file).replace(/\\/g, '/');
  const hits = [];

  let i = 0;
  let inPhp = false;

  const lineAt = (pos) => {
    let n = 1;
    for (let k = 0; k < pos && k < src.length; k++) if (src[k] === '\n') n++;
    return n;
  };
  const snippet = (pos) => src.slice(Math.max(0, pos - 40), pos + 10).replace(/\n/g, ' ');

  while (i < src.length) {
    // --- 模板区：找下一个 <?php / <?=
    if (!inPhp) {
      const open = src.indexOf('<?php', i);
      const echo = src.indexOf('<?=', i);
      let next = -1;
      if (open === -1 && echo === -1) break;
      else if (open === -1) next = echo;
      else if (echo === -1) next = open;
      else next = Math.min(open, echo);
      i = next + (next === echo ? 3 : 5);
      inPhp = true;
      continue;
    }

    const ch = src[i];
    const nx = src[i + 1];

    // --- 单行注释：整行扫一遍找 ?>
    if ((ch === '/' && nx === '/') || (ch === '#' && nx !== '[')) {
      const end = src.indexOf('\n', i);
      const stop = end === -1 ? src.length : end;
      const at = src.indexOf('?>', i);
      if (at !== -1 && at < stop) {
        hits.push({ line: lineAt(at), kind: '单行注释', snippet: snippet(at) });
      }
      i = stop;
      continue;
    }

    // --- 块注释
    if (ch === '/' && nx === '*') {
      const end = src.indexOf('*/', i + 2);
      const stop = end === -1 ? src.length : end + 2;
      const at = src.indexOf('?>', i);
      if (at !== -1 && at < stop) {
        hits.push({ line: lineAt(at), kind: '块注释', snippet: snippet(at) });
      }
      i = stop;
      continue;
    }

    // --- 字符串：在字符串内部找 ?>
    if (ch === "'" || ch === '"') {
      const quote = ch;
      const startLine = lineAt(i);
      let pos = i + 1;
      let found = -1;
      while (pos < src.length) {
        if (src[pos] === '\\') { pos += 2; continue; }
        if (src[pos] === quote) break;
        if (src[pos] === '?' && src[pos + 1] === '>') { found = pos; break; }
        pos++;
      }
      if (found >= 0) {
        hits.push({ line: startLine, kind: '字符串', snippet: snippet(found) });
      }
      i = pos + 1;
      continue;
    }

    // --- PHP 段正常结束
    if (ch === '?' && nx === '>') { i += 2; inPhp = false; continue; }

    i++;
  }

  if (hits.length) {
    problems++;
    console.log(`✗ ${rel}`);
    hits.forEach((h) => {
      console.log(`    第 ${h.line} 行 ${h.kind} 内含 "?>"： ...${h.snippet}...`);
    });
  }
}

console.log('');
console.log(problems
  ? `${problems} 个文件存在"意外 ?>"问题。\n` +
    `  字符串里的会让语法直接报错；注释里的会让后面的代码变成原样输出（php -l 查不出来，一定要打开页面看）。`
  : '✓ 没有发现意外的 ?>');

process.exit(problems ? 1 : 0);
