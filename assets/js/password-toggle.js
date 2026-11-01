// ปุ่มรูปตาแสดง/ซ่อนรหัสผ่าน ใส่ให้ทุกช่อง type="password" อัตโนมัติ
(() => {
    document.querySelectorAll('input[type="password"]').forEach(input => {
        if (input.closest('.password-toggle-wrap')) return;
        const wrap = document.createElement('div');
        wrap.className = 'password-toggle-wrap';
        input.parentNode.insertBefore(wrap, input);
        wrap.append(input);
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'password-toggle';
        button.setAttribute('aria-label', 'แสดงรหัสผ่าน');
        button.setAttribute('aria-pressed', 'false');
        button.innerHTML = '<i class="bi bi-eye"></i>';
        button.addEventListener('click', () => {
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            button.innerHTML = '<i class="bi ' + (show ? 'bi-eye-slash' : 'bi-eye') + '"></i>';
            button.setAttribute('aria-label', show ? 'ซ่อนรหัสผ่าน' : 'แสดงรหัสผ่าน');
            button.setAttribute('aria-pressed', String(show));
            input.focus();
        });
        wrap.append(button);
        // ส่งฟอร์มขณะแสดงรหัสผ่าน: เปลี่ยนกลับเป็นซ่อนก่อน เพื่อไม่ให้เบราว์เซอร์จำเป็นข้อความธรรมดา
        input.form?.addEventListener('submit', () => { input.type = 'password'; });
    });
})();
