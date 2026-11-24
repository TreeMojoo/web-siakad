<?php
require_once __DIR__ . '/../config/api.php';

api_run(function () use ($conn): void {
    $user = api_role(['dosen']);
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? '');
    if ($method === 'GET') {
        $stmt = $conn->prepare(
            "SELECT k.id_krs,k.tgl_krs,k.status,m.nim,m.nama_mhs,m.angkatan,
                    c.id_kelas,c.kode_mk,mk.nama_mk,mk.sks,c.tahun_ajaran,c.semester,c.hari,c.jam_mulai,c.ruang
             FROM krs k JOIN mahasiswa m ON m.nim=k.nim
             JOIN kelas c ON c.id_kelas=k.id_kelas
             JOIN mata_kuliah mk ON mk.kode_mk=c.kode_mk
             WHERE m.nidn_wali=? AND k.status='Menunggu'
             ORDER BY k.tgl_krs,m.nim"
        );
        $stmt->bind_param('s', $user['user_id']);
        $stmt->execute();
        api_ok(['pending' => $stmt->get_result()->fetch_all(MYSQLI_ASSOC)]);
        return;
    }

    api_method('POST');
    $data = api_json();
    api_csrf($data);
    $id = api_text($data, 'id_krs', 20);
    $decision = (string) ($data['decision'] ?? '');
    if (!in_array($decision, ['Disetujui', 'Ditolak'], true)) {
        throw new ApiException('Pilihan persetujuan tidak valid.');
    }

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare(
            'SELECT k.id_kelas,k.nim,k.status,m.nidn_wali,c.kuota
             FROM krs k JOIN mahasiswa m ON m.nim=k.nim
             JOIN kelas c ON c.id_kelas=k.id_kelas WHERE k.id_krs=? FOR UPDATE'
        );
        $stmt->bind_param('s', $id);
        $stmt->execute();
        $record = $stmt->get_result()->fetch_assoc();
        if (!$record || $record['nidn_wali'] !== $user['user_id']) {
            throw new ApiException('KRS tidak ditemukan atau bukan mahasiswa bimbingan Anda.', 404);
        }
        if ($record['status'] !== 'Menunggu') {
            throw new ApiException('Pengajuan KRS sudah diproses.');
        }
        if ($decision === 'Disetujui') {
            $stmt = $conn->prepare('SELECT id_kelas FROM kelas WHERE id_kelas=? FOR UPDATE');
            $stmt->bind_param('s', $record['id_kelas']);
            $stmt->execute();
            if (!$stmt->get_result()->fetch_assoc()) {
                throw new ApiException('Kelas tidak ditemukan.', 404);
            }
            $stmt = $conn->prepare("SELECT COUNT(*) AS n FROM krs WHERE id_kelas=? AND status='Disetujui'");
            $stmt->bind_param('s', $record['id_kelas']);
            $stmt->execute();
            if ((int) $stmt->get_result()->fetch_assoc()['n'] >= (int) $record['kuota']) {
                throw new ApiException('Kuota kelas penuh; pengajuan tidak dapat disetujui.');
            }
        }
        $stmt = $conn->prepare('UPDATE krs SET status=? WHERE id_krs=? AND status=\'Menunggu\'');
        $stmt->bind_param('ss', $decision, $id);
        $stmt->execute();
        $conn->commit();
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
    api_ok(['message' => 'KRS ' . strtolower($decision) . '.']);
});
