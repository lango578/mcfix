/* ==========================================================================
   MC 故障反馈系统 —— 玩家端交互
   无依赖，纯原生 JS。流程：
     填写表单 → submit → 拿到一次性验证令牌 → verify（服务端真连一次服务器）
     → 轮询 progress 直到出结论
   ========================================================================== */
(function () {
  'use strict';

  var API = 'index.php?r=api.';
  var bootstrapData = window.MCFIX_BOOTSTRAP || null;
  // 通过某台服务器的专属链接进来时，由页面注入 {server_id, token}；否则为 null。
  // 拿去做什么：提交时回传 token，让后端用它核对 server_id（前端锁不住绕过）。
  var lockedServer = window.MCFIX_LOCKED_SERVER || null;

  function $(sel, root) { return (root || document).querySelector(sel); }
  function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

  function api(action, payload, method) {
    method = method || 'POST';
    var url = API + action;
    var options = {
      method: method,
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'Accept': 'application/json'
      },
      credentials: 'same-origin'
    };
    if (method === 'POST') {
      options.headers['Content-Type'] = 'application/json';
      options.body = JSON.stringify(payload || {});
    } else if (payload) {
      url += '&' + new URLSearchParams(payload).toString();
    }
    return fetch(url, options).then(function (res) {
      return res.json().catch(function () {
        throw new Error('服务器返回了无法解析的内容（HTTP ' + res.status + '）');
      });
    });
  }

  function escapeHtml(text) {
    return String(text == null ? '' : text)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
  }

  /* ------------------------------------------------------------ 服务器状态徽标 */

  function refreshPings() {
    var nodes = $$('[data-ping-server]');
    if (!nodes.length) return;
    api('ping', null, 'GET').then(function (res) {
      if (!res.ok || !res.servers) return;
      Object.keys(res.servers).forEach(function (id) {
        var dot = $('[data-ping-server="' + id + '"]');
        var text = $('[data-ping-text="' + id + '"]');
        var info = res.servers[id];
        if (dot) {
          dot.className = 'dot ' + (info.online ? 'dot-ok' : 'dot-bad');
        }
        if (text) {
          text.textContent = info.online
            ? ('在线 ' + info.players + (info.max ? '/' + info.max : '') + ' 人')
            : '离线';
          text.style.color = info.online ? 'var(--ok)' : 'var(--bad)';
        }
      });
    }).catch(function () { /* 探测失败不影响提交 */ });
  }

  /* ------------------------------------------------------------ 分类提示 */

  function bindCategoryHint(categories) {
    var hint = $('#category-hint');
    if (!hint) return;
    var map = {};
    (categories || []).forEach(function (c) { map[c.key] = c.hint; });

    function update() {
      var checked = $('input[name="category"]:checked');
      hint.textContent = checked ? (map[checked.value] || '') : '';
    }
    $$('input[name="category"]').forEach(function (input) {
      input.addEventListener('change', update);
    });
    update();
  }

  /* ------------------------------------------------------------ 客户端日志上传 */

  function bindLogInputs(form) {
    var drop = $('#file-drop');
    var input = $('#client_log_file');
    var chosen = $('#file-chosen');

    if (!input) return;

    function showChosen() {
      if (!chosen || !input.files || !input.files.length) return;
      var file = input.files[0];
      chosen.hidden = false;
      chosen.textContent = '✓ 已选择：' + file.name + '（' + humanSize(file.size) + '）';
      drop.classList.add('has-file');
    }

    input.addEventListener('change', showChosen);

    if (drop) {
      ['dragenter', 'dragover'].forEach(function (type) {
        drop.addEventListener(type, function (event) {
          event.preventDefault();
          drop.classList.add('is-drag');
        });
      });
      ['dragleave', 'drop'].forEach(function (type) {
        drop.addEventListener(type, function (event) {
          event.preventDefault();
          drop.classList.remove('is-drag');
        });
      });
      drop.addEventListener('drop', function (event) {
        var files = event.dataTransfer && event.dataTransfer.files;
        if (files && files.length) {
          input.files = files;
          showChosen();
        }
      });
    }

    // 玩家一粘贴日志，自动切到"客户端报错"分类
    var textarea = $('#client_log');
    if (textarea) {
      textarea.addEventListener('input', function () {
        if (textarea.value.trim().length > 80) {
          var radio = form.querySelector('input[name="category"][value="client_problem"]');
          if (radio && !radio.checked) {
            radio.checked = true;
            radio.dispatchEvent(new Event('change', { bubbles: true }));
          }
        }
      });
    }
  }

  function humanSize(bytes) {
    if (!bytes) return '0 B';
    var units = ['B', 'KB', 'MB', 'GB'];
    var index = 0;
    while (bytes >= 1024 && index < units.length - 1) {
      bytes /= 1024;
      index++;
    }
    return (index === 0 ? bytes : bytes.toFixed(1)) + ' ' + units[index];
  }

  function hasLogPayload(form) {
    var input = $('#client_log_file');
    if (input && input.files && input.files.length) return true;
    var textarea = $('#client_log');
    return !!(textarea && textarea.value.trim().length > 20);
  }

  /* ------------------------------------------------------------ 客户端分析结果 */

  function renderClient(client) {
    var panel = $('#client-panel');
    if (!panel) return;

    if (!client || !client.issues || !client.issues.length) {
      panel.hidden = true;
      return;
    }

    var html = '';

    // 环境事实
    if (client.facts && client.facts.length) {
      html += '<div class="meta-grid">';
      client.facts.forEach(function (fact) {
        html += '<div><span>' + escapeHtml(fact.label) + '</span><b>' + escapeHtml(fact.value) + '</b></div>';
      });
      html += '</div>';
    }

    // 主要问题 + 解决步骤
    client.issues.forEach(function (issue, index) {
      html += '<div class="client-issue sev-' + escapeHtml(issue.severity) + '">' +
        '<div class="client-issue-head">' +
          '<span class="client-issue-badge">' + (index === 0 ? '主要原因' : '相关问题') + '</span>' +
          '<h3>' + escapeHtml(issue.title) + '</h3>' +
        '</div>' +
        '<p class="client-issue-cause">' + escapeHtml(issue.cause) + '</p>';

      if (issue.detail) {
        html += '<p class="client-issue-detail">日志线索：' + escapeHtml(issue.detail) + '</p>';
      }

      if (issue.extra && issue.extra.length) {
        html += '<div class="client-tags">';
        issue.extra.forEach(function (item) {
          html += '<span class="tag">' + escapeHtml(item) + '</span>';
        });
        html += '</div>';
      }

      if (issue.steps && issue.steps.length) {
        html += '<div class="client-steps"><b>你可以这样做：</b><ol>';
        issue.steps.forEach(function (step) {
          html += '<li>' + escapeHtml(step) + '</li>';
        });
        html += '</ol></div>';
      }

      // 服务端能自动做的事情
      var fix = issue.server_fix || {};
      if (fix.labels && fix.labels.length) {
        html += '<div class="server-fix">';
        if (fix.auto) {
          html += '<span class="pill pill-ok">系统已自动处理</span>';
        } else if (fix.needs_approval) {
          html += '<span class="pill pill-warn">已提交管理员确认</span>';
        } else {
          html += '<span class="pill pill-info">已通知管理员</span>';
        }
        html += '<span class="server-fix-text">服务端动作：' + escapeHtml(fix.labels.join('、')) + '</span>';
        html += '</div>';
      }

      html += '</div>';
    });

    // 需要下载的组件
    var needs = client.components || [];
    if (needs.length) {
      html += '<div class="client-needs"><b>需要补齐的文件</b>';
      html += '<p class="hint">版本必须和服务端一致，直接下载下面提供的文件是最稳的做法。</p>';
      needs.forEach(function (component) {
        html += '<div class="need-row">' +
          '<div class="need-name">' + escapeHtml(component.name) +
            (component.size_text ? '<small>' + escapeHtml(component.size_text) + '</small>' : '') +
          '</div>';
        if (component.available && component.download) {
          html += '<a class="btn btn-mini btn-primary" href="' + escapeHtml(component.download) + '">下载</a>';
        } else {
          html += '<button class="btn btn-mini" type="button" data-mod-request="' + escapeHtml(component.name) + '">从服务器取回</button>';
        }
        if (component.note) {
          html += '<span class="need-note">' + escapeHtml(component.note) + '</span>';
        }
        html += '</div>';
      });
      html += '</div>';
    }

    // 与服务端的比对结果
    var cross = client.cross || {};
    if (cross.possible) {
      html += '<details class="cross-box"><summary>与服务端的 MOD 比对结果</summary>';
      if (cross.extra && cross.extra.length) {
        html += '<p class="bad">你的客户端多装了这些（服务端没有，容易导致被踢）：</p><div class="client-tags">';
        cross.extra.forEach(function (item) { html += '<span class="tag tag-warn">' + escapeHtml(item) + '</span>'; });
        html += '</div>';
      }
      if (cross.missing && cross.missing.length) {
        html += '<p class="warn-text">你缺少这些（服务端有）：</p><div class="client-tags">';
        cross.missing.forEach(function (item) { html += '<span class="tag tag-fix">' + escapeHtml(item) + '</span>'; });
        html += '</div>';
      }
      html += '</details>';
    } else if (cross.note) {
      html += '<p class="hint">' + escapeHtml(cross.note) + '</p>';
    }

    if (client.log_name || client.log_kind) {
      html += '<p class="hint">已分析：' + escapeHtml(client.log_kind || '') +
        (client.log_name ? '（' + escapeHtml(client.log_name) + '）' : '') + '</p>';
    }

    panel.innerHTML = html;
    panel.hidden = false;

    // "从服务器取回"按钮
    Array.prototype.forEach.call(panel.querySelectorAll('[data-mod-request]'), function (button) {
      button.addEventListener('click', function () {
        requestMod(button);
      });
    });
  }

  function requestMod(button) {
    var component = button.getAttribute('data-mod-request');
    var token = currentToken();
    if (!token) {
      button.textContent = '链接失效，请刷新页面';
      return;
    }

    button.disabled = true;
    button.textContent = '正在请求…';

    api('mod_request', { token: token, component: component }).then(function (res) {
      if (!res.ok) throw new Error(res.error || '请求失败');

      if (res.state === 'ready' && res.download) {
        button.outerHTML = '<a class="btn btn-mini btn-primary" href="' + escapeHtml(res.download) + '">下载</a>';
        return;
      }

      button.textContent = '已请求，稍后刷新';
      button.disabled = false;
      var row = button.parentNode;
      var note = document.createElement('span');
      note.className = 'need-note';
      note.textContent = res.message || '文件正在取回，十几秒后刷新本页即可看到下载按钮';
      row.appendChild(note);

      setTimeout(function () {
        api('progress', { token: token }, 'GET').then(function (next) {
          if (next.ok) render(next);
        });
      }, 15000);
    }).catch(function (err) {
      button.disabled = false;
      button.textContent = '重试取回';
      alert(err.message || '请求失败');
    });
  }

  /* ------------------------------------------------------------ 表单提交 */

  function bindForm(form) {
    var errorBox = $('#form-error');
    var submitBtn = $('#submit-btn');
    var progressCard = $('#progress-card');

    function showError(message) {
      if (!errorBox) { alert(message); return; }
      errorBox.textContent = message;
      errorBox.hidden = false;
      errorBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    function clearError() {
      if (errorBox) { errorBox.hidden = true; errorBox.textContent = ''; }
    }

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      clearError();

      var data = {
        player_name: (form.player_name.value || '').trim(),
        server_id: form.server_id.value,
        category: (form.querySelector('input[name="category"]:checked') || {}).value || 'auto',
        message: (form.message.value || '').trim(),
        player_email: (form.player_email ? form.player_email.value : '').trim(),
        contact: (form.contact ? form.contact.value : ''),
        client_version: (form.client_version ? form.client_version.value : ''),
        client_launcher: (form.client_launcher ? form.client_launcher.value : ''),
        client_loader: (form.client_loader ? form.client_loader.value : '')
      };

      // 专属链接进来时带上分享令牌：提交那一刻由后端用它解出 server_id 再核对一遍。
      // 前端把下拉框锁成一行只是"不显示"，改一个 POST 就能绕过去 —— 真正管用的
      // 是后端那次校验，令牌就是给它用的凭据。
      if (lockedServer && lockedServer.token) {
        data.share_token = lockedServer.token;
      }

      if (data.player_name.length < 2) { showError('请填写你的游戏 ID'); return; }
      if (data.message.length < 4) { showError('请把问题描述得具体一点'); return; }
      // 邮箱是选填的，但填了就得像个邮箱 —— 否则服务端会拒，不如在这里先说清楚
      if (data.player_email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(data.player_email)) {
        showError('邮箱格式看起来不对（也可以留空，不影响提交）');
        return;
      }

      var withFile = hasLogPayload(form);
      if (submitBtn) submitBtn.classList.add('is-loading');
      var tip = $('#submit-tip');
      if (tip && withFile) tip.textContent = '正在上传日志并分析，稍等十几秒…';

      var request = withFile
        ? submitWithFormData(form, data)
        : api('submit', data);

      request.then(function (res) {
        if (!res.ok) throw new Error(res.error || '提交失败');
        if (progressCard) progressCard.hidden = false;
        setTicket(res.ticket_no);
        setStatus('submitted', '正在验证…');
        markStep('submitted', 'done');
        markStep('diagnosing', 'active');
        if (progressCard) progressCard.scrollIntoView({ behavior: 'smooth', block: 'start' });

        if (res.share_url) {
          var share = $('#share-link');
          if (share) { share.href = res.share_url; share.hidden = false; }
          try { history.replaceState(null, '', res.share_url); } catch (e) { /* 忽略 */ }
        }

        return runVerify(res.verify_token);
      }).catch(function (err) {
        showError(err.message || '提交失败，请稍后重试');
        setStatus('unresolved', '提交失败');
      }).then(function () {
        if (submitBtn) submitBtn.classList.remove('is-loading');
        if (tip) tip.textContent = '提交后会立刻开始检测，通常 5~20 秒出结果';
      });
    });
  }

  /**
   * 带文件时用 multipart/form-data 提交（$_FILES 才拿得到文件）。
   */
  function submitWithFormData(form, data) {
    var body = new FormData();
    Object.keys(data).forEach(function (key) {
      body.append(key, data[key] === null || data[key] === undefined ? '' : data[key]);
    });

    var fileInput = $('#client_log_file');
    if (fileInput && fileInput.files && fileInput.files.length) {
      body.append('client_log_file', fileInput.files[0]);
    }
    var textarea = $('#client_log');
    if (textarea && textarea.value.trim()) {
      body.append('client_log', textarea.value);
    }

    return fetch(API + 'submit', {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
      credentials: 'same-origin',
      body: body
    }).then(function (res) {
      return res.json().catch(function () {
        throw new Error('服务器返回了无法解析的内容（HTTP ' + res.status + '）');
      });
    });
  }

  /* ------------------------------------------------------------ 验证 + 轮询 */

  function runVerify(token) {
    if (!token) return Promise.resolve();

    setStatus('diagnosing', '正在验证…');
    markStep('diagnosing', 'active');

    return api('verify', { token: token }).then(function (res) {
      if (!res.ok) throw new Error(res.error || '验证失败');
      render(res);
      return pollUntilSettled(res.verify_token, 0);
    }).catch(function (err) {
      markStep('diagnosing', 'fail');
      setStatus('unresolved', '验证失败');
      setHeadline(err.message || '验证失败，请稍后重试');
    });
  }

  function pollUntilSettled(token, attempt) {
    var settled = ['resolved', 'manual', 'unresolved', 'rejected', 'closed'];
    var maxAttempts = 40; // 约 80 秒

    return api('progress', { token: token }, 'GET').then(function (res) {
      if (!res.ok) return null;
      render(res);

      if (res.verify_token) token = res.verify_token;
      if (settled.indexOf(res.status) !== -1) return res;
      if (attempt >= maxAttempts) return res;

      var wait = res.status === 'verifying' ? 3000 : 2000;
      return new Promise(function (resolve) { setTimeout(resolve, wait); })
        .then(function () { return pollUntilSettled(token, attempt + 1); });
    });
  }

  /* ------------------------------------------------------------ 渲染 */

  function setTicket(no) {
    var node = $('#ticket-no');
    if (node) node.textContent = no || '—';
  }

  function setStatus(status, label) {
    var pill = $('#status-pill');
    if (!pill) return;
    var tone = {
      submitted: 'info', diagnosing: 'busy', diagnosed: 'info', fixing: 'busy',
      verifying: 'busy', resolved: 'ok', manual: 'warn', unresolved: 'warn',
      rejected: 'muted', closed: 'muted'
    }[status] || 'muted';
    pill.className = 'pill pill-' + tone;
    pill.textContent = label || status;
  }

  function setHeadline(text) {
    var node = $('#progress-headline');
    if (node) node.textContent = text || '';
  }

  function markStep(step, state) {
    var node = $('.steps li[data-step="' + step + '"]');
    if (!node) return;
    node.classList.remove('is-active', 'is-done', 'is-fail');
    if (state) node.classList.add('is-' + state);
  }

  function render(data) {
    if (!data) return;

    if (data.ticket_no) setTicket(data.ticket_no);
    setStatus(data.status, data.status_label);
    setHeadline(data.headline || '');

    // 步骤条
    var order = ['submitted', 'diagnosing', 'fixing', 'verifying', 'resolved'];
    var currentIndex = order.indexOf(data.status);
    if (data.status === 'manual' || data.status === 'unresolved') currentIndex = 1;
    if (data.status === 'rejected' || data.status === 'closed') currentIndex = 4;

    order.forEach(function (step, index) {
      if (index < currentIndex) markStep(step, 'done');
      else if (index === currentIndex) markStep(step, (data.status === 'unresolved' || data.status === 'rejected') ? 'fail' : 'active');
      else markStep(step, '');
    });
    if (data.status === 'resolved') markStep('resolved', 'done');

    // 验证明细
    var checksBox = $('#checks');
    if (checksBox && data.checks && data.checks.length) {
      checksBox.innerHTML = data.checks.map(function (check) {
        return '<div class="check check-' + escapeHtml(check.status) + '">' +
          '<span class="check-dot"></span>' +
          '<div><b>' + escapeHtml(check.label) + '</b><small>' + escapeHtml(check.text) + '</small></div>' +
          '</div>';
      }).join('');
    }

    // 客户端分析结果（如果这次带了日志）
    renderClient(data.client);

    // 时间线
    var timeline = $('#timeline');
    if (timeline && data.timeline && data.timeline.length) {
      timeline.innerHTML = data.timeline.map(function (event) {
        return '<div class="tl-item tl-' + escapeHtml(event.level) + '">' +
          '<span class="tl-time">' + escapeHtml(formatTime(event.at)) + '</span>' +
          '<span class="tl-actor">' + escapeHtml(event.actor) + '</span>' +
          '<span class="tl-msg">' + escapeHtml(event.message) + '</span>' +
          '</div>';
      }).join('');
    }

    // 复验按钮
    var reverify = $('#reverify-btn');
    if (reverify) {
      reverify.hidden = !data.can_reverify;
      if (!reverify.dataset.bound) {
        reverify.dataset.bound = '1';
        reverify.addEventListener('click', function () {
          reverify.disabled = true;
          reverify.textContent = '验证中…';
          api('progress', { token: currentToken() }, 'GET').then(function (res) {
            if (res.verify_token) return runVerify(res.verify_token);
          }).then(function () {
            reverify.disabled = false;
            reverify.textContent = '再验证一次';
          }).catch(function () {
            reverify.disabled = false;
            reverify.textContent = '再验证一次';
          });
        });
      }
    }
  }

  function currentToken() {
    var share = $('#share-link');
    if (share && share.href && share.href.indexOf('t=') !== -1) {
      return decodeURIComponent(share.href.split('t=')[1].split('&')[0]);
    }
    return '';
  }

  function formatTime(value) {
    if (!value) return '';
    var parts = String(value).replace(' ', 'T').split('T');
    if (parts.length < 2) return value;
    var time = parts[1].slice(0, 8);
    var date = parts[0].slice(5);
    return date + ' ' + time;
  }

  /* ------------------------------------------------------------ 启动 */

  document.addEventListener('DOMContentLoaded', function () {
    refreshPings();
    setInterval(refreshPings, 60000);

    api('bootstrap', null, 'GET').then(function (res) {
      if (res.ok) bindCategoryHint(res.categories);
    }).catch(function () { /* 忽略 */ });

    var form = $('#feedback-form');
    if (form) {
      bindLogInputs(form);
      bindForm(form);

      // 带 ?t= 工单链接打开时，页面里已经有工单信息：直接显示状态并自动验证一次
      if (bootstrapData && bootstrapData.verify_token) {
        var card = $('#progress-card');
        if (card) card.hidden = false;
        setTicket(bootstrapData.ticket);
        setStatus('submitted', '已提交');
        markStep('submitted', 'done');
        markStep('diagnosing', 'active');
        if (bootstrapData.id) {
          $('#feedback-form').hidden = true;
        }
        runVerify(bootstrapData.verify_token);
      }
    }
  });
})();
