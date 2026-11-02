<?php
require_once __DIR__ . '/attendance.php';

function attendance_report_range($from, $until): array
{
    $from = attendance_date($from); $until = attendance_date($until);
    if ($until < $from || (new DateTimeImmutable($from))->diff(new DateTimeImmutable($until))->days > 730) throw new RuntimeException('ช่วงวันที่ต้องเรียงจากวันเริ่มถึงวันสิ้นสุด และไม่เกิน 2 ปี');
    return [$from,$until];
}

/** Count recorded student-days; an unmarked date is never an inferred absence. */
function attendance_report(int $courseId, int $groupId, string $from, string $until): array
{
    attendance_report_range($from,$until);
    $empty = array_fill_keys(array_keys(attendance_statuses(true)), 0);
    $students = [];
    foreach (grade_roster($courseId,$groupId) as $student) $students[(int)$student['id']] = $student + ['counts'=>$empty,'recorded'=>0,'concerns'=>0];
    $totals = $empty; $daily = []; $affected = [];
    $result = db_query('SELECT user_id,attendance_date,status FROM attendance_records WHERE course_id=? AND attendance_date BETWEEN ? AND ? ORDER BY attendance_date,user_id', [$courseId,$from,$until])->get_result();
    while ($record = $result->fetch_assoc()) {
        $uid = (int)$record['user_id']; $status = $record['status']; $day = $record['attendance_date'];
        if (!isset($students[$uid]) || !array_key_exists($status,$empty)) continue;
        $totals[$status]++; $students[$uid]['counts'][$status]++; $students[$uid]['recorded']++;
        if ($status !== 'present') { $affected[$uid] = true; $students[$uid]['concerns']++; }
        $daily[$day] ??= $empty;
        $daily[$day][$status]++;
    }
    $result->free();
    $students = array_values($students);
    usort($students, fn($a,$b) => ($b['counts']['absent'] <=> $a['counts']['absent']) ?: ($b['counts']['late'] <=> $a['counts']['late']) ?: strcmp($a['student_code'] ?? '',$b['student_code'] ?? '') ?: strcmp($a['fullname'],$b['fullname']));
    $recorded = array_sum($totals);
    return ['totals'=>$totals,'daily'=>$daily,'students'=>$students,'student_count'=>count($students),'recorded'=>$recorded,
        'affected'=>count($affected),'marked_students'=>count(array_filter($students,fn($s) => $s['recorded'] > 0)),
        'leave_total'=>$totals['leave']+$totals['sick_leave']+$totals['personal_leave'],
        'attendance_rate'=>$recorded ? round(100*($totals['present']+$totals['late'])/$recorded,1) : null];
}

function attendance_report_text(array $course, string $group, string $from, string $until, array $report): string
{
    // Deliberately share aggregate counts without student names, notes, or a private dashboard URL.
    $lines = ['สรุปการเข้าเรียน', $course['code'].' '.$course['title'], 'กลุ่ม: '.$group,
        'วันที่: '.thai_date($from).($until !== $from ? ' – '.thai_date($until) : ''),
        'ผู้เรียนปัจจุบัน '.$report['student_count'].' คน · มีบันทึก '.$report['marked_students'].' คน',
        'รายการที่บันทึก '.$report['recorded'].' ครั้ง (คน-วัน)'];
    foreach (attendance_statuses(true) as $key=>$label) {
        if ($key === 'leave' && !$report['totals'][$key]) continue;
        $lines[] = $label.': '.$report['totals'][$key].' ครั้ง';
    }
    $lines[] = 'รวมการลา: '.$report['leave_total'].' ครั้ง';
    $lines[] = 'นับเฉพาะข้อมูลที่บันทึกแล้ว วันยังไม่เช็คชื่อไม่นับเป็นขาด';
    return implode("\n",$lines);
}
