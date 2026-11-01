(() => {
    let builderId = 0;
    const letters = ['ก', 'ข', 'ค', 'ง', 'จ', 'ฉ', 'ช', 'ซ', 'ฌ', 'ญ'];
    function init(builder) {
        if (builder.dataset.ready) return;
        builder.dataset.ready = '1';
        const radioPrefix = 'quiz_correct_' + (++builderId) + '_';
        const hidden = builder.querySelector('.quiz-data'), list = builder.querySelector('.quiz-questions');
        const $ = selector => builder.querySelector(selector), $$ = selector => [...builder.querySelectorAll(selector)];
        let data;
        try { data = JSON.parse(hidden.value); } catch (_) { data = []; }
        // รูปแบบเดิมเป็นรายการข้อ, เมื่อมีการตั้งค่าเก็บเป็น {questions, shuffle, source, categories, count, kind, pair, max_attempts}
        const config = Array.isArray(data) ? {questions: data} : (data && typeof data === 'object' ? data : {});
        let questions = Array.isArray(config.questions) && config.questions.length ? config.questions : [{text: '', options: ['', '', '', ''], correct: 0}];
        const sources = $$('.quiz-source');
        sources.forEach(radio => { radio.name = radioPrefix + 'source'; radio.checked = radio.value === (config.source === 'bank' ? 'bank' : 'manual'); });
        $('.quiz-kind').value = config.kind || '';
        $('.quiz-pair').value = config.pair || '';
        $('.quiz-max').value = Number.isInteger(config.max_attempts) ? config.max_attempts : 0;
        $('.quiz-shuffle').checked = !!config.shuffle;
        $('.quiz-opens').value = config.opens_at || '';
        $('.quiz-closes').value = config.closes_at || '';
        const cats = Array.isArray(config.categories) ? config.categories : [];
        $$('.quiz-cat').forEach(box => box.checked = cats.includes(box.value));
        if ($('.quiz-bank-count') && config.count) $('.quiz-bank-count').value = config.count;
        // แบบทดสอบหลังเรียนที่ยังไม่เลือกคู่ และมีแบบทดสอบก่อนเรียนเพียงชุดเดียว → เลือกให้อัตโนมัติ
        const pairOptions = $$('.quiz-pair option').filter(option => option.value);
        const source = () => sources.find(radio => radio.checked)?.value || 'manual';
        function save() {
            const kind = $('.quiz-kind').value, prepost = kind === 'pre' || kind === 'post';
            // ก่อน–หลังเรียนสอบได้ 1 ครั้งเสมอ
            if (prepost) $('.quiz-max').value = 1;
            $('.quiz-max').disabled = prepost;
            $('.quiz-max-note').textContent = prepost ? 'ก่อน–หลังเรียนสอบได้ 1 ครั้ง' : '0 = ไม่จำกัด';
            const max = Math.max(0, Math.min(100, parseInt($('.quiz-max').value, 10) || 0));
            if (kind === 'post' && !$('.quiz-pair').value && pairOptions.length === 1) $('.quiz-pair').value = pairOptions[0].value;
            $('.quiz-pair-box').hidden = kind !== 'post';
            const isBank = source() === 'bank';
            $('.quiz-bank-box').hidden = !isBank;
            $('.quiz-manual-box').hidden = isBank;
            const out = {questions, shuffle: $('.quiz-shuffle').checked, source: source(), categories: $$('.quiz-cat').filter(box => box.checked).map(box => box.value),
                count: parseInt($('.quiz-bank-count')?.value, 10) || 0, kind, pair: kind === 'post' ? $('.quiz-pair').value : '', max_attempts: max,
                opens_at: $('.quiz-opens').value, closes_at: $('.quiz-closes').value};
            if (isBank) {
                out.questions = [];
                const checked = $$('.quiz-cat').filter(box => box.checked), pool = (checked.length ? checked : $$('.quiz-cat')).reduce((sum, box) => sum + Number(box.dataset.count), 0);
                const info = $('.quiz-bank-info');
                if (info) { info.textContent = 'มีข้อในหมวดที่เลือก ' + pool + ' ข้อ' + (out.count > pool ? ' — น้อยกว่าจำนวนที่ต้องการสุ่ม' : ''); info.classList.toggle('text-danger', out.count > pool); }
            }
            hidden.value = JSON.stringify(out.source === 'manual' && !out.shuffle && !out.kind && !out.max_attempts && !out.opens_at && !out.closes_at ? questions : out);
        }
        // เปลี่ยนแบบทดสอบก่อนเรียนที่บันทึกแล้วเป็นประเภทอื่น = แก้ชุดเดิม ไม่ได้สร้างชุดใหม่
        $('.quiz-kind').addEventListener('change', event => {
            if (builder.dataset.savedKind !== 'pre' || event.target.value === 'pre') return;
            if (!confirm('แบบทดสอบนี้คือแบบทดสอบก่อนเรียนที่บันทึกไว้แล้ว ถ้าเปลี่ยนประเภทแล้วบันทึก ชุดนี้จะไม่ใช่แบบทดสอบก่อนเรียนอีกต่อไป (ไม่ได้สร้างชุดใหม่เพิ่ม)\n\n'
                + 'ถ้าต้องการแบบทดสอบหลังเรียนอีกชุด ให้กด "ยกเลิก" แล้วใช้ลิงก์ "เพิ่มเป็นสื่อใหม่"\n\nยืนยันเปลี่ยนประเภทชุดนี้?')) {
                event.target.value = 'pre';
                event.stopImmediatePropagation();
            }
        });
        builder.addEventListener('change', event => { if (!event.target.closest('.quiz-questions')) save(); });
        builder.addEventListener('input', event => { if (event.target.matches('.quiz-max, .quiz-bank-count')) save(); });
        function render() {
            list.replaceChildren();
            $('.quiz-count').value = questions.length;
            questions.forEach((q, i) => {
                const card = document.createElement('fieldset'); card.className = 'border rounded p-3 mb-3';
                const legend = document.createElement('legend'); legend.className = 'fs-6'; legend.textContent = 'ข้อที่ ' + (i + 1); card.append(legend);
                const text = document.createElement('textarea'); text.className = 'form-control mb-2'; text.placeholder = 'โจทย์'; text.setAttribute('aria-label', 'โจทย์ข้อที่ ' + (i + 1)); text.maxLength = 2000; text.value = q.text;
                text.oninput = () => { q.text = text.value; save(); }; card.append(text);
                q.options.forEach((option, j) => {
                    const label = document.createElement('label'); label.className = 'd-flex align-items-center gap-2 mb-2';
                    const radio = document.createElement('input'); radio.type = 'radio'; radio.name = radioPrefix + i; radio.checked = q.correct === j; radio.setAttribute('aria-label', 'เฉลยตัวเลือก ' + letters[j]);
                    radio.onchange = () => { q.correct = j; save(); };
                    const input = document.createElement('input'); input.className = 'form-control'; input.maxLength = 1000; input.placeholder = 'ตัวเลือก ' + letters[j]; input.value = option;
                    input.oninput = () => { q.options[j] = input.value; save(); };
                    const letter = document.createElement('span'); letter.textContent = letters[j] + '.';
                    label.append(radio, letter, input); card.append(label);
                });
                for (const [label, delta] of [['เพิ่มตัวเลือก', 1], ['ลดตัวเลือก', -1]]) {
                    const button = document.createElement('button'); button.type = 'button'; button.className = 'btn btn-sm btn-outline-secondary me-2'; button.textContent = label;
                    button.disabled = delta === 1 ? q.options.length >= 10 : q.options.length <= 2;
                    button.onclick = () => { if (delta === 1) q.options.push(''); else q.options.pop(); q.correct = Math.min(q.correct, q.options.length - 1); render(); }; card.append(button);
                }
                list.append(card);
            });
            save();
        }
        $$('.quiz-import-mode').forEach(radio => radio.name = radioPrefix + 'import');
        $('.quiz-import-run').onclick = async () => {
            const msg = $('.quiz-import-msg'), body = new FormData(), file = $('.quiz-import-file').files[0];
            const csrf = builder.closest('form')?.querySelector('[name=csrf]')?.value || '';
            body.append('csrf', csrf);
            if (file) body.append('file', file); else body.append('text', $('.quiz-import-text').value);
            if (!file && !$('.quiz-import-text').value.trim()) { msg.className = 'small mt-2 quiz-import-msg text-danger'; msg.textContent = 'วางข้อความหรือเลือกไฟล์ก่อน'; return; }
            msg.className = 'small mt-2 quiz-import-msg text-muted'; msg.textContent = 'กำลังนำเข้า…';
            try {
                const result = await (await fetch(builder.dataset.importUrl, {method: 'POST', body, credentials: 'same-origin'})).json();
                if (result.error) throw new Error(result.error);
                const append = $$('.quiz-import-mode').find(radio => radio.checked)?.value === 'append';
                const keep = append ? questions.filter(q => q.text.trim() || q.options.some(option => option.trim())) : [];
                if (keep.length + result.questions.length > 100) throw new Error('แบบทดสอบหนึ่งชุดมีได้ไม่เกิน 100 ข้อ (รวมแล้ว ' + (keep.length + result.questions.length) + ' ข้อ)');
                questions = keep.concat(result.questions);
                sources.forEach(radio => radio.checked = radio.value === 'manual');
                render();
                $('.quiz-import-text').value = ''; $('.quiz-import-file').value = '';
                msg.className = 'small mt-2 quiz-import-msg text-success'; msg.textContent = 'นำเข้า ' + result.questions.length + ' ข้อแล้ว ตรวจสอบแล้วกดบันทึกบทเรียน';
            } catch (error) {
                msg.className = 'small mt-2 quiz-import-msg text-danger'; msg.textContent = error.message || 'นำเข้าไม่สำเร็จ';
            }
        };
        $('.quiz-resize').onclick = () => {
            const count = Number($('.quiz-count').value);
            if (!Number.isInteger(count) || count < 1 || count > 100) return alert('กำหนดจำนวนข้อระหว่าง 1–100');
            if (count < questions.length && !confirm('ลดจำนวนข้อจะลบข้อท้ายที่เกินจำนวน ยืนยันหรือไม่?')) return;
            while (questions.length < count) questions.push({text: '', options: ['', '', '', ''], correct: 0});
            questions.length = count; render();
        };
        render();
    }
    window.initQuizBuilders = () => document.querySelectorAll('.quiz-builder').forEach(init);
    window.initQuizBuilders();
})();
