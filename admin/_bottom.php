        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= url('assets/js/password-toggle.js') ?>?v=<?= (int)@filemtime(ROOT_PATH . '/assets/js/password-toggle.js') ?>"></script>
<script>
    // ปุ่ม/ฟอร์มที่มี data-confirm จะถามยืนยันก่อน
    document.addEventListener('submit', function (ev) {
        var msg = ev.target.getAttribute('data-confirm');
        if (msg && !confirm(msg)) ev.preventDefault();
    });
</script>
</body>
</html>
