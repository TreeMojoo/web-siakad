<?php
require_once __DIR__ . '/../config/api.php';

api_run(function () use ($conn): void {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? '');
    if ($method === 'GET') {
        $user = api_user();
        if ($user['role'] !== 'mhs') {
            throw new ApiException('KRS mandiri hanya tersedia untuk mahasiswa.', 403);
        }
        $term = api_current_term($conn);
        if (!$term) {
             api_ok(['classes' => [], 'mine' => [], 'max_sks' => 24, 'current_term' => null]);
             return;
        }
        $stmt = $conn->prepare(
             "SELECT c.id_kelas,c.kode_mk,mk.nama_mk,mk.sks,c.nidn,d.nama_dosen,
                     c.tahun_ajaran,c.semester,c.hari,c.jam_mulai,c.ruang,c.kuota,
                     COALESCE(SUM(k.status='Disetujui'),0) AS terisi
              FROM kelas c JOIN mata_kuliah mk ON mk.kode_mk=c.kode_mk
              JOIN dosen d ON d.nidn=c.nidn
              LEFT JOIN krs k ON k.id_kelas=c.id_kelas
              WHERE c.tahun_ajaran=? AND c.semester=?
              GROUP BY c.id_kelas ORDER BY c.hari,c.jam_mulai"
        );
        $stmt->bind_param('ss', $term['tahun_ajaran'], $term['semester']);
        $stmt->execute();
        $classes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt = $conn->prepare(
             'SELECT k.id_krs,k.id_kelas,k.tgl_krs,k.status,c.kode_mk,mk.nama_mk,mk.sks,
                     c.tahun_ajaran,c.semester,c.hari,c.jam_mulai,c.ruang
             FROM krs k JOIN kelas c ON c.id_kelas=k.id_kelas
              JOIN mata_kuliah mk ON mk.kode_mk=c.kode_mk
              WHERE k.nim=? AND c.tahun_ajaran=? AND c.semester=?
              ORDER BY k.tgl_krs DESC,k.id_krs'
        );
        $stmt->bind_param('sss', $user['user_id'], $term['tahun_ajaran'], $term['semester']);
        $stmt->execute();
        api_ok([
             'classes' => $classes,
             'mine' => $stmt->get_result()->fetch_all(MYSQLI_ASSOC),
             'max_sks' => 24,
             'current_term' => $term['tahun_ajaran'] . ' ' . $term['semester'],
        ]);
        return;
    }

    api_method('POST');
    $user = api_role(['mhs']);
    $data = api_json();
    api_csrf($data);
    $action = is_string($data['action'] ?? null) ? $data['action'] : '';

    if ($action === 'cancel') {
        $id = api_text($data, 'id_krs', 20);
        $stmt = $conn->prepare('SELECT nim FROM krs WHERE id_krs=?');
        $stmt->bind_param('s', $id);
        $stmt->execute();
        $record = $stmt->get_result()->fetch_assoc();
        if (!$record || $record['nim'] !== $user['user_id']) {
            throw new ApiException('KRS tidak ditemukan atau bukan milik Anda.', 404);
        }
        $stmt = $conn->prepare('SELECT 1 FROM nilai WHERE id_krs=?');
        $stmt->bind_param('s', $id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows) {
            throw new ApiException('KRS yang sudah memiliki nilai tidak dapat dibatalkan.');
        }
        $stmt = $conn->prepare('DELETE FROM krs WHERE id_krs=? AND nim=?');
        $stmt->bind_param('ss', $id, $user['user_id']);
        $stmt->execute();
        api_ok(['message' => 'Pengajuan KRS dibatalkan.']);
        return;
    }

    if ($action !== 'submit') {
        throw new ApiException('Aksi KRS tidak valid.');
    }
    $classId = api_text($data, 'id_kelas', 20);
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare('SELECT nim FROM mahasiswa WHERE nim=? FOR UPDATE');
        $stmt->bind_param('s', $user['user_id']);
        $stmt->execute();
        if (!$stmt->get_result()->num_rows) {
            throw new ApiException('Akun tidak terhubung dengan data mahasiswa.', 403);
        }
        $stmt = $conn->prepare('SELECT id_kelas,kuota,hari,jam_mulai,tahun_ajaran,semester FROM kelas WHERE id_kelas=? FOR UPDATE');
        $stmt->bind_param('s', $classId);
        $stmt->execute();
        $class = $stmt->get_result()->fetch_assoc();
        if (!$class) {
            throw new ApiException('Kelas tidak ditemukan.', 404);
        }
        $term = api_current_term($conn);
        if (!$term || $class['tahun_ajaran'] !== $term['tahun_ajaran'] || $class['semester'] !== $term['semester']) {
            throw new ApiException('Kelas bukan bagian dari periode KRS aktif.');
        }

        $stmt = $conn->prepare("SELECT COUNT(*) AS n FROM krs WHERE id_kelas=? AND status='Disetujui'");
        $stmt->bind_param('s', $classId);
        $stmt->execute();
        if ((int) $stmt->get_result()->fetch_assoc()['n'] >= (int) $class['kuota']) {
            throw new ApiException('Kuota kelas sudah penuh.');
        }

        $stmt = $conn->prepare('SELECT id_krs,status FROM krs WHERE nim=? AND id_kelas=?');
        $stmt->bind_param('ss', $user['user_id'], $classId);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        if ($existing && $existing['status'] !== 'Ditolak') {
            throw new ApiException('Kelas ini sudah ada dalam KRS Anda.');
        }

        $stmt = $conn->prepare("SELECT COALESCE(SUM(mk.sks),0) AS sks FROM krs k JOIN kelas c ON c.id_kelas=k.id_kelas JOIN mata_kuliah mk ON mk.kode_mk=c.kode_mk WHERE k.nim=? AND k.status<>'Ditolak' AND c.tahun_ajaran=? AND c.semester=?");
        $stmt->bind_param('sss', $user['user_id'], $term['tahun_ajaran'], $term['semester']);
        $stmt->execute();
        $currentSks = (int) $stmt->get_result()->fetch_assoc()['sks'];
        $stmt = $conn->prepare('SELECT mk.sks FROM kelas c JOIN mata_kuliah mk ON mk.kode_mk=c.kode_mk WHERE c.id_kelas=?');
        $stmt->bind_param('s', $classId);
        $stmt->execute();
        $classSks = (int) $stmt->get_result()->fetch_assoc()['sks'];
        if ($currentSks + $classSks > 24) {
            throw new ApiException('Total pengajuan melebihi batas 24 SKS.');
        }

        $stmt = $conn->prepare("SELECT c.id_kelas,c.jam_mulai FROM krs k JOIN kelas c ON c.id_kelas=k.id_kelas WHERE k.nim=? AND k.status<>'Ditolak' AND c.hari=? AND c.tahun_ajaran=? AND c.semester=?");
        $stmt->bind_param('ssss', $user['user_id'], $class['hari'], $term['tahun_ajaran'], $term['semester']);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $scheduled) {
            if ($class['jam_mulai'] === $scheduled['jam_mulai']) {
                throw new ApiException('Jadwal bentrok dengan kelas ' . $scheduled['id_kelas'] . '.');
            }
        }

        $today = date('Y-m-d');
        if ($existing) {
            $submittedId = $existing['id_krs'];
            $stmt = $conn->prepare("UPDATE krs SET status='Menunggu',tgl_krs=? WHERE id_krs=?");
            $stmt->bind_param('ss', $today, $submittedId);
        } else {
            for ($attempt = 0; $attempt < 5; $attempt++) {
                $submittedId = api_short_id('K');
                $stmt = $conn->prepare('SELECT 1 FROM krs WHERE id_krs=?');
                $stmt->bind_param('s', $submittedId);
                $stmt->execute();
                if (!$stmt->get_result()->num_rows) break;
            }
            if ($attempt === 5) throw new ApiException('Tidak dapat membuat ID KRS baru. Coba lagi.', 503);
            $stmt = $conn->prepare("INSERT INTO krs (id_krs,nim,id_kelas,tgl_krs,status) VALUES (?,?,?,?,'Menunggu')");
            $stmt->bind_param('ssss', $submittedId, $user['user_id'], $classId, $today);
        }
        $stmt->execute();
        $conn->commit();
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
    api_ok(['message' => 'KRS diajukan dan menunggu persetujuan dosen wali.', 'id_krs' => $submittedId], 201);
});
