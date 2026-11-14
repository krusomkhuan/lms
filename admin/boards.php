<?php
// หลังบ้าน: รายการกระดานกิจกรรม (เลือกรายวิชา → กระดานในรายวิชา) เพิ่ม คัดลอก เปลี่ยนสถานะ ลบ
require_once __DIR__ . '/../includes/bootstrap.php';
$me = require_role('admin', 'teacher');
$pageTitle = 'กระดานกิจกรรม';
if (!boards_ready()) {
    require __DIR__ . '/_top.php';
    echo '<div class="alert alert-warning">กรุณารัน database/migrations/1.17.0.sql และ 1.17.1.sql ใน phpMyAdmin ก่อนใช้กระดานกิจกรรม</div>';
    require __DIR__ . '/_bottom.php';
    exit;
}
$courseId = get_int('course_id');
if (!$courseId) {
    $courses = staff_courses($me);
    $counts = $courses ? array_column(db_all('SELECT course_id, COUNT(*) AS n FROM boards WHERE course_id IN (' . implode(',', array_map(fn($c) => (int)$c['id'], $courses)) . ") AND status <> 'archived' GROUP BY course_id"), 'n', 'course_id') : [];
    require __DIR__ . '/_top.php'; ?>
    <p class="text-muted">กระดานกิจกรรมสำหรับ Active Learning: ถาม–ตอบ ระดมสมอง ส่งใบงาน/ผลงาน แสดงความคิดเห็น ให้คะแนนเข้าสมุดคะแนน และสะสม Level ของผู้เรียน · เลือกรายวิชา</p>
    <div class="card border-0 shadow-sm rounded-4"><div class="table-responsive"><table class="table table-hover mb-0">
        <thead class="table-light"><tr><th>รหัสวิชา</th><th>รายวิชา</th><th class="text-center">กระดาน</th><th class="text-end">จัดการ</th></tr></thead>
        <tbody>
        <?php foreach ($courses as $c): ?>
            <tr><td><?= e($c['code']) ?></td><td><?= e($c['title']) ?></td><td class="text-center"><?= (int)($counts[$c['id']] ?? 0) ?></td>
                <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= url('admin/boards.php?course_id=' . $c['id']) ?>"><i class="bi bi-easel2"></i> กระดานของวิชา</a></td></tr>
        <?php endforeach; ?>
        <?php if (!$courses): ?><tr><td colspan="4" class="text-center text-muted py-5">ยังไม่มีรายวิชาที่คุณสอน</td></tr><?php endif; ?>
        </tbody>
    </table></div></div>
    <?php require __DIR__ . '/_bottom.php';
    exit;
}
$course = db_one('SELECT * FROM courses WHERE id = ?', [$courseId]);
if (!$course || !can_manage_course($courseId)) {
    http_response_code(403);
    exit('ไม่มีสิทธิ์จัดการรายวิชานี้');
}
$self = 'admin/boards.php?course_id=' . $courseId;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $board = db_one('SELECT * FROM boards WHERE id = ? AND course_id = ?', [(int)post('id'), $courseId]);
    if (!$board) { flash('danger', 'ไม่พบกระดาน'); redirect($self); }
    $action = post('action');
    if ($action === 'delete') {
        $files = board_files_where('b.id = ?', [(int)$board['id']]);
        db_exec("DELETE FROM grade_items WHERE course_id = ? AND source_key = ?", [$courseId, 'board:' . $board['id'] . ':0']);
        db_exec('DELETE FROM xp_events WHERE board_id = ?', [(int)$board['id']]);
        db_exec('DELETE FROM boards WHERE id = ?', [(int)$board['id']]);
        foreach ($files as $path) delete_upload($path);
        flash('success', 'ลบกระดาน “' . $board['title'] . '” แล้ว');
    } elseif ($action === 'copy') {
        $conn->begin_transaction();
        $cols = ['course_id', 'lesson_id', 'description', 'layout', 'template', 'opens_at', 'due_at', 'closes_at', 'accept_late', 'require_approval', 'hide_until_reveal', 'allow_comments', 'reaction_mode', 'student_role', 'group_mode', 'team_visibility', 'is_assignment', 'max_points', 'one_answer'];
        $newId = db_exec('INSERT INTO boards (title,created_by,' . implode(',', $cols) . ') VALUES (?,?' . str_repeat(',?', count($cols)) . ')',
            array_merge([mb_substr('สำเนา ' . $board['title'], 0, 200), (int)$me['id']], array_map(fn($c) => $board[$c], $cols)));
        db_exec('INSERT INTO board_sections (board_id,title,sort_order) SELECT ?, title, sort_order FROM board_sections WHERE board_id = ?', [$newId, (int)$board['id']]);
        db_exec('INSERT INTO board_teams (board_id,name,sort_order) SELECT ?, name, sort_order FROM board_teams WHERE board_id = ?', [$newId, (int)$board['id']]);
        $conn->commit();
        flash('success', 'คัดลอกกระดานแล้ว (ไม่รวมโพสต์ และสมาชิกทีม) แก้ไขการตั้งค่าได้เลย');
        redirect('admin/board_form.php?id=' . $newId);
    } elseif (in_array($action, ['open', 'closed', 'archived'], true)) {
        db_exec('UPDATE boards SET status = ?, rev = rev + 1 WHERE id = ?', [$action, (int)$board['id']]);
        board_log((int)$board['id'], (int)$me['id'], null, 'status', $action);
        flash('success', 'เปลี่ยนสถานะกระดานแล้ว');
    }
    redirect($self);
}
$boards = db_all("SELECT b.*, l.title AS lesson_title,
        (SELECT COUNT(*) FROM board_posts p WHERE p.board_id = b.id AND p.deleted_at IS NULL) AS posts,
        (SELECT COUNT(DISTINCT p.user_id) FROM board_posts p WHERE p.board_id = b.id AND p.deleted_at IS NULL AND p.status <> 'rejected') AS senders,
        (SELECT COUNT(*) FROM board_posts p WHERE p.board_id = b.id AND p.deleted_at IS NULL AND p.status = 'pending') AS pending
    FROM boards b LEFT JOIN lessons l ON l.id = b.lesson_id WHERE b.course_id = ? ORDER BY (b.status = 'archived'), b.created_at DESC", [$courseId]);
$students = count(grade_roster($courseId));
$statusLabel = ['open' => ['เปิด', 'success'], 'closed' => ['ปิดรับ', 'secondary'], 'archived' => ['เก็บถาวร', 'dark']];
$pageTitle = 'กระดานกิจกรรม — ' . $course['code'] . ' ' . $course['title'];
require __DIR__ . '/_top.php';
?>
<div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
    <a href="<?= url('admin/lessons.php?course_id=' . $courseId) ?>"><i class="bi bi-arrow-left"></i> บทเรียนของวิชา</a>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-success" href="<?= url('leaderboard.php?course_id=' . $courseId) ?>" target="_blank"><i class="bi bi-trophy"></i> Level ผู้เรียน</a>
        <a class="btn btn-vt" href="<?= url('admin/board_form.php?course_id=' . $courseId) ?>"><i class="bi bi-plus-lg"></i> สร้างกระดาน</a>
    </div>
</div>
<div class="row g-3">
    <?php foreach ($boards as $b): [$label, $icon] = BOARD_LAYOUTS[$b['layout']] ?? ['Wall', 'bi-bricks']; $window = board_window($b); ?>
        <div class="col-md-6 col-xl-4">
            <div class="card border-0 shadow-sm rounded-4 h-100 <?= $b['status'] === 'archived' ? 'opacity-75' : '' ?>">
                <div class="card-body">
                    <div class="d-flex justify-content-between gap-2 mb-1">
                        <span class="badge bg-<?= $statusLabel[$b['status']][1] ?>"><?= $statusLabel[$b['status']][0] ?><?= $b['status'] === 'open' && $window !== 'open' ? ' · ' . ['late' => 'เลยกำหนด', 'upcoming' => 'ยังไม่ถึงเวลา', 'closed' => 'หมดเวลา'][$window] : '' ?></span>
                        <span class="small text-muted"><i class="bi <?= $icon ?>"></i> <?= e($label) ?></span>
                    </div>
                    <h2 class="h6 mb-1"><a href="<?= url('board.php?id=' . $b['id']) ?>" target="_blank"><?= e($b['title']) ?></a></h2>
                    <div class="small text-muted mb-2"><?= $b['lesson_title'] ? '<i class="bi bi-journal"></i> ' . e($b['lesson_title']) : 'ไม่ผูกบทเรียน' ?><?= board_window_label($b) ? ' · ' . e(board_window_label($b)) : '' ?></div>
                    <div class="d-flex flex-wrap gap-2 small mb-2">
                        <span class="badge bg-light text-dark border"><?= (int)$b['posts'] ?> โพสต์</span>
                        <?php if ($b['is_assignment']): ?><span class="badge bg-primary-subtle text-primary">ใบงาน · ส่งแล้ว <?= (int)$b['senders'] ?>/<?= $students ?> คน · <?= e($b['max_points']) ?> คะแนน</span><?php endif; ?>
                        <?php if ($b['pending']): ?><span class="badge bg-warning text-dark">รออนุมัติ <?= (int)$b['pending'] ?></span><?php endif; ?>
                        <?php if ($b['hide_until_reveal']): ?><span class="badge bg-info-subtle text-info-emphasis"><?= $b['revealed_at'] ? 'เปิดเฉลยแล้ว' : 'ซ่อนคำตอบเพื่อน' ?></span><?php endif; ?>
                    </div>
                </div>
                <div class="card-footer bg-white border-0 d-flex flex-wrap gap-1 pb-3">
                    <a class="btn btn-sm btn-vt" href="<?= url('board.php?id=' . $b['id']) ?>" target="_blank"><i class="bi bi-easel2"></i> เปิดกระดาน</a>
                    <a class="btn btn-sm btn-outline-success" href="<?= url('admin/board_report.php?id=' . $b['id']) ?>"><i class="bi bi-clipboard-check"></i> ตรวจ/รายงาน</a>
                    <a class="btn btn-sm btn-outline-secondary" href="<?= url('admin/board_form.php?id=' . $b['id']) ?>" aria-label="ตั้งค่า"><i class="bi bi-sliders"></i></a>
                    <div class="dropdown">
                        <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="dropdown" aria-label="เพิ่มเติม"><i class="bi bi-three-dots"></i></button>
                        <div class="dropdown-menu dropdown-menu-end">
                            <?php foreach (['open' => 'เปิดรับคำตอบ', 'closed' => 'ปิดรับคำตอบ', 'archived' => 'เก็บถาวร', 'copy' => 'คัดลอกกระดาน'] as $act => $text): if ($act === $b['status']) continue; ?>
                                <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$b['id'] ?>"><button class="dropdown-item" name="action" value="<?= $act ?>"><?= $text ?></button></form>
                            <?php endforeach; ?>
                            <div class="dropdown-divider"></div>
                            <form method="post" data-confirm="ลบกระดาน “<?= e($b['title']) ?>” พร้อมโพสต์ ไฟล์ผลงาน ความคิดเห็น คะแนน และดาวที่ได้จากกระดานนี้? กู้คืนไม่ได้ (แนะนำ “เก็บถาวร” แทน)"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$b['id'] ?>"><button class="dropdown-item text-danger" name="action" value="delete">ลบกระดาน</button></form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
    <?php if (!$boards): ?>
        <div class="col-12"><div class="text-center text-muted py-5 bg-white rounded-4 shadow-sm"><i class="bi bi-easel2 fs-1"></i><p class="mt-2">ยังไม่มีกระดานในรายวิชานี้</p>
            <a class="btn btn-vt" href="<?= url('admin/board_form.php?course_id=' . $courseId) ?>"><i class="bi bi-plus-lg"></i> สร้างกระดานแรก</a></div></div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/_bottom.php'; ?>
