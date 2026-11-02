(() => {
    const course = document.getElementById('dashboard-course');
    const group = document.getElementById('dashboard-group');
    if (course && group) course.addEventListener('change', () => {
        // Groups belong to one course; reset before submitting a course change.
        group.value = '0';
        course.form.requestSubmit();
    });
    const text = document.getElementById('attendance-share-text');
    const link = document.getElementById('attendance-line-share');
    const copy = document.getElementById('attendance-copy-summary');
    const feedback = document.getElementById('attendance-share-feedback');
    if (!text || !link || !copy || !feedback) return;
    const update = () => {
        const value = text.value.trim();
        link.href = 'https://line.me/R/share?text=' + encodeURIComponent(value);
        link.setAttribute('aria-disabled', value ? 'false' : 'true');
        copy.disabled = !value;
    };
    text.addEventListener('input', update);
    link.addEventListener('click', event => {
        if (!text.value.trim()) {
            event.preventDefault();
            feedback.textContent = 'กรุณากรอกข้อความก่อนแชร์';
            text.focus();
        }
    });
    copy.addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(text.value.trim());
            feedback.textContent = 'คัดลอกแล้ว เปิด LINE แล้ววางข้อความในกลุ่มได้เลย';
        } catch {
            text.focus(); text.select();
            feedback.textContent = 'เลือกข้อความให้แล้ว กรุณากดคัดลอก หรือ Ctrl+C แล้วนำไปวางใน LINE';
        }
    });
    update();
})();
