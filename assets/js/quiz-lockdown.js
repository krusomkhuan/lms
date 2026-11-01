// ป้องกันการออกจากหน้าสอบ: นับทุกครั้งที่นักเรียนสลับแท็บ/แอป/หน้าต่าง เตือนเต็มจอ และส่งคำตอบอัตโนมัติเมื่อครบกำหนด
// เบราว์เซอร์ไม่ยอมให้เว็บห้ามเปิดแท็บใหม่ได้จริง จึงตรวจจับการออกจากหน้าแทน (นับฝั่งเซิร์ฟเวอร์ รีโหลดแล้วไม่ล้าง)
(() => {
    const form = document.querySelector('form[data-lockdown]');
    if (!form) return;
    const limit = Number(form.dataset.limit), overlay = document.getElementById('quizLockOverlay');
    let leaves = Number(form.dataset.leaves), away = false, submitting = false, confirming = false;

    const autoSubmit = () => {
        if (submitting) return;
        submitting = true;
        show('ออกจากหน้าสอบครบ ' + limit + ' ครั้ง', 'ระบบกำลังส่งคำตอบอัตโนมัติ ข้อที่ยังไม่ได้ตอบได้ 0 คะแนน', false);
        form.onsubmit = null;
        form.submit();
    };
    function show(title, text, canClose) {
        overlay.querySelector('.lock-title').textContent = title;
        overlay.querySelector('.lock-text').textContent = text;
        overlay.querySelector('.lock-close').hidden = !canClose;
        overlay.hidden = false;
        if (canClose) overlay.querySelector('.lock-close').focus();
    }
    async function report() {
        if (submitting) return;
        try {
            const body = new FormData();
            body.append('csrf', form.querySelector('[name=csrf]').value);
            body.append('action', 'quiz_leave');
            const result = await (await fetch(location.href, {method: 'POST', body, credentials: 'same-origin'})).json();
            if (result.ok) leaves = result.leaves;
        } catch (_) {
            leaves++; // เครือข่ายขัดข้อง: นับในเครื่องต่อไป
        }
        if (leaves >= limit) return autoSubmit();
        show('คำเตือนครั้งที่ ' + leaves + '/' + (limit - 1), 'ห้ามเปิดแท็บใหม่ สลับหน้าจอ หรือออกจากหน้าสอบ ระหว่างทำแบบทดสอบ หากออกจากหน้าสอบครั้งที่ ' + limit + ' ระบบจะส่งคำตอบทันที', true);
    }
    // นับหนึ่งครั้งต่อการออกไปหนึ่งรอบ (เปลี่ยนแท็บ/ย่อหน้าต่าง หรือไปใช้หน้าต่าง/แอปอื่น)
    const leave = () => { if (!away && !submitting && !confirming) { away = true; report(); } };
    // กล่องยืนยันการส่งทำให้หน้าเสียโฟกัส ไม่นับเป็นการออกจากหน้าสอบ
    form.addEventListener('submit', event => {
        confirming = true;
        const ok = confirm('ยืนยันส่งคำตอบ? ส่งแล้วสอบซ้ำไม่ได้');
        setTimeout(() => { confirming = false; away = false; }, 600);
        if (ok) submitting = true; else event.preventDefault();
    });
    document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'hidden') leave(); else away = false; });
    window.addEventListener('blur', () => setTimeout(() => {
        if (document.visibilityState === 'visible' && !document.hasFocus() && document.activeElement?.tagName !== 'IFRAME') leave();
    }, 400));
    window.addEventListener('focus', () => { away = false; });
    overlay.querySelector('.lock-close').addEventListener('click', () => { overlay.hidden = true; });
    // ลดการคัดลอกโจทย์ไปค้นหา
    ['copy', 'cut', 'contextmenu'].forEach(type => form.addEventListener(type, event => event.preventDefault()));
    if (leaves >= limit) autoSubmit();
})();
