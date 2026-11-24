<?php
require_once __DIR__ . '/../config/api.php';

function nilai_huruf(float $nilai): array
{
    if ($nilai >= 80) return ['A', 4.0];
    if ($nilai >= 70) return ['B', 3.0];
    if ($nilai >= 60) return ['C', 2.0];
    if ($nilai >= 50) return ['D', 1.0];
    return ['E', 0.0];
}

api_run(function () use ($conn): void {
    $user = api_role(['admin', 'dosen']);
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
        $selected = $_GET['id_kelas'] ?? '';
        if (!is_string($selected)) throw new ApiException('Parameter kelas tidak valid.');
        $selected = trim($selected);
        if ($user['role'] === 'dosen') {
            $stmt = $conn->prepare(
                'SELECT c.id_kelas,c.kode_mk,mk.nama_mk FROM kelas c
                 JOIN mata_kuliah mk ON mk.kode_mk=c.kode_mk WHERE c.nidn=? ORDER BY c.id_kelas'
            );
            $stmt->bind_param('s', $user['user_id']);
        } else {
            $stmt = $conn->prepare(
                'SELECT c.id_kelas,c.kode_mk,mk.nama_mk FROM kelas c
                 JOIN mata_kuliah mk ON mk.kode_mk=c.kode_mk ORDER BY c.id_kelas'
            );
        }
        $stmt->execute();
        $classes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        if ($selected === '' && $classes) $selected = $classes[0]['id_kelas'];
        $stmt = $conn->prepare(
            "SELECT k.id_krs,m.nim,m.nama_mhs,n.nilai_tugas,n.nilai_uts,n.nilai_uas,n.nilai_akhir,n.nilai_huruf,n.bobot
             FROM krs k JOIN mahasiswa m ON m.nim=k.nim
             LEFT JOIN nilai n ON n.id_krs=k.id_krs
             WHERE k.id_kelas=? AND k.status='Disetujui' ORDER BY m.nim"
        );
        $stmt->bind_param('s', $selected);
        $stmt->execute();
        api_ok(['classes' => $classes, 'selected' => $selected, 'students' => $stmt->get_result()->fetch_all(MYSQLI_ASSOC)]);
        return;
    }

    api_method('POST');
    $data = api_json();
    api_csrf($data);
    $idKrs = api_text($data, 'id_krs', 20);
    $scores = [];
    foreach (['tugas', 'uts', 'uas'] as $field) {
        if (!isset($data[$field]) || !is_numeric($data[$field])) {
            throw new ApiException('Nilai tugas, UTS, dan UAS harus diisi angka.');
        }
        $scores[$field] = (float) $data[$field];
        if ($scores[$field] < 0 || $scores[$field] > 100) {
            throw new ApiException('Nilai harus berada pada rentang 0 sampai 100.');
        }
    }

    $stmt = $conn->prepare(
        "SELECT k.id_kelas FROM krs k JOIN kelas c ON c.id_kelas=k.id_kelas
         WHERE k.id_krs=? AND k.status='Disetujui' AND (?='admin' OR c.nidn=?)"
    );
    $stmt->bind_param('sss', $idKrs, $user['role'], $user['user_id']);
    $stmt->execute();
    if (!$stmt->get_result()->num_rows) {
        throw new ApiException('KRS tidak ditemukan, belum disetujui, atau bukan kelas yang Anda ajar.', 403);
    }

    $final = round(0.3 * $scores['tugas'] + 0.3 * $scores['uts'] + 0.4 * $scores['uas'], 2);
    [$letter, $weight] = nilai_huruf($final);
    $stmt = $conn->prepare('SELECT id_nilai FROM nilai WHERE id_krs=?');
    $stmt->bind_param('s', $idKrs);
    $stmt->execute();
    $existingGrade = $stmt->get_result()->fetch_assoc();
    $idNilai = $existingGrade['id_nilai'] ?? '';
    if ($idNilai === '') {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $idNilai = api_short_id('N');
            $stmt = $conn->prepare('SELECT 1 FROM nilai WHERE id_nilai=?');
            $stmt->bind_param('s', $idNilai);
            $stmt->execute();
            if (!$stmt->get_result()->num_rows) break;
        }
        if ($attempt === 5) throw new ApiException('Tidak dapat membuat ID nilai baru. Coba lagi.', 503);
    }
    $stmt = $conn->prepare(
        'INSERT INTO nilai (id_nilai,id_krs,nilai_tugas,nilai_uts,nilai_uas,nilai_akhir,nilai_huruf,bobot)
         VALUES (?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE nilai_tugas=VALUES(nilai_tugas),nilai_uts=VALUES(nilai_uts),
         nilai_uas=VALUES(nilai_uas),nilai_akhir=VALUES(nilai_akhir),nilai_huruf=VALUES(nilai_huruf),bobot=VALUES(bobot)'
    );
    $stmt->bind_param('ssddddsd', $idNilai, $idKrs, $scores['tugas'], $scores['uts'], $scores['uas'], $final, $letter, $weight);
    $stmt->execute();
    api_ok(['message' => 'Nilai disimpan.', 'nilai_akhir' => $final, 'nilai_huruf' => $letter, 'bobot' => $weight]);
});
