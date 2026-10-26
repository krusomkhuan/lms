<?php
require_once __DIR__ . '/includes/bootstrap.php';

$pageTitle = 'คุ้มครองข้อมูลส่วนบุคคล (PDPA)';
$privacyUser = current_user();
require __DIR__ . '/includes/header.php';
?>
<style>
    .privacy-page { overflow-wrap: anywhere; }
    .privacy-page section { scroll-margin-top: 90px; }
    @media print {
        header, nav, footer, #cookieNotice, .privacy-actions { display: none !important; }
        .privacy-page { max-width: none !important; padding: 0 !important; }
        .privacy-page .card { box-shadow: none !important; }
    }
</style>
<main class="container py-4 privacy-page" style="max-width:880px">
    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-body p-4 p-lg-5">
            <h1 class="h2 mb-2"><i class="bi bi-shield-lock text-vt" aria-hidden="true"></i> คุ้มครองข้อมูลส่วนบุคคล (PDPA)</h1>
            <p class="text-muted">ข้อมูลเกี่ยวกับการใช้ข้อมูลส่วนบุคคลในระบบ <?= e(setting('site_name', 'Vocational Teacher')) ?> และช่องทางจัดการข้อมูลของท่าน</p>
            <div class="d-flex flex-wrap gap-2 mb-4 privacy-actions" aria-label="หัวข้อในหน้านี้">
                <a class="btn btn-outline-primary btn-sm" href="#policy">อ่านนโยบาย</a>
                <a class="btn btn-outline-primary btn-sm" href="#learning-data">ข้อมูลกลุ่มเรียน</a>
                <a class="btn btn-outline-primary btn-sm" href="#data-rights">จัดการข้อมูลของฉัน</a>
                <a class="btn btn-outline-primary btn-sm" href="#privacy-contact">ติดต่อผู้ดูแล</a>
                <button type="button" class="btn btn-light btn-sm" onclick="window.print()">พิมพ์ / บันทึก PDF</button>
            </div>
            <section id="policy" aria-labelledby="policy-title">
                <h2 id="policy-title" class="h4">นโยบายความเป็นส่วนตัว</h2>
                <p class="text-muted small mb-4">เวอร์ชัน <?= e(privacy_version()) ?><?php if (setting('privacy_updated')): ?> · ปรับปรุงล่าสุด <?= thai_date(setting('privacy_updated')) ?><?php endif; ?></p>
                <div class="lesson-content"><?= privacy_policy_html() ?></div>
            </section>
            <section id="learning-data" class="mt-5" aria-labelledby="learning-data-title">
                <h2 id="learning-data-title" class="h4">ข้อมูลกลุ่มเรียนและสิทธิ์เข้ารายวิชา</h2>
                <p>ระบบบันทึกกลุ่มเรียนที่ท่านเลือกตอนสมัครสมาชิก เพื่อเชื่อมโยงกับรายวิชาที่ครูอนุมัติให้กลุ่มเข้าเรียน กลุ่มหนึ่งสามารถได้รับสิทธิ์หลายรายวิชา และระบบบันทึกความก้าวหน้าการเรียนแยกตามผู้เรียนและบทเรียน</p>
                <p>หากเลือกกลุ่มผิด กรุณาติดต่อครูหรือผู้ดูแลระบบเพื่อแก้ไข การนำสมาชิกออกจากกลุ่มหรือถอนสิทธิ์ของกลุ่มอาจทำให้เข้าเรียนบางรายวิชาไม่ได้ การลบกลุ่มไม่ใช่การลบบัญชีผู้เรียน</p>
            </section>
            <section id="data-rights" class="mt-5" aria-labelledby="data-rights-title">
                <h2 id="data-rights-title" class="h4">จัดการข้อมูลส่วนบุคคลของฉัน</h2>
                <p>ท่านสามารถแก้ไขข้อมูลส่วนตัว ดาวน์โหลดสำเนาข้อมูลเป็นไฟล์ JSON และยื่นคำร้องเกี่ยวกับข้อมูลส่วนบุคคลได้จากหน้าข้อมูลส่วนตัว รวมถึงติดตามสถานะคำร้องและถอนความยินยอม</p>
                <p class="small text-muted">การถอนความยินยอมในระบบจะทำให้ออกจากระบบ และระบบจะขอให้ยอมรับนโยบายอีกครั้งเมื่อกลับมาใช้งาน หากเข้าใช้บัญชีไม่ได้ โปรดใช้ช่องทางติดต่อด้านล่าง</p>
                <div class="d-flex flex-wrap gap-2 privacy-actions">
                    <?php if ($privacyUser): ?>
                        <a class="btn btn-vt" href="<?= url('profile.php#pdpa') ?>">ข้อมูลส่วนตัว / ยื่นคำร้อง</a>
                        <a class="btn btn-outline-primary" href="<?= url('my-data.php') ?>">ดาวน์โหลดข้อมูลของฉัน</a>
                    <?php else: ?>
                        <a class="btn btn-vt" href="<?= url('login.php?next=' . urlencode(url('profile.php'))) ?>">เข้าสู่ระบบเพื่อจัดการข้อมูล</a>
                    <?php endif; ?>
                </div>
            </section>
            <section id="privacy-contact" class="mt-5" aria-labelledby="privacy-contact-title">
                <h2 id="privacy-contact-title" class="h4">ติดต่อเรื่องข้อมูลส่วนบุคคล</h2>
                <p class="mb-1"><strong><?= e(pdpa_org_name()) ?></strong></p>
                <p><?= nl2br(e(pdpa_contact())) ?></p>
            </section>
        </div>
    </div>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
