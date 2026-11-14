/* กระดานกิจกรรม: แสดงผล 9 รูปแบบ, ส่งคำตอบ/อัปโหลดผลงาน, ความคิดเห็น, reaction, อัปเดตอัตโนมัติ (polling) */
(() => {
    'use strict';
    const C = window.BOARD;
    const app = document.getElementById('board-app');
    let data = null, rev = 0, view = C.layout, teamFilter = '', pendingOnly = false, busy = false, dragging = false, map = null;
    let selectMode = false;            // ครูเลือกหลายคนเพื่อมอบดาวพร้อมกัน
    const picked = new Set();          // id ของโพสต์ที่เลือก
    const openComments = new Set();

    try { const saved = localStorage.getItem('board-view-' + C.board); if (saved && C.layouts[saved]) view = saved; } catch (e) {}

    // ---------- ตัวช่วย ----------
    const h = (tag, attrs = {}, ...kids) => {
        const el = document.createElement(tag);
        for (const [k, v] of Object.entries(attrs || {})) {
            if (v === null || v === undefined || v === false) continue;
            if (k === 'class') el.className = v;
            else if (k === 'text') el.textContent = v;
            else if (k.startsWith('on')) el.addEventListener(k.slice(2), v);
            else el.setAttribute(k, v === true ? '' : v);
        }
        for (const kid of kids.flat()) if (kid !== null && kid !== undefined && kid !== false) el.append(kid.nodeType ? kid : document.createTextNode(String(kid)));
        return el;
    };
    const icon = (name) => h('i', { class: 'bi ' + name, 'aria-hidden': 'true' });
    const fmtSize = (b) => b >= 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB';
    const fmtTime = (s) => {
        if (!s) return '';
        const d = new Date(s.replace(' ', 'T') + '+07:00'), diff = (Date.now() - d) / 1000;
        if (diff < 60) return 'เมื่อสักครู่';
        if (diff < 3600) return Math.floor(diff / 60) + ' นาทีที่แล้ว';
        if (diff < 86400) return Math.floor(diff / 3600) + ' ชั่วโมงที่แล้ว';
        return d.toLocaleString('th-TH', { day: 'numeric', month: 'short', year: '2-digit', hour: '2-digit', minute: '2-digit' });
    };
    const toast = (msg, type = 'success') => {
        const t = h('div', { class: 'board-toast alert alert-' + type + ' shadow', role: 'status' }, msg);
        document.body.append(t);
        setTimeout(() => t.remove(), 4000);
    };
    const kindOf = (name) => {
        const ext = (name.split('.').pop() || '').toLowerCase();
        for (const [kind, exts] of Object.entries(C.kinds)) if (exts.includes(ext)) return kind;
        return null;
    };

    async function api(action, fields = {}) {
        const fd = new FormData();
        fd.append('csrf', C.csrf); fd.append('board_id', C.board); fd.append('action', action);
        for (const [k, v] of Object.entries(fields)) {
            if (Array.isArray(v)) v.forEach((x) => fd.append(k + '[]', x));
            else if (v !== null && v !== undefined) fd.append(k, v);
        }
        let res;
        try { res = await fetch(C.api, { method: 'POST', body: fd, credentials: 'same-origin' }); }
        catch (e) { throw new Error('เชื่อมต่อเซิร์ฟเวอร์ไม่ได้ กรุณาตรวจสอบอินเทอร์เน็ตแล้วลองใหม่'); }
        let json = null;
        try { json = await res.json(); } catch (e) {}
        if (!json || !json.ok) throw new Error(json && json.error ? json.error : 'เกิดข้อผิดพลาด (' + res.status + ') กรุณาลองใหม่');
        return json;
    }

    async function refresh(force = false) {
        if (busy && !force) return;
        try {
            const res = await fetch(C.api + '?board=' + C.board + '&since=' + (force ? 0 : rev), { credentials: 'same-origin', cache: 'no-store' });
            const json = await res.json();
            document.getElementById('live-dot').classList.toggle('text-danger', !json.ok);
            if (!json.ok) { if (res.status === 401 || res.status === 403) app.replaceChildren(h('div', { class: 'alert alert-warning' }, json.error)); return; }
            if (!json.changed) return;
            data = json; rev = json.rev;
            if (!dragging) render();
        } catch (e) { document.getElementById('live-dot').classList.add('text-danger'); }
    }

    // ---------- การ์ดโพสต์ ----------
    const STATUS = { pending: ['รอครูอนุมัติ', 'warning text-dark'], rejected: ['ครูให้แก้ไข', 'danger'] };

    function attachmentsView(post) {
        const imgs = post.attachments.filter((a) => a.kind === 'image');
        const box = h('div', { class: 'post-files' });
        if (imgs.length) box.append(h('div', { class: 'post-gallery n' + Math.min(imgs.length, 4) }, imgs.map((a) =>
            h('button', { type: 'button', class: 'post-img', onclick: () => openViewer(a), 'aria-label': 'ดูรูป ' + a.name }, h('img', { src: a.url, alt: a.name, loading: 'lazy' })))));
        for (const a of post.attachments) {
            if (a.kind === 'video') box.append(h('video', { src: a.url, controls: true, preload: 'metadata', class: 'w-100 rounded mt-2 bg-dark' }));
            else if (a.kind === 'audio') box.append(h('div', { class: 'mt-2' }, h('div', { class: 'small text-muted text-truncate' }, icon('bi-music-note-beamed'), ' ', a.name), h('audio', { src: a.url, controls: true, preload: 'none', class: 'w-100' })));
            else if (a.kind === 'document') {
                const ico = { pdf: 'bi-file-earmark-pdf text-danger', doc: 'bi-file-earmark-word text-primary', docx: 'bi-file-earmark-word text-primary', ppt: 'bi-file-earmark-slides text-warning', pptx: 'bi-file-earmark-slides text-warning', xls: 'bi-file-earmark-excel text-success', xlsx: 'bi-file-earmark-excel text-success' }[a.ext] || 'bi-file-earmark';
                box.append(h('div', { class: 'post-doc' }, icon(ico + ' fs-4'), h('span', { class: 'flex-grow-1 text-truncate', title: a.name }, a.name, h('small', { class: 'd-block text-muted' }, fmtSize(a.size))),
                    a.ext === 'pdf' ? h('button', { type: 'button', class: 'btn btn-sm btn-outline-secondary', onclick: () => openViewer(a) }, 'ดู') : null,
                    h('a', { class: 'btn btn-sm btn-outline-primary', href: a.url + '&download=1', 'aria-label': 'ดาวน์โหลด ' + a.name }, icon('bi-download'))));
            }
        }
        return box;
    }

    function linkView(link) {
        if (!link) return null;
        if (link.youtube) return h('div', { class: 'ratio ratio-16x9 mt-2 rounded overflow-hidden' }, h('iframe', { src: 'https://www.youtube-nocookie.com/embed/' + link.youtube, title: 'YouTube', allowfullscreen: true, loading: 'lazy', allow: 'encrypted-media; picture-in-picture' }));
        return h('a', { class: 'post-link', href: link.url, target: '_blank', rel: 'noopener noreferrer' }, icon('bi-link-45deg'), h('span', { class: 'text-truncate' }, link.host || link.url));
    }

    function reactionView(post) {
        const mode = data.board.reaction_mode, can = data.me.can.react && !post.mine && post.status === 'approved';
        if (mode === 'none') return null;
        if (mode === 'star') {
            const stars = h('span', { class: 'post-stars', 'aria-label': 'ให้ดาว' });
            for (let i = 1; i <= 5; i++) stars.append(h('button', { type: 'button', class: 'btn btn-link p-0 star' + (i <= post.reactions.mine ? ' on' : ''), disabled: !can, 'aria-label': i + ' ดาว',
                onclick: () => act('react', { post_id: post.id, value: post.reactions.mine === i ? 0 : i }) }, icon(i <= post.reactions.mine ? 'bi-star-fill' : 'bi-star')));
            return h('span', { class: 'd-inline-flex align-items-center gap-1' }, stars, h('small', { class: 'text-muted' }, post.reactions.count ? post.reactions.avg + ' (' + post.reactions.count + ')' : ''));
        }
        const on = post.reactions.mine > 0, ico = mode === 'vote' ? (on ? 'bi-hand-thumbs-up-fill' : 'bi-hand-thumbs-up') : (on ? 'bi-heart-fill' : 'bi-heart');
        return h('button', { type: 'button', class: 'btn btn-sm btn-link text-decoration-none react-btn' + (on ? ' on' : ''), disabled: !can, 'aria-pressed': on ? 'true' : 'false',
            onclick: () => act('react', { post_id: post.id, value: on ? 0 : 1 }), title: mode === 'vote' ? 'โหวต' : 'ถูกใจ' }, icon(ico), ' ', post.reactions.count || '', mode === 'vote' ? ' โหวต' : '');
    }

    function commentsView(post) {
        if (!data.board.allow_comments && !data.me.can.moderate) return null;
        const open = openComments.has(post.id);
        const wrap = h('div', { class: 'post-comments' });
        const toggle = h('button', { type: 'button', class: 'btn btn-sm btn-link text-decoration-none', onclick: () => { open ? openComments.delete(post.id) : openComments.add(post.id); render(); } },
            icon('bi-chat-left-text'), ' ', post.comments.length ? post.comments.length + ' ความคิดเห็น' : 'แสดงความคิดเห็น');
        if (!open) return toggle;
        wrap.append(toggle);
        for (const c of post.comments) wrap.append(h('div', { class: 'comment' }, h('img', { src: c.avatar, class: 'avatar-xs', alt: '' }),
            h('div', { class: 'flex-grow-1' }, h('b', {}, c.author), ' ', h('small', { class: 'text-muted' }, fmtTime(c.at)), h('div', { class: 'comment-body' }, c.body)),
            c.can_delete ? h('button', { type: 'button', class: 'btn btn-sm btn-link text-danger p-0', 'aria-label': 'ลบความคิดเห็น', onclick: () => confirm('ลบความคิดเห็นนี้?') && act('comment_delete', { comment_id: c.id }) }, icon('bi-x-lg')) : null));
        if (data.me.can.comment) {
            const input = h('input', { class: 'form-control form-control-sm', name: 'comment-' + post.id, maxlength: 2000, placeholder: 'เขียนความคิดเห็น…', 'aria-label': 'ความคิดเห็น' });
            wrap.append(h('form', { class: 'd-flex gap-1 mt-2', onsubmit: async (e) => {
                e.preventDefault();
                if (!input.value.trim()) return;
                const ok = await act('comment_add', { post_id: post.id, body: input.value });
                if (ok) input.value = '';
            } }, input, h('button', { class: 'btn btn-sm btn-vt', 'aria-label': 'ส่งความคิดเห็น' }, icon('bi-send'))));
        }
        return wrap;
    }

    function menuView(post) {
        const items = [];
        const mod = data.me.can.moderate;
        if (post.can_edit) items.push(['bi-pencil', 'แก้ไข', () => openComposer(post)]);
        if (mod && post.status !== 'approved') items.push(['bi-check-circle text-success', 'อนุมัติ', () => act('approve', { post_id: post.id })]);
        if (mod && post.status !== 'rejected') items.push(['bi-arrow-counterclockwise text-danger', 'ไม่อนุมัติ / ให้แก้ไข', () => { const note = prompt('บอกนักเรียนว่าต้องแก้ไขอะไร'); if (note && note.trim()) act('reject', { post_id: post.id, note }); }]);
        if (mod) items.push(['bi-pin-angle', post.pinned ? 'เลิกปักหมุด' : 'ปักหมุด', () => act('pin', { post_id: post.id })]);
        if (post.can_delete) items.push(['bi-trash text-danger', 'ลบ', () => confirm('ลบโพสต์นี้? ' + (post.mine ? '' : '(ลบโพสต์ของ ' + post.author + ')')) && act('post_delete', { post_id: post.id })]);
        if (!items.length) return null;
        return h('div', { class: 'dropdown' }, h('button', { type: 'button', class: 'btn btn-sm btn-link text-muted p-0', 'data-bs-toggle': 'dropdown', 'aria-label': 'ตัวเลือกโพสต์' }, icon('bi-three-dots-vertical')),
            h('ul', { class: 'dropdown-menu dropdown-menu-end' }, items.map(([ico, label, fn]) => h('li', {}, h('button', { type: 'button', class: 'dropdown-item', onclick: fn }, icon(ico), ' ', label)))));
    }

    function card(post, opts = {}) {
        const team = post.team_id && data.teams.find((t) => t.id === post.team_id);
        const badges = [];
        if (post.pinned) badges.push(h('span', { class: 'badge bg-dark' }, icon('bi-pin-angle-fill'), ' ปักหมุด'));
        if (STATUS[post.status]) badges.push(h('span', { class: 'badge bg-' + STATUS[post.status][1] }, STATUS[post.status][0]));
        if (post.late) badges.push(h('span', { class: 'badge bg-warning text-dark' }, 'ส่งเกินเวลา'));
        if (team) badges.push(h('span', { class: 'badge bg-info-subtle text-info-emphasis' }, team.name));
        const pickable = selectMode && post.level && post.status === 'approved';
        const el = h('article', { class: 'post-card' + (post.mine ? ' mine' : '') + (post.status !== 'approved' ? ' is-' + post.status : '') + (pickable && picked.has(post.id) ? ' picked' : ''), 'data-id': post.id },
            h('header', { class: 'post-head' }, pickable ? pickBox(post) : null, h('span', { class: 'avatar-wrap' }, h('img', { src: post.avatar, class: 'avatar-sm', alt: '' }),
                    post.level ? h('span', { class: 'avatar-level', title: 'Level ' + post.level + ' · ' + post.stars + ' ดาว' }, post.level) : null),
                h('div', { class: 'flex-grow-1 min-w-0' }, h('div', { class: 'fw-semibold text-truncate' }, post.author, post.student_code ? h('small', { class: 'text-muted fw-normal' }, ' ' + post.student_code) : null),
                    h('small', { class: 'text-muted' }, fmtTime(post.created_at), post.updated_at ? ' · แก้ไขแล้ว' : '')),
                post.board_stars ? h('span', { class: 'board-stars', title: 'ดาวจากครูในกิจกรรมนี้' }, '⭐ ', post.board_stars) : null,
                menuView(post)),
            badges.length ? h('div', { class: 'd-flex flex-wrap gap-1 mb-1' }, badges) : null,
            post.status === 'rejected' && post.reject_note ? h('div', { class: 'alert alert-danger py-1 px-2 small mb-2' }, icon('bi-chat-square-text'), ' ครู: ', post.reject_note) : null,
            post.title ? h('h3', { class: 'post-title' }, post.title) : null,
            post.body ? h('div', { class: 'post-body' }, post.body) : null,
            linkView(post.link), attachmentsView(post));
        if (post.mine && data.me.score && opts.withScore) {
            const s = data.me.score;
            el.append(h('div', { class: 'post-score ' + (s.status === 'revision' ? 'revision' : '') },
                s.status === 'revision' ? [icon('bi-arrow-counterclockwise'), ' ครูให้แก้ไขงาน'] : s.score !== null ? [icon('bi-award'), ' คะแนน ', h('b', {}, s.score), ' / ' + data.board.max_points] : [icon('bi-hourglass'), ' รอครูตรวจ'],
                s.feedback ? h('div', { class: 'small mt-1' }, 'ข้อเสนอแนะ: ', s.feedback) : null));
        }
        const give = (n, from) => { starBurst(from); act('award_star', { post_ids: [post.id], amount: n }); };
        const award = data.me.can.manage && post.level && post.status === 'approved' ? h('div', { class: 'btn-group btn-group-sm' },
            h('button', { type: 'button', class: 'btn btn-award', title: 'มอบ 1 ดาวให้ ' + post.author, onclick: (e) => give(1, e.currentTarget) }, icon('bi-star-fill'), ' มอบดาว'),
            h('button', { type: 'button', class: 'btn btn-award dropdown-toggle dropdown-toggle-split', 'data-bs-toggle': 'dropdown', 'aria-label': 'เลือกจำนวนดาว' }),
            h('ul', { class: 'dropdown-menu' }, [1, 2, 3, 5].map((n) => h('li', {}, h('button', { type: 'button', class: 'dropdown-item', onclick: (e) => give(n, e.currentTarget.closest('.btn-group')) }, '+' + n + ' ⭐'))))) : null;
        el.append(h('footer', { class: 'post-foot' }, reactionView(post), award, commentsView(post)));
        return el;
    }

    /** ช่องเลือกนักเรียน (โหมดมอบดาวหลายคน) */
    function pickBox(post) {
        return h('input', { type: 'checkbox', class: 'form-check-input pick-box', checked: picked.has(post.id), 'aria-label': 'เลือก ' + post.author,
            onchange: (e) => { e.target.checked ? picked.add(post.id) : picked.delete(post.id); e.target.closest('.post-card, tr')?.classList.toggle('picked', e.target.checked); updateSelectBar(); } });
    }
    const pickablePosts = () => visiblePosts().filter((p) => p.level && p.status === 'approved');
    const pickedPeople = () => new Set(data.posts.filter((p) => picked.has(p.id)).map((p) => p.user_id)).size;
    function updateSelectBar() {
        const bar = document.getElementById('select-bar');
        if (!bar) return;
        bar.hidden = !selectMode;
        document.getElementById('select-mode')?.classList.toggle('active', selectMode);
        const n = pickedPeople();
        document.getElementById('select-count').textContent = n ? 'เลือกแล้ว ' + n + ' คน' : 'ติ๊กเลือกงานของนักเรียนที่จะให้ดาว';
        document.getElementById('select-award').disabled = !n;
        const all = pickablePosts();
        document.getElementById('select-all').textContent = all.length && all.every((p) => picked.has(p.id)) ? 'ไม่เลือกทั้งหมด' : 'เลือกทั้งหมด (' + new Set(all.map((p) => p.user_id)).size + ' คน)';
        document.getElementById('fab').classList.toggle('d-none', selectMode);
    }
    async function awardPicked(ids, from) {
        const amount = Number(document.getElementById('select-amount')?.value || 1);
        if (from) starBurst(from);
        const res = await act('award_star', { post_ids: ids, amount });
        if (res) { picked.clear(); selectMode = false; render(); }
    }

    /** ดาวลอยขึ้นตอนครูมอบดาว */
    function starBurst(from) {
        const r = from.getBoundingClientRect();
        for (let i = 0; i < 6; i++) {
            const s = h('span', { class: 'star-burst', 'aria-hidden': 'true' }, '⭐');
            s.style.left = r.left + r.width / 2 + 'px'; s.style.top = r.top + 'px';
            s.style.setProperty('--dx', (Math.random() * 120 - 60) + 'px'); s.style.animationDelay = i * 60 + 'ms';
            document.body.append(s);
            setTimeout(() => s.remove(), 1400);
        }
    }

    // ---------- รูปแบบการแสดงผล ----------
    function visiblePosts() {
        return data.posts.filter((p) => (!teamFilter || String(p.team_id) === teamFilter) && (!pendingOnly || p.status === 'pending'));
    }
    const firstMine = () => { const mine = data.posts.filter((p) => p.mine); return mine.length ? mine[mine.length - 1].id : null; };

    function renderCards(posts, cls) {
        const scoreId = firstMine();
        return h('div', { class: cls }, posts.length ? posts.map((p) => card(p, { withScore: p.id === scoreId })) : emptyNote());
    }

    function emptyNote() {
        const hidden = data.board.hide_until_reveal && !data.board.revealed && !data.me.can.moderate;
        return h('div', { class: 'board-empty' }, icon(hidden ? 'bi-eye-slash fs-1' : 'bi-chat-square-dots fs-1'),
            h('div', {}, hidden ? 'คำตอบของเพื่อนจะแสดงเมื่อครูเปิดให้ดู' : 'ยังไม่มีคำตอบ'), data.me.can.post ? h('div', { class: 'small' }, 'กดปุ่ม "ส่งคำตอบ" มุมขวาล่างเพื่อเริ่ม') : null);
    }

    function columnsView(posts) {
        let cols;
        if (data.sections.length) cols = [...data.sections.map((s) => ({ key: 'section_id', id: s.id, title: s.title })), { key: 'section_id', id: null, title: 'ไม่ระบุหัวข้อ' }];
        else if (data.board.group_mode === 'teams' && data.teams.length) cols = [...data.teams.map((t) => ({ key: 'team_id', id: t.id, title: t.name })), { key: 'team_id', id: null, title: 'ไม่มีทีม' }];
        else return renderCards(posts, 'board-grid');
        const wrap = h('div', { class: 'board-columns' });
        for (const col of cols) {
            const items = posts.filter((p) => (p[col.key] || null) === col.id);
            if (col.id === null && !items.length) continue;
            const body = h('div', { class: 'column-body', 'data-section': col.id ?? '' }, items.map((p) => card(p)));
            if (col.key === 'section_id') {
                body.addEventListener('dragover', (e) => { e.preventDefault(); body.classList.add('drop'); });
                body.addEventListener('dragleave', () => body.classList.remove('drop'));
                body.addEventListener('drop', (e) => { e.preventDefault(); body.classList.remove('drop'); const id = e.dataTransfer.getData('text/post'); if (id) act('post_move', { post_id: id, section_id: col.id ?? '' }); });
                body.querySelectorAll('.post-card').forEach((c) => { const p = data.posts.find((x) => x.id == c.dataset.id); if (p && p.can_move) { c.draggable = true; c.addEventListener('dragstart', (e) => e.dataTransfer.setData('text/post', p.id)); } });
            }
            wrap.append(h('section', { class: 'board-column' }, h('h2', { class: 'column-title' }, col.title, h('span', { class: 'badge bg-secondary ms-1' }, items.length)),
                data.me.can.post && col.key === 'section_id' && col.id ? h('button', { type: 'button', class: 'btn btn-sm btn-outline-secondary w-100 mb-2', onclick: () => openComposer(null, { section_id: col.id }) }, icon('bi-plus'), ' เพิ่มในคอลัมน์นี้') : null, body));
        }
        return wrap;
    }

    function timelineView(posts) {
        const sorted = [...posts].sort((a, b) => a.created_at.localeCompare(b.created_at));
        const wrap = h('div', { class: 'board-timeline' });
        let day = '';
        for (const p of sorted) {
            const d = p.created_at.slice(0, 10);
            if (d !== day) { day = d; wrap.append(h('div', { class: 'timeline-day' }, new Date(d + 'T00:00:00+07:00').toLocaleDateString('th-TH', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }))); }
            wrap.append(h('div', { class: 'timeline-item' }, h('span', { class: 'timeline-time' }, p.created_at.slice(11, 16)), card(p)));
        }
        return sorted.length ? wrap : emptyNote();
    }

    function tableView(posts) {
        if (!posts.length) return emptyNote();
        const mode = data.board.reaction_mode;
        return h('div', { class: 'table-responsive bg-white rounded-4 shadow-sm' }, h('table', { class: 'table table-hover align-middle mb-0' },
            h('thead', { class: 'table-light' }, h('tr', {}, h('th', {}, 'ผู้ส่ง'), h('th', {}, 'คำตอบ'), h('th', {}, 'ไฟล์/ลิงก์'), mode !== 'none' ? h('th', {}, mode === 'star' ? 'ดาว' : 'ถูกใจ') : null, h('th', {}, 'เวลา'), h('th', {}, 'สถานะ'), h('th', {}))),
            h('tbody', {}, posts.map((p) => h('tr', { class: selectMode && picked.has(p.id) ? 'picked' : '' },
                h('td', {}, selectMode && p.level && p.status === 'approved' ? pickBox(p) : null, h('div', { class: 'fw-semibold d-inline-block ms-1' }, p.author), p.board_stars ? h('span', { class: 'board-stars ms-1' }, '⭐ ', p.board_stars) : null, p.student_code ? h('small', { class: 'text-muted' }, p.student_code) : null),
                h('td', { style: 'max-width:28rem' }, p.title ? h('div', { class: 'fw-semibold' }, p.title) : null, h('div', { class: 'small text-break table-body' }, p.body)),
                h('td', { class: 'small' }, p.attachments.map((a) => h('div', {}, h('a', { href: a.url + (a.kind === 'document' ? '&download=1' : ''), target: '_blank', rel: 'noopener' }, icon('bi-paperclip'), ' ', a.name))), p.link ? h('a', { href: p.link.url, target: '_blank', rel: 'noopener noreferrer' }, icon('bi-link-45deg'), ' ลิงก์') : null),
                mode !== 'none' ? h('td', {}, mode === 'star' ? (p.reactions.count ? p.reactions.avg : '–') : p.reactions.count) : null,
                h('td', { class: 'small text-nowrap' }, fmtTime(p.created_at)),
                h('td', {}, STATUS[p.status] ? h('span', { class: 'badge bg-' + STATUS[p.status][1] }, STATUS[p.status][0]) : h('span', { class: 'badge bg-success' }, 'เผยแพร่'), p.late ? h('span', { class: 'badge bg-warning text-dark ms-1' }, 'เกินเวลา') : null),
                h('td', {}, menuView(p)))))));
    }

    function freeformView(posts) {
        const canvas = h('div', { class: 'board-freeform', style: 'height:' + Math.max(900, 260 * Math.ceil(posts.length / 4) + 200) + 'px' });
        posts.forEach((p, i) => {
            const c = card(p);
            c.style.left = (p.x ?? 20 + (i % 4) * 300) + 'px';
            c.style.top = (p.y ?? 20 + Math.floor(i / 4) * 280) + 'px';
            if (p.can_move) {
                c.classList.add('movable');
                const grip = h('div', { class: 'drag-grip', title: 'ลากเพื่อย้าย' }, icon('bi-grip-horizontal'));
                c.prepend(grip);
                grip.addEventListener('pointerdown', (e) => {
                    e.preventDefault(); dragging = true; grip.setPointerCapture(e.pointerId);
                    const sx = e.clientX - c.offsetLeft, sy = e.clientY - c.offsetTop;
                    const move = (ev) => { c.style.left = Math.max(0, ev.clientX - sx) + 'px'; c.style.top = Math.max(0, ev.clientY - sy) + 'px'; };
                    const up = () => { grip.removeEventListener('pointermove', move); dragging = false; act('post_move', { post_id: p.id, x: parseInt(c.style.left), y: parseInt(c.style.top) }); };
                    grip.addEventListener('pointermove', move);
                    grip.addEventListener('pointerup', up, { once: true });
                });
            }
            canvas.append(c);
        });
        return posts.length ? h('div', { class: 'freeform-scroll' }, canvas) : emptyNote();
    }

    function loadLeaflet() {
        if (window.L) return Promise.resolve();
        return new Promise((resolve, reject) => {
            document.head.append(h('link', { rel: 'stylesheet', href: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css' }));
            const s = h('script', { src: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js' });
            s.onload = resolve; s.onerror = () => reject(new Error('โหลดแผนที่ไม่ได้'));
            document.head.append(s);
        });
    }

    function mapView(posts) {
        const holder = h('div', {}, h('div', { id: 'board-map', class: 'board-map' }), h('div', { class: 'small text-muted my-2' }, data.me.can.post ? 'คลิกบนแผนที่เพื่อปักหมุดและส่งคำตอบ ณ ตำแหน่งนั้น' : ''));
        const unplaced = posts.filter((p) => p.lat === null);
        if (unplaced.length) holder.append(h('h2', { class: 'h6 mt-3' }, 'ยังไม่ได้ปักหมุด'), renderCards(unplaced, 'board-grid'));
        loadLeaflet().then(() => {
            const el = document.getElementById('board-map');
            if (!el) return;
            if (map) { map.remove(); map = null; }
            map = L.map(el).setView([13.75, 100.5], 6);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(map);
            const bounds = [];
            for (const p of posts.filter((x) => x.lat !== null)) {
                const m = L.marker([p.lat, p.lng], { draggable: p.can_move }).addTo(map);
                m.bindPopup(() => card(p), { maxWidth: 320, minWidth: 240 });
                if (p.can_move) m.on('dragend', () => { const ll = m.getLatLng(); act('post_move', { post_id: p.id, lat: ll.lat.toFixed(6), lng: ll.lng.toFixed(6) }); });
                bounds.push([p.lat, p.lng]);
            }
            if (bounds.length) map.fitBounds(bounds, { padding: [40, 40], maxZoom: 14 });
            if (data.me.can.post) map.on('click', (e) => openComposer(null, { lat: e.latlng.lat.toFixed(6), lng: e.latlng.lng.toFixed(6) }));
        }).catch((e) => holder.prepend(h('div', { class: 'alert alert-warning' }, e.message)));
        return holder;
    }

    /** Word Cloud: คำตอบที่ตรงกัน (ไม่สนตัวพิมพ์/ช่องว่าง) ยิ่งมากยิ่งตัวใหญ่ */
    function cloudView(posts) {
        const groups = new Map();
        for (const p of posts) {
            const text = (p.title || p.body || '').trim().replace(/\s+/g, ' ');
            if (!text) continue;
            const key = text.toLowerCase();
            const g = groups.get(key) || { text, posts: [] };
            g.posts.push(p); groups.set(key, g);
        }
        const list = [...groups.values()].sort((a, b) => b.posts.length - a.posts.length);
        if (!list.length) return emptyNote();
        const max = list[0].posts.length, palette = ['#1d4ed8', '#be123c', '#047857', '#7c3aed', '#c2410c', '#0e7490', '#a16207', '#4338ca'];
        // คำที่มากที่สุดอยู่กลาง
        const ordered = [];
        list.forEach((g, i) => (i % 2 ? ordered.push(g) : ordered.unshift(g)));
        const cloud = h('div', { class: 'word-cloud', role: 'list', 'aria-label': 'คำตอบที่ตอบตรงกันมากแสดงตัวใหญ่' }, ordered.map((g, i) => {
            const n = g.posts.length, size = 15 + Math.round(Math.sqrt(n / max) * 49);
            const names = data.me.can.moderate ? '\nตอบโดย: ' + g.posts.map((p) => p.author).join(', ') : '';
            return h('button', { type: 'button', role: 'listitem', class: 'cloud-word' + (g.posts.some((p) => p.mine) ? ' mine' : ''), style: `font-size:${size}px;color:${palette[i % palette.length]}`,
                title: g.text + ' — ' + n + ' คน' + names, onclick: () => {
                    const students = g.posts.filter((p) => p.level && p.status === 'approved');
                    if (selectMode) { students.forEach((p) => picked.add(p.id)); updateSelectBar(); }
                    cloudDetail.replaceChildren(h('div', { class: 'd-flex flex-wrap align-items-center gap-2 mb-2' }, h('h2', { class: 'h6 mb-0' }, '"' + g.text + '" — ' + n + ' คน'),
                        data.me.can.manage && students.length ? h('button', { type: 'button', class: 'btn btn-sm btn-award', onclick: (e) => awardPicked(students.map((p) => p.id), e.currentTarget) }, icon('bi-stars'), ' มอบดาวให้ทุกคนที่ตอบคำนี้ (' + new Set(students.map((p) => p.user_id)).size + ' คน)') : null),
                        renderCards(g.posts, 'board-grid'));
                } }, g.text, n > 1 ? h('sup', {}, n) : null);
        }));
        const cloudDetail = h('div', { class: 'mt-3' });
        return h('div', {}, h('div', { class: 'small text-muted mb-2' }, 'คำตอบทั้งหมด ' + posts.length + ' คำตอบ · ' + list.length + ' แบบ · คลิกคำเพื่อดูรายละเอียด'), cloud, cloudDetail);
    }

    // ---------- วาดหน้า ----------
    function render() {
        // เก็บข้อความที่พิมพ์ค้างในช่องความคิดเห็นก่อนวาดใหม่
        const typing = {}; let focus = null;
        app.querySelectorAll('input[name^="comment-"]').forEach((i) => { typing[i.name] = i.value; if (i === document.activeElement) focus = i.name; });
        const b = data.board;
        document.querySelectorAll('#layout-switch [data-layout]').forEach((btn) => { btn.classList.toggle('active', btn.dataset.layout === view); btn.setAttribute('aria-pressed', btn.dataset.layout === view); });
        const meta = [h('span', { class: 'badge bg-' + ({ open: 'success', late: 'warning text-dark', upcoming: 'secondary', closed: 'dark' }[b.window]) }, { open: 'เปิดรับคำตอบ', late: 'เลยกำหนดส่ง (ยังส่งได้)', upcoming: 'ยังไม่เปิดรับ', closed: b.status === 'archived' ? 'เก็บถาวร' : 'ปิดรับคำตอบ' }[b.window]),
            ' ', b.window_label, ' · ', data.me.role_label, ' · ', data.posts.length + ' โพสต์'];
        if (data.me.stars) meta.push(' ', h('a', { class: 'star-chip', href: C.levelUrl, title: 'ดาวและ Level ของฉันในรายวิชานี้' }, '⭐ ', data.me.stars.stars, ' · Lv.', data.me.stars.level));
        document.getElementById('board-meta').replaceChildren(...meta.map((m) => (m.nodeType ? m : document.createTextNode(m))));
        const banner = [];
        if (b.hide_until_reveal && !b.revealed) banner.push(h('div', { class: 'alert alert-info py-2 small mt-2 mb-0' }, icon('bi-eye-slash'), data.me.can.moderate ? ' นักเรียนยังมองไม่เห็นคำตอบของเพื่อน (ครูเห็นทั้งหมด) · กด "เปิดให้เห็นคำตอบ" เมื่อพร้อมเฉลย' : ' คุณเห็นเฉพาะคำตอบของตัวเอง คำตอบของเพื่อนจะแสดงเมื่อครูเปิดให้ดู'));
        if (b.require_approval && !data.me.can.moderate) banner.push(h('div', { class: 'alert alert-light border py-2 small mt-2 mb-0' }, icon('bi-shield-check'), ' โพสต์จะแสดงให้เพื่อนเห็นหลังครูอนุมัติ'));
        if (b.group_mode === 'teams' && !data.me.can.moderate) { const t = data.teams.find((x) => x.id === data.me.team_id); banner.push(h('div', { class: 'small mt-2 text-muted' }, icon('bi-people'), t ? ' ทีมของคุณ: ' + t.name : ' คุณยังไม่ได้อยู่ในทีม (แจ้งครูได้)')); }
        document.getElementById('board-banner').replaceChildren(...banner);
        const pendingBtn = document.getElementById('pending-filter');
        if (pendingBtn) { pendingBtn.hidden = !b.require_approval && !b.pending; document.getElementById('pending-count').textContent = b.pending; pendingBtn.classList.toggle('active', pendingOnly); }
        const reveal = document.getElementById('reveal-btn');
        if (reveal) { reveal.hidden = !b.hide_until_reveal; reveal.replaceChildren(icon(b.revealed ? 'bi-eye-slash' : 'bi-eye'), b.revealed ? ' ซ่อนคำตอบของเพื่อนอีกครั้ง' : ' เปิดให้เห็นคำตอบ (เฉลย)'); }
        const tf = document.getElementById('team-filter');
        if (data.teams.length && (data.me.can.moderate || b.team_visibility === 'all')) {
            tf.hidden = false;
            if (tf.options.length !== data.teams.length + 1) tf.replaceChildren(h('option', { value: '' }, 'ทุกทีม'), ...data.teams.map((t) => h('option', { value: t.id }, t.name)));
            tf.value = teamFilter;
        }
        const fab = document.getElementById('fab');
        fab.hidden = !data.me.can.post;
        const myPost = data.posts.find((p) => p.mine);
        fab.querySelector('span').textContent = b.one_answer && myPost && !data.me.can.moderate ? 'แก้คำตอบของฉัน' : view === 'wordcloud' ? 'ตอบคำถาม' : 'ส่งคำตอบ';

        const posts = visiblePosts();
        if (map && view !== 'map') { map.remove(); map = null; }
        const views = { wall: () => renderCards(posts, 'board-wall'), grid: () => renderCards(posts, 'board-grid'), stream: () => renderCards(posts, 'board-stream'), columns: () => columnsView(posts),
            timeline: () => timelineView(posts), table: () => tableView(posts), freeform: () => freeformView(posts), map: () => mapView(posts), wordcloud: () => cloudView(posts) };
        for (const id of [...picked]) if (!data.posts.some((p) => p.id === id)) picked.delete(id);
        app.classList.toggle('select-mode', selectMode);
        const narrow = window.innerWidth < 576 && view === 'freeform';
        app.replaceChildren(narrow ? h('div', {}, h('div', { class: 'small text-muted mb-2' }, icon('bi-phone'), ' จอเล็ก: แสดงแบบเรียงแถวเดียว (ลากวางได้บนจอใหญ่)'), views.stream()) : (views[view] || views.wall)());
        updateSelectBar();
        for (const [name, value] of Object.entries(typing)) { const i = app.querySelector(`input[name="${name}"]`); if (i) { i.value = value; if (focus === name) i.focus(); } }
    }

    async function act(action, fields) {
        busy = true;
        try {
            const res = await api(action, fields);
            if (res.message) toast(res.message);
            await refresh(true);
            return res;
        } catch (e) { toast(e.message, 'danger'); return null; }
        finally { busy = false; }
    }

    // ---------- แผงส่งคำตอบ + อัปโหลด ----------
    const modalEl = document.getElementById('composer'), form = document.getElementById('composer-form');
    const modal = new bootstrap.Modal(modalEl);
    const fileList = document.getElementById('file-list'), errorBox = document.getElementById('composer-error');
    let editing = null, files = [], extra = {}, removed = [];
    const draftKey = () => 'board-draft-' + C.board + '-' + (editing ? editing.id : 'new');

    document.getElementById('file-rules').textContent = 'รูป วิดีโอ เสียง PDF Word Excel PowerPoint · สูงสุด ' + C.maxFiles + ' ไฟล์ ไฟล์ละไม่เกิน ' + fmtSize(C.maxBytes);
    if (window.MediaRecorder && navigator.mediaDevices) document.getElementById('record-btn').hidden = false;

    function showError(msg) { errorBox.textContent = msg; errorBox.classList.toggle('d-none', !msg); if (msg) errorBox.scrollIntoView({ block: 'nearest' }); }

    function saveDraft() {
        const value = { title: form.title.value, body: form.body.value, link_url: form.link_url.value, files: files.filter((f) => f.id).map((f) => ({ id: f.id, name: f.name, kind: f.kind, size: f.size })) };
        try { value.title || value.body || value.link_url || value.files.length ? localStorage.setItem(draftKey(), JSON.stringify(value)) : localStorage.removeItem(draftKey()); } catch (e) {}
    }

    function renderFiles() {
        const kept = editing ? editing.attachments.filter((a) => !removed.includes(a.id)) : [];
        fileList.replaceChildren(...kept.map((a) => h('li', { class: 'file-row' }, icon('bi-paperclip'), h('span', { class: 'flex-grow-1 text-truncate' }, a.name, h('small', { class: 'text-muted' }, ' · ไฟล์เดิม')),
            h('button', { type: 'button', class: 'btn btn-sm btn-link text-danger', onclick: () => { removed.push(a.id); renderFiles(); } }, 'นำออก'))),
        ...files.map((f) => h('li', { class: 'file-row' + (f.error ? ' error' : '') },
            icon({ image: 'bi-image', video: 'bi-camera-video', audio: 'bi-mic', document: 'bi-file-earmark' }[f.kind] || 'bi-file-earmark'),
            h('div', { class: 'flex-grow-1 min-w-0' }, h('div', { class: 'text-truncate' }, f.name, h('small', { class: 'text-muted' }, ' · ' + fmtSize(f.size))),
                f.error ? h('div', { class: 'small text-danger' }, f.error) : h('div', { class: 'progress', style: 'height:6px' }, h('div', { class: 'progress-bar' + (f.id ? ' bg-success' : ' progress-bar-striped progress-bar-animated'), style: 'width:' + (f.id ? 100 : f.progress) + '%' }))),
            f.error && f.file && !f.fatal ? h('button', { type: 'button', class: 'btn btn-sm btn-link', onclick: () => upload(f) }, 'ลองใหม่') : null,
            h('button', { type: 'button', class: 'btn btn-sm btn-link text-danger', 'aria-label': 'ลบไฟล์ ' + f.name, onclick: () => { if (f.xhr) f.xhr.abort(); if (f.id) api('upload_discard', { upload_id: f.id }).catch(() => {}); files = files.filter((x) => x !== f); renderFiles(); saveDraft(); } }, icon('bi-x-lg')))));
        document.getElementById('composer-submit').disabled = files.some((f) => !f.id && !f.error);
    }

    function addFiles(list) {
        showError('');
        const kept = editing ? editing.attachments.filter((a) => !removed.includes(a.id)).length : 0;
        for (const file of list) {
            if (kept + files.length >= C.maxFiles) { showError('แนบได้สูงสุด ' + C.maxFiles + ' ไฟล์ต่อโพสต์'); break; }
            const name = file.name || 'pasted-image.png', kind = kindOf(name);
            const f = { file, name, size: file.size, kind, progress: 0 };
            if (!kind) f.error = 'ไม่รองรับไฟล์ชนิดนี้ (รองรับรูป วิดีโอ เสียง PDF Word Excel PowerPoint zip)';
            else if (file.size > C.maxBytes) f.error = 'ไฟล์ ' + fmtSize(file.size) + ' เกินที่เซิร์ฟเวอร์รับ (' + fmtSize(C.maxBytes) + ')';
            f.fatal = !!f.error;
            files.push(f);
            if (!f.error) upload(f);
        }
        renderFiles();
    }

    function upload(f) {
        f.error = null; f.progress = 0;
        const fd = new FormData();
        fd.append('csrf', C.csrf); fd.append('board_id', C.board); fd.append('action', 'upload'); fd.append('file', f.file, f.name);
        const xhr = new XMLHttpRequest();
        f.xhr = xhr;
        xhr.open('POST', C.api);
        xhr.upload.onprogress = (e) => { if (e.lengthComputable) { f.progress = Math.round(e.loaded * 100 / e.total); const bar = fileList.children[files.indexOf(f) + (editing ? editing.attachments.filter((a) => !removed.includes(a.id)).length : 0)]?.querySelector('.progress-bar'); if (bar) bar.style.width = f.progress + '%'; } };
        xhr.onload = () => {
            let json = null;
            try { json = JSON.parse(xhr.responseText); } catch (e) {}
            if (json && json.ok) { Object.assign(f, json.upload); f.xhr = null; saveDraft(); }
            else f.error = json && json.error ? json.error : (xhr.status === 413 ? 'ไฟล์ใหญ่เกินที่เซิร์ฟเวอร์รับ' : 'อัปโหลดไม่สำเร็จ (' + xhr.status + ')');
            renderFiles();
        };
        xhr.onerror = () => { f.error = 'อัปโหลดไม่สำเร็จ อินเทอร์เน็ตอาจหลุด กด "ลองใหม่"'; renderFiles(); };
        xhr.send(fd);
        renderFiles();
    }

    function openComposer(post = null, preset = {}) {
        if (!post && data.board.one_answer && !data.me.can.moderate) post = data.posts.find((p) => p.mine && p.can_edit) || null;
        editing = post; extra = preset; removed = []; files = [];
        form.reset(); showError('');
        const cloud = view === 'wordcloud' || data.board.layout === 'wordcloud';
        document.getElementById('composer-title').textContent = post ? 'แก้ไขคำตอบ' : cloud ? 'ตอบคำถาม' : 'ส่งคำตอบ / ผลงาน';
        document.getElementById('title-text').textContent = cloud ? 'คำตอบของคุณ (สั้น ๆ คำตอบที่ตรงกับเพื่อนจะตัวใหญ่ขึ้น)' : 'หัวข้อ (ไม่บังคับ)';
        form.title.maxLength = cloud ? 80 : 200;
        ['body-label', 'link-label', 'files-section'].forEach((id) => (document.getElementById(id).hidden = cloud));
        const sel = form.section_id;
        document.getElementById('section-wrap').hidden = !data.sections.length;
        sel.replaceChildren(h('option', { value: '' }, '— ไม่ระบุ —'), ...data.sections.map((s) => h('option', { value: s.id }, s.title)));
        document.getElementById('location-wrap').hidden = view !== 'map' && data.board.layout !== 'map';
        document.getElementById('location-text').textContent = preset.lat ? preset.lat + ', ' + preset.lng : (post && post.lat !== null ? post.lat + ', ' + post.lng : 'ยังไม่ได้เลือก (คลิกบนแผนที่ หรือใช้ตำแหน่งปัจจุบัน)');
        if (post) { form.title.value = post.title; form.body.value = post.body; form.link_url.value = post.link ? post.link.url : ''; sel.value = post.section_id || ''; }
        if (preset.section_id) sel.value = preset.section_id;
        let draft = null;
        try { draft = JSON.parse(localStorage.getItem(draftKey()) || 'null'); } catch (e) {}
        document.getElementById('draft-note').classList.toggle('d-none', !draft);
        if (draft) { form.title.value = draft.title; form.body.value = draft.body; form.link_url.value = draft.link_url; files = (draft.files || []).map((f) => ({ ...f, progress: 100 })); }
        const b = data.board;
        document.getElementById('composer-hint').textContent = b.window === 'late' ? 'เลยกำหนดส่งแล้ว ส่งได้แต่จะติดสถานะส่งเกินเวลา' : b.require_approval && !data.me.can.moderate ? 'ครูจะอนุมัติก่อนแสดงให้เพื่อนเห็น' : b.hide_until_reveal && !b.revealed ? 'เพื่อนจะยังไม่เห็นคำตอบจนกว่าครูจะเปิด' : '';
        renderFiles();
        modal.show();
        setTimeout(() => (cloud ? form.title : form.body.value || !form.title.value ? form.body : form.title).focus(), 300);
    }

    form.addEventListener('input', saveDraft);
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (files.some((f) => !f.id && !f.error)) { showError('กรุณารอให้อัปโหลดไฟล์เสร็จก่อน'); return; }
        const failed = files.filter((f) => f.error && !f.fatal);
        if (failed.length && !confirm('มีไฟล์ที่อัปโหลดไม่สำเร็จ ' + failed.length + ' ไฟล์ จะส่งโดยไม่มีไฟล์เหล่านี้หรือไม่?')) return;
        const btn = document.getElementById('composer-submit');
        btn.disabled = true; showError('');
        try {
            const res = await api('post_save', { post_id: editing ? editing.id : '', title: form.title.value, body: form.body.value, link_url: form.link_url.value, section_id: form.section_id.value,
                uploads: files.filter((f) => f.id).map((f) => f.id), remove_attachments: removed, lat: extra.lat || '', lng: extra.lng || '' });
            try { localStorage.removeItem(draftKey()); } catch (err) {}
            files = [];
            modal.hide();
            toast(res.message || 'ส่งแล้ว');
            await refresh(true);
            const mine = app.querySelector('.post-card[data-id="' + res.post_id + '"]');
            if (mine) { mine.classList.add('flash'); mine.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
        } catch (err) {
            showError(err.message);
            if (/หมดอายุ/.test(err.message)) { files = files.filter((f) => !f.id); renderFiles(); saveDraft(); }
        } finally { btn.disabled = false; }
    });

    const zone = document.getElementById('drop-zone'), input = document.getElementById('file-input');
    zone.addEventListener('click', () => input.click());
    zone.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); } });
    input.addEventListener('change', () => { addFiles([...input.files]); input.value = ''; });
    ['camera-input', 'video-input'].forEach((id) => document.getElementById(id)?.addEventListener('change', (e) => { addFiles([...e.target.files]); e.target.value = ''; }));
    ['dragenter', 'dragover'].forEach((ev) => modalEl.addEventListener(ev, (e) => { if ([...e.dataTransfer.types].includes('Files')) { e.preventDefault(); zone.classList.add('over'); } }));
    ['dragleave', 'drop'].forEach((ev) => modalEl.addEventListener(ev, () => zone.classList.remove('over')));
    modalEl.addEventListener('drop', (e) => { if (e.dataTransfer.files.length) { e.preventDefault(); addFiles([...e.dataTransfer.files]); } });
    modalEl.addEventListener('paste', (e) => {
        const pasted = [...(e.clipboardData?.files || [])];
        if (pasted.length) { e.preventDefault(); addFiles(pasted.map((f, i) => f.name && f.name !== 'image.png' ? f : new File([f], 'pasted-' + Date.now() + '-' + i + '.' + (f.type.split('/')[1] || 'png'), { type: f.type }))); }
    });

    // อัดเสียงในเบราว์เซอร์
    let recorder = null;
    document.getElementById('record-btn').addEventListener('click', async (e) => {
        const btn = e.currentTarget;
        if (recorder) { recorder.stop(); return; }
        try {
            const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            const type = MediaRecorder.isTypeSupported('audio/webm') ? 'audio/webm' : 'audio/mp4';
            recorder = new MediaRecorder(stream, { mimeType: type });
            const chunks = [];
            recorder.ondataavailable = (ev) => chunks.push(ev.data);
            recorder.onstop = () => {
                stream.getTracks().forEach((t) => t.stop());
                addFiles([new File(chunks, 'recording-' + new Date().toISOString().slice(0, 19).replace(/[:T]/g, '') + (type === 'audio/webm' ? '.weba' : '.m4a'), { type })]);
                recorder = null; btn.replaceChildren(icon('bi-mic'), ' อัดเสียง'); btn.classList.remove('btn-danger');
            };
            recorder.start();
            btn.replaceChildren(icon('bi-stop-circle'), ' หยุดอัด'); btn.classList.add('btn-danger');
        } catch (err) { showError('ใช้ไมโครโฟนไม่ได้: กรุณาอนุญาตการใช้ไมโครโฟนในเบราว์เซอร์'); }
    });
    document.getElementById('use-location').addEventListener('click', () => navigator.geolocation?.getCurrentPosition(
        (pos) => { extra.lat = pos.coords.latitude.toFixed(6); extra.lng = pos.coords.longitude.toFixed(6); document.getElementById('location-text').textContent = extra.lat + ', ' + extra.lng; },
        () => showError('ไม่ได้รับอนุญาตให้ใช้ตำแหน่ง')));

    // ดูรูป/PDF เต็มจอ
    const viewer = new bootstrap.Modal(document.getElementById('viewer'));
    function openViewer(a) {
        document.getElementById('viewer-name').textContent = a.name;
        document.getElementById('viewer-download').href = a.url + '&download=1';
        document.getElementById('viewer-body').replaceChildren(a.kind === 'image' ? h('img', { src: a.url, alt: a.name, class: 'img-fluid viewer-img' }) : h('iframe', { src: a.url, title: a.name, class: 'viewer-frame' }));
        viewer.show();
    }

    // ---------- แถบเครื่องมือ ----------
    document.querySelectorAll('#layout-switch [data-layout]').forEach((btn) => btn.addEventListener('click', () => {
        view = btn.dataset.layout;
        try { localStorage.setItem('board-view-' + C.board, view); } catch (e) {}
        render();
    }));
    document.getElementById('team-filter').addEventListener('change', (e) => { teamFilter = e.target.value; render(); });
    document.getElementById('pending-filter')?.addEventListener('click', () => { pendingOnly = !pendingOnly; render(); });
    document.getElementById('reveal-btn')?.addEventListener('click', () => act(data.board.revealed ? 'hide' : 'reveal', {}));
    document.querySelectorAll('[data-status]').forEach((btn) => btn.addEventListener('click', () => act('set_status', { status: btn.dataset.status })));
    document.getElementById('fab').addEventListener('click', () => openComposer());
    document.getElementById('select-mode')?.addEventListener('click', () => { selectMode = !selectMode; if (!selectMode) picked.clear(); render(); });
    document.getElementById('select-cancel')?.addEventListener('click', () => { selectMode = false; picked.clear(); render(); });
    document.getElementById('select-all')?.addEventListener('click', () => {
        const all = pickablePosts();
        if (all.every((p) => picked.has(p.id))) all.forEach((p) => picked.delete(p.id)); else all.forEach((p) => picked.add(p.id));
        render();
    });
    document.getElementById('select-award')?.addEventListener('click', (e) => awardPicked([...picked], e.currentTarget));
    document.getElementById('award-all')?.addEventListener('click', (e) => {
        const n = Number(document.getElementById('select-amount')?.value || 1);
        if (confirm('มอบ ' + n + ' ดาวให้นักเรียนทุกคนที่ตอบบนกระดานนี้? (เปลี่ยนจำนวนดาวได้ที่ปุ่ม "เลือกให้ดาวหลายคน")')) { starBurst(e.currentTarget); act('award_star', { all: 1, amount: n }); }
    });

    refresh(true);
    setInterval(() => { if (!document.hidden && !modalEl.classList.contains('show')) refresh(); }, 4000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
})();
