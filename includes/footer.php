<footer class="bg-dark text-white-50 py-4 mt-5">
    <div class="container d-flex flex-wrap justify-content-between gap-2 small">
        <div><i class="bi bi-mortarboard-fill text-warning"></i> <b class="text-white"><?= e(setting('site_name', 'Vocational Teacher')) ?></b> — <?= e(setting('site_slogan', 'ครูสมควรออนไลน์')) ?></div>
        <div>ระบบจัดการเรียนการสอนออนไลน์สำหรับครูอาชีวศึกษา &copy; <?= date('Y') + 543 ?>
            · <a href="<?= url('manual.php') ?>" class="link-light">คู่มือการใช้งาน</a>
            · <a href="<?= url('privacy.php') ?>" class="link-light">คุ้มครองข้อมูลส่วนบุคคล (PDPA)</a>
            · <span class="badge bg-secondary" title="เผยแพร่ <?= thai_date(APP_RELEASE_DATE) ?>">v<?= e(APP_VERSION) ?></span></div>
    </div>
</footer>

<!-- แจ้งการใช้คุกกี้ (PDPA): ระบบใช้เฉพาะคุกกี้ที่จำเป็น -->
<div id="cookieNotice" class="position-fixed bottom-0 start-0 end-0 p-3 d-none" style="z-index:1080">
    <div class="container">
        <div class="bg-white shadow-lg rounded-4 p-3 d-flex flex-wrap align-items-center gap-3 border">
            <i class="bi bi-cookie fs-3 text-warning"></i>
            <div class="flex-grow-1 small" style="min-width:220px">
                เว็บไซต์นี้ใช้เฉพาะ<b>คุกกี้ที่จำเป็น</b>ต่อการเข้าสู่ระบบและการใช้งาน ไม่ใช้คุกกี้เพื่อการโฆษณาหรือติดตามพฤติกรรม
                <a href="<?= url('privacy.php') ?>">อ่านนโยบายความเป็นส่วนตัว</a>
            </div>
            <button type="button" class="btn btn-vt btn-sm px-4" id="cookieOk">รับทราบ</button>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    (function () {
        var key = 'vt_cookie_notice', box = document.getElementById('cookieNotice');
        var seen = false;
        try { seen = localStorage.getItem(key) === '1'; } catch (e) {}
        if (!seen) box.classList.remove('d-none');
        document.getElementById('cookieOk').addEventListener('click', function () {
            try { localStorage.setItem(key, '1'); } catch (e) {}
            box.classList.add('d-none');
        });
    })();
</script>
</body>
</html>
