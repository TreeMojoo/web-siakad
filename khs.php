<?php
require_once __DIR__ . '/../config/api.php';

api_run(function () use ($conn): void {
    api_method('GET');
    $user = api_user();
    $requestedNim = $_GET['nim'] ?? '';
    if (!is_string($requestedNim)) throw new ApiException('Parameter NIM tidak valid.');
    $nim = $user['role'] === 'mhs' ? $user['user_id'] : trim($requestedNim);
    if ($nim === '' && $user['role'] !== 'mhs') {
        if ($user['role'] === 'admin') {
            $students = $conn->query('SELECT nim,nama_mhs FROM mahasiswa ORDER BY nim')->fetch_all(MYSQLI_ASSOC);
        } else {
            $stmt = $conn->prepare('SELECT nim,nama_mhs FROM mahasiswa WHERE nidn_wali=? ORDER BY nim');
            $stmt->bind_param('s', $user['user_id']);
            $stmt->execute();
            $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }
        api_ok(['students' => $students]);
        return;
    }
    api_student_access($conn, $user, $nim);

    $stmt = $conn->prepare(
        'SELECT m.nim,m.nama_mhs,m.angkatan,p.nama_prodi,d.nama_dosen
         FROM mahasiswa m JOIN prodi p ON p.id_prodi=m.id_prodi
         JOIN dosen d ON d.nidn=m.nidn_wali WHERE m.nim=?'
    );
    $stmt->bind_param('s', $nim);
    $stmt->execute();
    $student = $stmt->get_result()->fetch_assoc();
    if (!$student) throw new ApiException('Mahasiswa tidak ditemukan.', 404);

    $stmt = $conn->prepare(
        "SELECT c.tahun_ajaran,c.semester,c.kode_mk,mk.nama_mk,mk.sks,k.id_krs,
                n.nilai_tugas,n.nilai_uts,n.nilai_uas,n.nilai_akhir,n.nilai_huruf,n.bobot
         FROM krs k JOIN kelas c ON c.id_kelas=k.id_kelas
         JOIN mata_kuliah mk ON mk.kode_mk=c.kode_mk
         JOIN nilai n ON n.id_krs=k.id_krs
         WHERE k.nim=? AND k.status='Disetujui'
         ORDER BY c.tahun_ajaran DESC,
         CASE WHEN c.semester='Genap' THEN 2 WHEN c.semester='Ganjil' THEN 1 ELSE 0 END DESC,c.kode_mk"
    );
    $stmt->bind_param('s', $nim);
    $stmt->execute();
    $allCourses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $terms = [];
    foreach ($allCourses as $course) {
        $term = $course['tahun_ajaran'] . ' ' . $course['semester'];
        if (!in_array($term, $terms, true)) $terms[] = $term;
    }
    $selectedTerm = $_GET['term'] ?? '';
    if (!is_string($selectedTerm)) throw new ApiException('Parameter semester tidak valid.');
    $selectedTerm = trim($selectedTerm);
    if (!in_array($selectedTerm, $terms, true)) $selectedTerm = $terms[0] ?? '';

    $cumulativeCredits = 0;
    $cumulativeQuality = 0.0;
    $termCredits = 0;
    $termQuality = 0.0;
    $courses = [];
    foreach ($allCourses as $course) {
        $course['sks'] = (int) $course['sks'];
        $course['bobot'] = (float) $course['bobot'];
        $course['nilai_akhir'] = (float) $course['nilai_akhir'];
        $course['mutu'] = $course['sks'] * $course['bobot'];
        $cumulativeCredits += $course['sks'];
        $cumulativeQuality += $course['mutu'];
        if (($course['tahun_ajaran'] . ' ' . $course['semester']) === $selectedTerm) {
            $termCredits += $course['sks'];
            $termQuality += $course['mutu'];
            $courses[] = $course;
        }
    }
    api_ok([
        'student' => $student,
        'courses' => $courses,
        'terms' => $terms,
        'selected_term' => $selectedTerm,
        'total_sks' => $termCredits,
        'ips' => $termCredits > 0 ? round($termQuality / $termCredits, 2) : 0,
        'cumulative_sks' => $cumulativeCredits,
        'ipk' => $cumulativeCredits > 0 ? round($cumulativeQuality / $cumulativeCredits, 2) : 0,
    ]);
});
