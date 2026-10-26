<?php
require_once __DIR__ . '/includes/bootstrap.php';

$q = trim($_GET['q'] ?? '');

$where = ["c.status = 'published'"];
$params = [];
if ($q !== '') {
    $where[] = '(c.title LIKE ? OR c.code LIKE ? OR c.description LIKE ?)';
    array_push($params, "%$q%", "%$q%", "%$q%");
}
$courses = db_all(course_list_sql() . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY c.code', $params);

$pageTitle = 'รายวิชาทั้งหมด';
require __DIR__ . '/includes/header.php';
?>
<div class="container py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <h3 class="mb-0">รายวิชาทั้งหมด <span class="text-muted fs-6">(<?= count($courses) ?>)</span></h3>
        <form class="d-flex gap-2">
            <input name="q" class="form-control" placeholder="ค้นหา..." value="<?= e($q) ?>">
            <button class="btn btn-vt"><i class="bi bi-search"></i></button>
        </form>
    </div>
    <div class="row g-4">
        <?php foreach ($courses as $c) include __DIR__ . '/includes/course_card.php'; ?>
        <?php if (!$courses): ?><div class="col-12 text-center text-muted py-5"><i class="bi bi-search fs-1 d-block"></i>ไม่พบรายวิชา</div><?php endif; ?>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
