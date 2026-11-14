<?php
// การ์ดกระดานกิจกรรม (ใช้ในหน้าบทเรียนและหน้ารายวิชา) ต้องกำหนด $boardList, $me ก่อน include
if (!isset($boardList, $me) || !$boardList) return;
$sentBoards = $me['role'] === 'student' ? array_flip(array_map('intval', array_column(db_all(
    "SELECT DISTINCT board_id FROM board_posts WHERE user_id = ? AND deleted_at IS NULL AND status <> 'rejected' AND board_id IN (" . implode(',', array_map(fn($b) => (int)$b['id'], $boardList)) . ')', [(int)$me['id']]), 'board_id'))) : [];
?>
<div class="row g-2">
    <?php foreach ($boardList as $b): [$label, $icon] = BOARD_LAYOUTS[$b['layout']] ?? ['Wall', 'bi-bricks']; $window = board_window($b); $sent = isset($sentBoards[(int)$b['id']]); ?>
        <div class="col-md-6">
            <a class="card h-100 border-0 shadow-sm rounded-4 text-decoration-none text-body board-link" href="<?= url('board.php?id=' . $b['id']) ?>">
                <div class="card-body d-flex gap-3 align-items-start">
                    <span class="fs-3 text-primary"><i class="bi <?= $icon ?>"></i></span>
                    <span class="flex-grow-1">
                        <span class="fw-semibold d-block"><?= e($b['title']) ?></span>
                        <span class="small text-muted d-block"><?= $b['is_assignment'] ? 'ใบงาน ' . e($b['max_points']) . ' คะแนน · ' : '' ?><?= e(board_window_label($b) ?: $label) ?></span>
                        <span class="d-flex flex-wrap gap-1 mt-1">
                            <span class="badge bg-<?= ['open' => 'success', 'late' => 'warning text-dark', 'upcoming' => 'secondary', 'closed' => 'dark'][$window] ?>"><?= ['open' => 'เปิดรับคำตอบ', 'late' => 'เลยกำหนด (ยังส่งได้)', 'upcoming' => 'ยังไม่เปิด', 'closed' => 'ปิดรับแล้ว'][$window] ?></span>
                            <?php if ($me['role'] === 'student'): ?><span class="badge <?= $sent ? 'bg-primary' : 'bg-light text-dark border' ?>"><?= $sent ? 'ส่งแล้ว' : 'ยังไม่ได้ส่ง' ?></span><?php endif; ?>
                        </span>
                    </span>
                    <i class="bi bi-chevron-right text-muted"></i>
                </div>
            </a>
        </div>
    <?php endforeach; ?>
</div>
